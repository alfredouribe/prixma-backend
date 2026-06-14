<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gender_identities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug')->unique();
            $table->string('label');
        });

        Schema::create('orientations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug')->unique();
            $table->string('label');
        });

        Schema::create('pronouns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug')->unique();
            $table->string('label');
        });

        Schema::create('interests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug')->unique();
            $table->string('label');
            $table->enum('category', ['culture', 'activism', 'lifestyle', 'tech']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interests');
        Schema::dropIfExists('pronouns');
        Schema::dropIfExists('orientations');
        Schema::dropIfExists('gender_identities');
    }
};
