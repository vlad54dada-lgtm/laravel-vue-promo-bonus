<?php

use App\Enums\PromoClaimStatus;
use App\Models\PromoClaim;
use App\Models\PromoCode;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\UniqueConstraintViolationException;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

// C1: валідний код, перший раз
it('credits the bonus on a valid first-time claim', function () {
    $user = User::factory()->create();
    PromoCode::factory()->create(['code' => 'WELCOME50', 'amount_cents' => 5000]);

    actingAs($user, 'sanctum');

    postJson('/api/promo/claim', ['code' => 'WELCOME50'])
        ->assertOk()
        ->assertJsonPath('bonus_amount_cents', 5000)
        ->assertJsonPath('balance_cents', 5000)
        ->assertJsonPath('claim.status', 'applied')
        ->assertJsonPath('claim.code', 'WELCOME50');

    expect($user->refresh()->balance_cents)->toBe(5000);

    $claim = PromoClaim::sole();
    expect($claim->status)->toBe(PromoClaimStatus::Applied)
        ->and($claim->amount_cents)->toBe(5000);

    $ledger = WalletTransaction::sole();
    expect($ledger->amount_cents)->toBe(5000)
        ->and($ledger->balance_after_cents)->toBe(5000)
        ->and($ledger->promo_claim_id)->toBe($claim->id);
});

