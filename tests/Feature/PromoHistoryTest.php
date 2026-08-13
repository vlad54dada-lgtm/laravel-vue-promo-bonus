<?php

use App\Enums\PromoClaimStatus;
use App\Enums\PromoRejectReason;
use App\Models\PromoClaim;
use App\Models\PromoCode;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

function makeClaim(User $user, PromoClaimStatus $status, ?PromoCode $promo = null): PromoClaim
{
    $isRejected = $status === PromoClaimStatus::Rejected;

    return PromoClaim::create([
        'user_id' => $user->id,
        'promo_code_id' => $isRejected ? null : ($promo ?? PromoCode::factory()->create())->id,
        'code_entered' => 'CODE'.fake()->unique()->numberBetween(100, 999),
        'amount_cents' => $isRejected ? null : 5000,
        'status' => $status,
        'reject_reason' => $isRejected ? PromoRejectReason::NotFound : null,
    ]);
}

// H1: всі власні записи, новіші перші, пагіновано
it('returns own history paginated, newest first', function () {
    $user = User::factory()->create();
    $first = makeClaim($user, PromoClaimStatus::Applied);
    $second = makeClaim($user, PromoClaimStatus::Rejected);

    actingAs($user, 'sanctum');

    $response = getJson('/api/promo/history')->assertOk();

    $response->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $second->id)
        ->assertJsonPath('data.1.id', $first->id)
        ->assertJsonStructure(['data', 'links', 'meta' => ['current_page', 'total', 'per_page']]);
});

// H2: фільтр за статусом
it('filters history by status', function (string $status, int $expectedCount) {
    $user = User::factory()->create();
    makeClaim($user, PromoClaimStatus::Applied);
    makeClaim($user, PromoClaimStatus::Rejected);
    makeClaim($user, PromoClaimStatus::Rejected);

    actingAs($user, 'sanctum');

    getJson("/api/promo/history?status={$status}")
        ->assertOk()
        ->assertJsonCount($expectedCount, 'data')
        ->assertJsonPath('meta.total', $expectedCount);
})->with([
    'applied' => ['applied', 1],
    'rejected' => ['rejected', 2],
    'revoked' => ['revoked', 0],
]);

// Невалідний статус — 422
it('rejects an unknown status filter with 422', function () {
    actingAs(User::factory()->create(), 'sanctum');

    getJson('/api/promo/history?status=hacked')->assertUnprocessable();
});

// H3: чужі записи ніколи не видно
it('never exposes other players history', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    makeClaim($stranger, PromoClaimStatus::Applied);

    actingAs($user, 'sanctum');

    getJson('/api/promo/history')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 0);
});

// H4: per_page обрізається до 50
it('clamps per_page to 50', function () {
    $user = User::factory()->create();
    actingAs($user, 'sanctum');

    getJson('/api/promo/history?per_page=999')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 50);
});

// Пагінація по суті: друга сторінка містить решту записів
it('paginates history', function () {
    $user = User::factory()->create();
    foreach (range(1, 3) as $i) {
        makeClaim($user, PromoClaimStatus::Rejected);
    }

    actingAs($user, 'sanctum');

    getJson('/api/promo/history?per_page=2&page=2')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.current_page', 2);
});

// Без токена
it('rejects unauthenticated history with 401', function () {
    getJson('/api/promo/history')->assertUnauthorized();
});
