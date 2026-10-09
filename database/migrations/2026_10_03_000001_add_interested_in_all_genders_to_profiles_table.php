<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            // `true` = "Todes / sin preferencia". Mutuamente excluyente con
            // tener filas en `profile_interested_genders` (ver migración
            // create_profile_interested_genders_table). Ver domain.md →
            // Profile y features/matching/specs/spec.md → "Compatibilidad de
            // género".
            $table->boolean('interested_in_all_genders')->default(false);
        });

        // Backfill de retrocompatibilidad: las filas que ya existían ANTES de
        // esta migración deben quedar en `true` ("Todes / sin preferencia")
        // para que ningún perfil preexistente desaparezca de golpe de ninguna
        // cola de Explorar. El `default(false)` de arriba solo aplica a filas
        // insertadas DESPUÉS de este punto — todo usuario nuevo pasa por el
        // onboarding, que siempre escribe un valor explícito antes de llegar
        // a Explorar, así que ese default en la práctica nunca queda
        // "sin configurar" para cuentas nuevas.
        DB::table('profiles')->update(['interested_in_all_genders' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('interested_in_all_genders');
        });
    }
};
