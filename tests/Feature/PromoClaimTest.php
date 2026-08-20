<?php

use App\Enums\PromoClaimStatus;
use App\Models\PromoClaim;
use App\Models\PromoCode;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\UniqueConstraintViolationException;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\patchJson;
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

    // Сервіс-«сліпець»: обидві перевіркові locking read'и (дубль і кулдаун)
    // нічого не бачать — як конкурентна транзакція під REPEATABLE READ;
    // INSERT впаде на UNIQUE-індексі
    app()->instance(\App\Services\PromoService::class, new class extends \App\Services\PromoService
    {
        protected function hasConsumingClaim($lockedUser, $promo): bool
        {
            return false;
        }

        protected function cooldownExpiresAt($lockedUser): ?\Illuminate\Support\Carbon
        {
            return null;
        }
    });

    actingAs($user, 'sanctum');

    postJson('/api/promo/claim', ['code' => 'WELCOME50'])
        ->assertConflict()
        ->assertJsonPath('error_code', 'PROMO_ALREADY_USED');

    expect($user->refresh()->balance_cents)->toBe(5000)
        ->and(WalletTransaction::count())->toBe(0);

    // Race-гілка мапиться саме на already_used, а не на іншу причину
    $rejected = PromoClaim::where('status', PromoClaimStatus::Rejected)->sole();
    expect($rejected->reject_reason->value)->toBe('already_used');
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

    test()->travel(25)->hours(); // другий claim — за межами кулдауну D7

    postJson('/api/promo/claim', ['code' => 'SUMMER100'])->assertOk();

    $ledgerSum = (int) WalletTransaction::where('user_id', $user->id)->sum('amount_cents');

    expect($user->refresh()->balance_cents)->toBe(15000)
        ->and($ledgerSum)->toBe(15000);
});

// ── Кулдаун D7: один успішний claim на 24 години на гравця ──────────────────

// C11: інший код у межах вікна → 409 PROMO_COOLDOWN + next_claim_available_at
it('rejects a different code within the 24h cooldown window', function () {
    $user = User::factory()->create();
    PromoCode::factory()->create(['code' => 'WELCOME50', 'amount_cents' => 5000]);
    PromoCode::factory()->create(['code' => 'SUMMER100', 'amount_cents' => 10000]);

    actingAs($user, 'sanctum');

    postJson('/api/promo/claim', ['code' => 'WELCOME50'])->assertOk();
    $creditedAt = PromoClaim::sole()->created_at;

    test()->travel(23)->hours();

    postJson('/api/promo/claim', ['code' => 'SUMMER100'])
        ->assertConflict()
        ->assertJsonPath('error_code', 'PROMO_COOLDOWN')
        ->assertJsonPath('next_claim_available_at', $creditedAt->copy()->addHours(24)->toISOString());

    expect($user->refresh()->balance_cents)->toBe(5000)
        ->and(WalletTransaction::count())->toBe(1);

    $rejected = PromoClaim::where('status', PromoClaimStatus::Rejected)->sole();
    expect($rejected->reject_reason->value)->toBe('cooldown')
        ->and($rejected->promo_code_id)->toBeNull()
        ->and($rejected->code_entered)->toBe('SUMMER100');
});

// C12: рівно через 24 год вікно спливло (межа: строге >)
it('allows a different code once the 24h window has fully elapsed', function () {
    $user = User::factory()->create();
    PromoCode::factory()->create(['code' => 'WELCOME50', 'amount_cents' => 5000]);
    PromoCode::factory()->create(['code' => 'SUMMER100', 'amount_cents' => 10000]);

    actingAs($user, 'sanctum');

    // Заморозка секунди: інакше перетин секундної межі між claim і travel
    // зсуває cutoff і межа «строге >» перевіряється лише ймовірнісно
    test()->freezeSecond();

    postJson('/api/promo/claim', ['code' => 'WELCOME50'])->assertOk();

    test()->travel(24)->hours();

    postJson('/api/promo/claim', ['code' => 'SUMMER100'])->assertOk();

    expect($user->refresh()->balance_cents)->toBe(15000);
});

