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
        // Pivot — a quién le gustaría conocer este perfil, por identidad de
        // género. Reusa el catálogo `gender_identities` ya existente, sin
        // `custom_value` a propósito: es un selector estructurado para
        // filtrar matching, no una descripción de identidad (esa ya existe
        // aparte, en `profile_gender_identities`). Ver domain.md →
        // ProfileInterestedGender y features/matching/specs/spec.md →
        // "Compatibilidad de género".
        Schema::create('profile_interested_genders', function (Blueprint $table) {
            $table->uuid('profile_id');
            $table->uuid('gender_identity_id');

            $table->primary(['profile_id', 'gender_identity_id']);
            $table->foreign('profile_id')->references('id')->on('profiles')->onDelete('cascade');
            $table->foreign('gender_identity_id')->references('id')->on('gender_identities')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('profile_interested_genders');
    }
};
