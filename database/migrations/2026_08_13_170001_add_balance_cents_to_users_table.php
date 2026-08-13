<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('balance_cents')->default(0);
        });

        // SQLite не підтримує ALTER TABLE ... ADD CONSTRAINT: там інваріант
        // невід'ємності тримають guard у PromoService і тести.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE users ADD CONSTRAINT chk_users_balance_non_negative CHECK (balance_cents >= 0)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT chk_users_balance_non_negative');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('balance_cents');
        });
    }
};