// C13: rejected-спроба кулдаун не запускає
it('does not start the cooldown from a rejected attempt', function () {
    $user = User::factory()->create();
    PromoCode::factory()->create(['code' => 'WELCOME50', 'amount_cents' => 5000]);

    actingAs($user, 'sanctum');

    postJson('/api/promo/claim', ['code' => 'NOSUCHCODE1'])->assertNotFound();

    postJson('/api/promo/claim', ['code' => 'WELCOME50'])->assertOk();

    expect($user->refresh()->balance_cents)->toBe(5000);
});

// C13 (продовження): відмова через кулдаун вікно не подовжує
it('does not extend the cooldown window by a rejected cooldown attempt', function () {
    $user = User::factory()->create();
    PromoCode::factory()->create(['code' => 'WELCOME50', 'amount_cents' => 5000]);
    PromoCode::factory()->create(['code' => 'SUMMER100', 'amount_cents' => 10000]);

    actingAs($user, 'sanctum');

    postJson('/api/promo/claim', ['code' => 'WELCOME50'])->assertOk();

    test()->travel(23)->hours();
    postJson('/api/promo/claim', ['code' => 'SUMMER100'])->assertConflict();

    // Вікно рахується від успішного claim (ще +2 год = 25 від нього),
    // а не від щойно відхиленої спроби (від неї минуло б лише 2 год)
    test()->travel(2)->hours();
    postJson('/api/promo/claim', ['code' => 'SUMMER100'])->assertOk();

    expect($user->refresh()->balance_cents)->toBe(15000);
});

// C14: revoke кулдаун не знімає і НЕ подовжує (якір — created_at, не revoked_at)
it('keeps the cooldown after the crediting claim was revoked', function () {
    $user = User::factory()->create();
    PromoCode::factory()->create(['code' => 'WELCOME50', 'amount_cents' => 5000]);
    PromoCode::factory()->create(['code' => 'SUMMER100', 'amount_cents' => 10000]);

    actingAs($user, 'sanctum');

    postJson('/api/promo/claim', ['code' => 'WELCOME50'])->assertOk();
    $claim = PromoClaim::sole();

    // Revoke через 23 год: якби вікно рахувалось від revoked_at,
    // next_claim_available_at посунувся б на +47 год від claim
    test()->travel(23)->hours();
    patchJson("/api/promo/{$claim->id}/revoke")->assertOk();

    postJson('/api/promo/claim', ['code' => 'SUMMER100'])
        ->assertConflict()
        ->assertJsonPath('error_code', 'PROMO_COOLDOWN')
        ->assertJsonPath('next_claim_available_at', $claim->created_at->copy()->addHours(24)->toISOString());

    expect($user->refresh()->balance_cents)->toBe(0);
});

// C15: кулдаун — на гравця з токена, не глобальний
it('scopes the cooldown to the player from the token, not globally', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    PromoCode::factory()->create(['code' => 'WELCOME50', 'amount_cents' => 5000]);
    PromoCode::factory()->create(['code' => 'SUMMER100', 'amount_cents' => 10000]);

    actingAs($first, 'sanctum');
    postJson('/api/promo/claim', ['code' => 'WELCOME50'])->assertOk();

    // Той самий момент часу: «чуже» вікно другого гравця не стосується
    actingAs($second, 'sanctum');
    postJson('/api/promo/claim', ['code' => 'SUMMER100'])->assertOk();

    expect($first->refresh()->balance_cents)->toBe(5000)
        ->and($second->refresh()->balance_cents)->toBe(10000);
});

