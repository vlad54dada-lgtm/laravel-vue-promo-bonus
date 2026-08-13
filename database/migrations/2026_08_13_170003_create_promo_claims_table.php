<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Заповнюється ТІЛЬКИ для applied/revoked; для rejected завжди NULL,
            // тому UNIQUE(user_id, promo_code_id) обмежує лише «споживаючі» записи
            // (унікальні індекси в MySQL і SQLite ігнорують NULL).
            $table->foreignId('promo_code_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('code_entered', 12);
            $table->unsignedBigInteger('amount_cents')->nullable(); // null для rejected
            $table->string('status', 16);        // applied | revoked | rejected
            $table->string('reject_reason', 32)->nullable(); // not_found | expired | already_used
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'promo_code_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_claims');
    }
};
