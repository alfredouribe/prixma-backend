<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_pronouns', function (Blueprint $table) {
            $table->uuid('profile_id');
            $table->uuid('pronoun_id');

            $table->primary(['profile_id', 'pronoun_id']);
            $table->foreign('profile_id')->references('id')->on('profiles')->onDelete('cascade');
            $table->foreign('pronoun_id')->references('id')->on('pronouns')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_pronouns');
    }
};
