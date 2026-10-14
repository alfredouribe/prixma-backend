<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mientras esté activado, toda cuenta nueva recibe automáticamente
     * Prixma+ (`users.premium_until`) por 30 días al registrarse — sin
     * acción manual por usuario. Ver `PackageService::grantWelcomeTrialIfEnabled()`
     * y `AuthService::register()`. Apagar el switch después no afecta a
     * quienes ya recibieron el beneficio, solo detiene nuevos otorgamientos.
     */
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->boolean('new_account_premium_trial_enabled')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn('new_account_premium_trial_enabled');
        });
    }
};
