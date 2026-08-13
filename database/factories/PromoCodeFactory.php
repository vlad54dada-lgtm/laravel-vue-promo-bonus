<?php

namespace Database\Factories;

use App\Models\PromoCode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PromoCode>
 */
class PromoCodeFactory extends Factory
{
    protected $model = PromoCode::class;

    public function definition(): array
    {
        return [
            'code' => Str::upper(Str::random(10)),
            'amount_cents' => fake()->numberBetween(1, 500) * 100,
            'expires_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    public function expiresAt(?\DateTimeInterface $at): static
    {
        return $this->state(fn () => ['expires_at' => $at]);
    }
}
