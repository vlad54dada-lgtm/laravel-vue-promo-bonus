<?php

use App\Models\User;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

it('logs in with valid credentials and returns a token with user payload', function () {
    $user = User::factory()->create(['email' => 'p1@test.dev']);

    $response = postJson('/api/auth/login', [
        'email' => 'p1@test.dev',
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'balance_cents']])
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.balance_cents', 0);
});

it('rejects invalid credentials with 422', function () {
    User::factory()->create(['email' => 'p1@test.dev']);

    postJson('/api/auth/login', [
        'email' => 'p1@test.dev',
        'password' => 'wrong-password',
    ])->assertUnprocessable();
});

it('returns the current player with balance on /api/me', function () {
    $user = User::factory()->create();
    $user->forceFill(['balance_cents' => 12345])->save();

    $token = $user->createToken('test')->plainTextToken;

    getJson('/api/me', ['Authorization' => "Bearer $token"])
        ->assertOk()
        ->assertJsonPath('id', $user->id)
        ->assertJsonPath('balance_cents', 12345);
});

it('rejects unauthenticated /api/me with 401', function () {
    getJson('/api/me')->assertUnauthorized();
});

it('revokes the token on logout so it stops working', function () {
    $user = User::factory()->create();
    $token = $user->createToken('spa')->plainTextToken;
    $headers = ['Authorization' => "Bearer $token"];

    test()->postJson('/api/auth/logout', [], $headers)->assertOk();

    expect($user->tokens()->count())->toBe(0);

    // Гвард кешує автентифікацію в межах одного тесту — скидаємо,
    // щоб наступний запит справді пройшов перевірку токена
    app('auth')->forgetGuards();

    getJson('/api/me', $headers)->assertUnauthorized();
});

it('replaces previous spa tokens on re-login instead of piling them up', function () {
    $user = User::factory()->create(['email' => 'p1@test.dev']);

    postJson('/api/auth/login', ['email' => 'p1@test.dev', 'password' => 'password'])->assertOk();
    postJson('/api/auth/login', ['email' => 'p1@test.dev', 'password' => 'password'])->assertOk();

    expect($user->tokens()->where('name', 'spa')->count())->toBe(1);
});
