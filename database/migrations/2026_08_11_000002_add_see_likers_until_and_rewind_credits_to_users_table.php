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
            // `Package.grant_type === see_likers_days` — desbloquea ver
            // quién dio like sin match hasta esta fecha. Sin feature que lo
            // consuma todavía (ver spec.md → "Catálogo de paquetes" →
            // alcance explícito), solo se registra.
            $table->timestamp('see_likers_until')->nullable()->after('premium_until');

            // `Package.grant_type === rewind_credits` — créditos consumibles
            // de "deshacer el último swipe", no basado en tiempo. Sin
            // feature que lo consuma todavía.
            $table->unsignedInteger('rewind_credits')->default(0)->after('see_likers_until');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['see_likers_until', 'rewind_credits']);
        });
    }
};
