<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Premium otorgado por un paquete (`Package.grant_type ===
            // premium_days`) — expira solo, a diferencia de `is_premium`
            // (toggle manual, sin expiración). Ver
            // features/premium/specs/plan.md → "Catálogo de paquetes" y
            // `User::hasPremiumAccess()`.
            $table->timestamp('premium_until')->nullable()->after('is_premium');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('premium_until');
        });
    }
};