// Вікно перезаводиться від КОЖНОГО успішного claim, а не від першого в історії
it('re-anchors the cooldown window on each successful claim', function () {
    $user = User::factory()->create();
    PromoCode::factory()->create(['code' => 'WELCOME50', 'amount_cents' => 5000]);
    PromoCode::factory()->create(['code' => 'SUMMER100', 'amount_cents' => 10000]);
    PromoCode::factory()->create(['code' => 'BONUS200XY', 'amount_cents' => 20000]);

    actingAs($user, 'sanctum');

    postJson('/api/promo/claim', ['code' => 'WELCOME50'])->assertOk();

    test()->travel(25)->hours();
    postJson('/api/promo/claim', ['code' => 'SUMMER100'])->assertOk();
    $secondCreditedAt = PromoClaim::where('code_entered', 'SUMMER100')->sole()->created_at;

    // Через 1 год після ДРУГОГО успішного: вікно активне і рахується від нього
    test()->travel(1)->hours();
    postJson('/api/promo/claim', ['code' => 'BONUS200XY'])
        ->assertConflict()
        ->assertJsonPath('error_code', 'PROMO_COOLDOWN')
        ->assertJsonPath('next_claim_available_at', $secondCreditedAt->copy()->addHours(24)->toISOString());

    expect($user->refresh()->balance_cents)->toBe(15000);
});

// Пріоритет D7: прострочений код усередині вікна → PROMO_EXPIRED, не COOLDOWN
it('prefers PROMO_EXPIRED over the cooldown inside the window', function () {
    $user = User::factory()->create();
    PromoCode::factory()->create(['code' => 'WELCOME50', 'amount_cents' => 5000]);
    PromoCode::factory()->expired()->create(['code' => 'EXPIRED25', 'amount_cents' => 2500]);

    actingAs($user, 'sanctum');

    postJson('/api/promo/claim', ['code' => 'WELCOME50'])->assertOk();
    test()->travel(1)->hours();

    postJson('/api/promo/claim', ['code' => 'EXPIRED25'])
        ->assertConflict()
        ->assertJsonPath('error_code', 'PROMO_EXPIRED');

    $rejected = PromoClaim::where('status', PromoClaimStatus::Rejected)->sole();
    expect($rejected->reject_reason->value)->toBe('expired');
});

// Пріоритет D7: неіснуючий код усередині вікна → 404, не COOLDOWN
it('prefers PROMO_NOT_FOUND over the cooldown inside the window', function () {
    $user = User::factory()->create();
    PromoCode::factory()->create(['code' => 'WELCOME50', 'amount_cents' => 5000]);

    actingAs($user, 'sanctum');

    postJson('/api/promo/claim', ['code' => 'WELCOME50'])->assertOk();
    test()->travel(1)->hours();

    postJson('/api/promo/claim', ['code' => 'NOSUCHCODE1'])
        ->assertNotFound()
        ->assertJsonPath('error_code', 'PROMO_NOT_FOUND');

    $rejected = PromoClaim::where('status', PromoClaimStatus::Rejected)->sole();
    expect($rejected->reject_reason->value)->toBe('not_found');
});

// Пріоритет: повторний той самий код у вікні → постійна причина ALREADY_USED
it('prefers PROMO_ALREADY_USED over the cooldown for a repeated code', function () {
    $user = User::factory()->create();
    PromoCode::factory()->create(['code' => 'WELCOME50', 'amount_cents' => 5000]);

    actingAs($user, 'sanctum');

    postJson('/api/promo/claim', ['code' => 'WELCOME50'])->assertOk();

    test()->travel(1)->hours(); // глибоко всередині вікна кулдауну

    postJson('/api/promo/claim', ['code' => 'WELCOME50'])
        ->assertConflict()
        ->assertJsonPath('error_code', 'PROMO_ALREADY_USED');
});

// Конфіг: cooldown_hours = 0 повністю вимикає правило
it('disables the cooldown when cooldown_hours is zero', function () {
    config(['promo.cooldown_hours' => 0]);

    $user = User::factory()->create();
    PromoCode::factory()->create(['code' => 'WELCOME50', 'amount_cents' => 5000]);
    PromoCode::factory()->create(['code' => 'SUMMER100', 'amount_cents' => 10000]);

    actingAs($user, 'sanctum');

    postJson('/api/promo/claim', ['code' => 'WELCOME50'])->assertOk();
    postJson('/api/promo/claim', ['code' => 'SUMMER100'])->assertOk();

    expect($user->refresh()->balance_cents)->toBe(15000);
});
