<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only ledger: кожна зміна балансу лишає рядок.
        // Інваріант: users.balance_cents == SUM(amount_cents) по гравцю.
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('promo_claim_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount_cents'); // signed: + нарахування, − списання
            $table->string('type', 32);         // promo_claim | promo_revoke
            $table->unsignedBigInteger('balance_after_cents');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