// C2: невалідний формат → 422, в історію не пишеться
it('returns 422 for invalid code format and records nothing', function (array $payload) {
    $user = User::factory()->create();
    actingAs($user, 'sanctum');

    postJson('/api/promo/claim', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    expect(PromoClaim::count())->toBe(0)
        ->and($user->refresh()->balance_cents)->toBe(0);
})->with([
    'missing code' => [[]],
    'too short (5)' => [['code' => 'ABC12']],
    'too long (13)' => [['code' => 'ABCDEFGHIJKLM']],
    'non-latin characters' => [['code' => 'промо!']],
    'spaces inside' => [['code' => 'ABC 123']],
    // Трейлінг-\n обрізає глобальний TrimStrings ще до валідації,
    // а от перенос усередині коду має завалити regex (\A..\z якорі)
    'newline inside' => [['code' => "WELC\nOME50"]],
]);

// C3: неіснуючий код правильного формату
it('returns 404 for a nonexistent code and records a rejected attempt', function () {
    $user = User::factory()->create();
    actingAs($user, 'sanctum');

    postJson('/api/promo/claim', ['code' => 'NOSUCHCODE1'])
        ->assertNotFound()
        ->assertJsonPath('error_code', 'PROMO_NOT_FOUND')
        ->assertJsonStructure(['message', 'error_code']);

    $claim = PromoClaim::sole();
    expect($claim->status)->toBe(PromoClaimStatus::Rejected)
        ->and($claim->reject_reason->value)->toBe('not_found')
        ->and($claim->promo_code_id)->toBeNull()
        ->and($claim->code_entered)->toBe('NOSUCHCODE1');

    expect($user->refresh()->balance_cents)->toBe(0)
        ->and(WalletTransaction::count())->toBe(0);
});

// C4: прострочений код
it('returns 409 for an expired code and records a rejected attempt', function () {
    $user = User::factory()->create();
    PromoCode::factory()->expired()->create(['code' => 'EXPIRED25', 'amount_cents' => 2500]);

    actingAs($user, 'sanctum');

    postJson('/api/promo/claim', ['code' => 'EXPIRED25'])
        ->assertConflict()
        ->assertJsonPath('error_code', 'PROMO_EXPIRED');

    $claim = PromoClaim::sole();
    expect($claim->status)->toBe(PromoClaimStatus::Rejected)
        ->and($claim->reject_reason->value)->toBe('expired');

    expect($user->refresh()->balance_cents)->toBe(0);
});

// C5: повторний claim того самого коду
it('rejects a second claim of the same code with 409 and does not double-credit', function () {
    $user = User::factory()->create();
    PromoCode::factory()->create(['code' => 'WELCOME50', 'amount_cents' => 5000]);

    actingAs($user, 'sanctum');

    postJson('/api/promo/claim', ['code' => 'WELCOME50'])->assertOk();
    postJson('/api/promo/claim', ['code' => 'WELCOME50'])
        ->assertConflict()
        ->assertJsonPath('error_code', 'PROMO_ALREADY_USED');

    expect($user->refresh()->balance_cents)->toBe(5000)
        ->and(WalletTransaction::count())->toBe(1)
        ->and(PromoClaim::where('status', PromoClaimStatus::Applied)->count())->toBe(1)
        ->and(PromoClaim::where('status', PromoClaimStatus::Rejected)->count())->toBe(1);
});

// C7: нормалізація регістру
it('treats codes case-insensitively', function () {
    $user = User::factory()->create();
    PromoCode::factory()->create(['code' => 'WELCOME50', 'amount_cents' => 5000]);

    actingAs($user, 'sanctum');

    postJson('/api/promo/claim', ['code' => 'welcome50'])
        ->assertOk()
        ->assertJsonPath('claim.code', 'WELCOME50');

    postJson('/api/promo/claim', ['code' => 'WeLcOmE50'])
        ->assertConflict()
        ->assertJsonPath('error_code', 'PROMO_ALREADY_USED');

    expect($user->refresh()->balance_cents)->toBe(5000);
});

// C8: другий рубіж — унікальний індекс на рівні схеми
it('enforces UNIQUE(user_id, promo_code_id) at the database level', function () {
    $user = User::factory()->create();
    $promo = PromoCode::factory()->create();

    $attributes = [
        'user_id' => $user->id,
        'promo_code_id' => $promo->id,
        'code_entered' => $promo->code,
        'amount_cents' => $promo->amount_cents,
        'status' => PromoClaimStatus::Applied,
    ];

    PromoClaim::create($attributes);

    expect(fn () => PromoClaim::create($attributes))
        ->toThrow(UniqueConstraintViolationException::class);
});

// C8 (продовження): гонка, що «проскочила» locking read і дійшла до INSERT,
// мапиться через catch UniqueConstraintViolationException на контрактний 409
it('maps a unique-violation race to 409 with a rejected record via the API', function () {
    $user = User::factory()->create();
    $promo = PromoCode::factory()->create(['code' => 'WELCOME50', 'amount_cents' => 5000]);

    PromoClaim::create([
        'user_id' => $user->id,
        'promo_code_id' => $promo->id,
        'code_entered' => $promo->code,
        'amount_cents' => $promo->amount_cents,
        'status' => \App\Enums\PromoClaimStatus::Applied,
    ]);
    $user->forceFill(['balance_cents' => 5000])->save();

    // Сервіс-«сліпець»: перевірка дубля нічого не бачить — як конкурентна
    // транзакція під REPEATABLE READ; INSERT впаде на UNIQUE-індексі
    app()->instance(\App\Services\PromoService::class, new class extends \App\Services\PromoService
    {
        protected function hasConsumingClaim($lockedUser, $promo): bool
        {
            return false;
        }
    });

    actingAs($user, 'sanctum');

    postJson('/api/promo/claim', ['code' => 'WELCOME50'])
        ->assertConflict()
        ->assertJsonPath('error_code', 'PROMO_ALREADY_USED');

    expect($user->refresh()->balance_cents)->toBe(5000)
        ->and(WalletTransaction::count())->toBe(0)
        ->and(PromoClaim::where('status', PromoClaimStatus::Rejected)->count())->toBe(1);
});

// C8 (продовження): rejected-спроби не блокуються унікальним індексом
it('allows unlimited rejected attempts thanks to NULL promo_code_id', function () {
    $user = User::factory()->create();
    actingAs($user, 'sanctum');

    postJson('/api/promo/claim', ['code' => 'NOSUCHCODE1'])->assertNotFound();
    postJson('/api/promo/claim', ['code' => 'NOSUCHCODE1'])->assertNotFound();

    expect(PromoClaim::count())->toBe(2);
});

// C9: без токена
it('rejects unauthenticated claim with 401', function () {
    postJson('/api/promo/claim', ['code' => 'WELCOME50'])->assertUnauthorized();
});

// C10: код одноразовий на гравця, не глобально
it('allows different players to claim the same code', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    PromoCode::factory()->create(['code' => 'WELCOME50', 'amount_cents' => 5000]);

    actingAs($first, 'sanctum');
    postJson('/api/promo/claim', ['code' => 'WELCOME50'])->assertOk();

    actingAs($second, 'sanctum');
    postJson('/api/promo/claim', ['code' => 'WELCOME50'])->assertOk();

    expect($first->refresh()->balance_cents)->toBe(5000)
        ->and($second->refresh()->balance_cents)->toBe(5000);
});

// Інваріант ledger: баланс завжди дорівнює сумі транзакцій
it('keeps the ledger consistent with the balance', function () {
    $user = User::factory()->create();
    PromoCode::factory()->create(['code' => 'WELCOME50', 'amount_cents' => 5000]);
    PromoCode::factory()->create(['code' => 'SUMMER100', 'amount_cents' => 10000]);

    actingAs($user, 'sanctum');
    postJson('/api/promo/claim', ['code' => 'WELCOME50'])->assertOk();
    postJson('/api/promo/claim', ['code' => 'SUMMER100'])->assertOk();

    $ledgerSum = (int) WalletTransaction::where('user_id', $user->id)->sum('amount_cents');

    expect($user->refresh()->balance_cents)->toBe(15000)
        ->and($ledgerSum)->toBe(15000);
});
