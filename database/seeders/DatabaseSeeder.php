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
     *
     * Свідомо без фабрик: фабрики тягнуть fake() з dev-залежності Faker,
     * якої немає в production-образі (composer --no-dev) — сідер має
     * працювати і там (демо-деплой сідить базу на кожному старті).
     */
    public function run(): void
    {
        User::create([
            'name' => 'Demo Player',
            'email' => 'player@demo.test',
            'password' => 'password', // cast 'hashed' у моделі захешує сам
        ]);

        User::create([
            'name' => 'Other Player',
            'email' => 'other@demo.test',
            'password' => 'password',
        ]);

        PromoCode::create([
            'code' => 'WELCOME50',
            'amount_cents' => 5000,
            'expires_at' => null,
        ]);

        PromoCode::create([
            'code' => 'SUMMER100',
            'amount_cents' => 10000,
            'expires_at' => now()->addDays(30),
        ]);

        PromoCode::create([
            'code' => 'EXPIRED25',
            'amount_cents' => 2500,
            'expires_at' => now()->subDay(),
        ]);
    }
}
