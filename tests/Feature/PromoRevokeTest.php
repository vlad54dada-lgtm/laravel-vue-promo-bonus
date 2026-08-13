<?php

use App\Enums\PromoClaimStatus;
use App\Models\PromoClaim;
use App\Models\PromoCode;
use App\Models\User;
use App\Models\WalletTransaction;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

function claimedByApi(User $user, string $code = 'WELCOME50', int $amount = 5000): PromoClaim
{
    PromoCode::factory()->create(['code' => $code, 'amount_cents' => $amount]);
    actingAs($user, 'sanctum');
    postJson('/api/promo/claim', ['code' => $code])->assertOk();

    return PromoClaim::where('user_id', $user->id)
        ->where('status', PromoClaimStatus::Applied)
        ->latest('id')
        ->firstOrFail();
}

// R1: власний applied claim скасовується, сума знімається
it('revokes an applied claim and deducts the amount', function () {
    $user = User::factory()->create();
    $claim = claimedByApi($user);

    expect($user->refresh()->balance_cents)->toBe(5000);

    patchJson("/api/promo/{$claim->id}/revoke")
        ->assertOk()
        ->assertJsonPath('balance_cents', 0)
        ->assertJsonPath('claim.status', 'revoked');

    expect($user->refresh()->balance_cents)->toBe(0);

    $claim->refresh();
    expect($claim->status)->toBe(PromoClaimStatus::Revoked)
        ->and($claim->revoked_at)->not->toBeNull();

    // Ledger: +5000 та −5000, підсумковий інваріант — 0
    $ledger = WalletTransaction::where('user_id', $user->id)->orderBy('id')->get();
    expect($ledger)->toHaveCount(2)
        ->and($ledger[1]->amount_cents)->toBe(-5000)
        ->and($ledger[1]->balance_after_cents)->toBe(0)
        ->and((int) $ledger->sum('amount_cents'))->toBe($user->refresh()->balance_cents);
});

// R2: повторний revoke — 409, кошти НЕ списуються вдруге
it('returns 409 on a second revoke and never deducts twice', function () {
    $user = User::factory()->create();
    $claim = claimedByApi($user);

    patchJson("/api/promo/{$claim->id}/revoke")->assertOk();

    patchJson("/api/promo/{$claim->id}/revoke")
        ->assertConflict()
        ->assertJsonPath('error_code', 'CLAIM_ALREADY_REVOKED');

    expect($user->refresh()->balance_cents)->toBe(0)
        ->and(WalletTransaction::where('user_id', $user->id)->count())->toBe(2);
});

// R3: revoke rejected-запису — 409
it('returns 409 when revoking a rejected attempt', function () {
    $user = User::factory()->create();
    actingAs($user, 'sanctum');

    postJson('/api/promo/claim', ['code' => 'NOSUCHCODE1'])->assertNotFound();
    $rejected = PromoClaim::sole();

    patchJson("/api/promo/{$rejected->id}/revoke")
        ->assertConflict()
        ->assertJsonPath('error_code', 'CLAIM_NOT_REVOCABLE');
});

// R4: чужий claim — 404, існування не розкривається
it('returns 404 for another players claim', function () {
    $owner = User::factory()->create();
    $claim = claimedByApi($owner);

    $stranger = User::factory()->create();
    actingAs($stranger, 'sanctum');

    patchJson("/api/promo/{$claim->id}/revoke")
        ->assertNotFound()
        ->assertJsonPath('error_code', 'CLAIM_NOT_FOUND');

    expect($owner->refresh()->balance_cents)->toBe(5000);
});

// R5: неіснуючий claim — 404
it('returns 404 for a nonexistent claim id', function () {
    actingAs(User::factory()->create(), 'sanctum');

    patchJson('/api/promo/999999/revoke')
        ->assertNotFound()
        ->assertJsonPath('error_code', 'CLAIM_NOT_FOUND');
});

// R6 (детерміновано): умовний UPDATE не чіпає не-applied рядки —
// навіть якщо статус змінився між перевіркою і UPDATE, 0 affected rows → 409
it('conditional update guard never touches non-applied claims', function () {
    $user = User::factory()->create();
    $claim = claimedByApi($user);

    // Імітуємо конкурента, що встиг скасувати: статус вже revoked у БД
    PromoClaim::whereKey($claim->id)->update([
        'status' => PromoClaimStatus::Revoked->value,
        'revoked_at' => now(),
    ]);

    patchJson("/api/promo/{$claim->id}/revoke")
        ->assertConflict()
        ->assertJsonPath('error_code', 'CLAIM_ALREADY_REVOKED');

    // Списання не відбулося: у ledger тільки початкове нарахування
    expect(WalletTransaction::where('user_id', $user->id)->count())->toBe(1)
        ->and($user->refresh()->balance_cents)->toBe(5000);
});

// R7: без токена
it('rejects unauthenticated revoke with 401', function () {
    patchJson('/api/promo/1/revoke')->assertUnauthorized();
});

// C6: повторний claim після revoke заборонено (анти-абуз claim→revoke→claim)
it('forbids re-claiming a code after its bonus was revoked', function () {
    $user = User::factory()->create();
    $claim = claimedByApi($user);

    patchJson("/api/promo/{$claim->id}/revoke")->assertOk();

    postJson('/api/promo/claim', ['code' => 'WELCOME50'])
        ->assertConflict()
        ->assertJsonPath('error_code', 'PROMO_ALREADY_USED');

    expect($user->refresh()->balance_cents)->toBe(0);
});

// Історія показує revoked зі своїм фільтром
it('shows revoked claims in history with the revoked filter', function () {
    $user = User::factory()->create();
    $claim = claimedByApi($user);
    patchJson("/api/promo/{$claim->id}/revoke")->assertOk();

    $response = test()->getJson('/api/promo/history?status=revoked')->assertOk();

    $response->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $claim->id)
        ->assertJsonPath('data.0.status', 'revoked');
});
