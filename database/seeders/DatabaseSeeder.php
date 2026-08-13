<?php

namespace Database\Seeders;

use App\Models\PromoCode;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Демо-дані: два гравці (другий — для перевірки ізоляції даних)
     * і три промокоди, що покривають сценарії демо: валідний безстроковий,
     * валідний з майбутнім терміном, прострочений.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Demo Player',
            'email' => 'player@demo.test',
        ]);

        User::factory()->create([
            'name' => 'Other Player',
            'email' => 'other@demo.test',
        ]);

        PromoCode::factory()->create([
            'code' => 'WELCOME50',
            'amount_cents' => 5000,
            'expires_at' => null,
        ]);

        PromoCode::factory()->create([
            'code' => 'SUMMER100',
            'amount_cents' => 10000,
            'expires_at' => now()->addDays(30),
        ]);

        PromoCode::factory()->expired()->create([
            'code' => 'EXPIRED25',
            'amount_cents' => 2500,
        ]);
    }
}
