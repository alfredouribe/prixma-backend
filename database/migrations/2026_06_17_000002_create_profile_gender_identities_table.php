<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_gender_identities', function (Blueprint $table) {
            $table->uuid('profile_id');
            $table->uuid('identity_id');

            $table->primary(['profile_id', 'identity_id']);
            $table->foreign('profile_id')->references('id')->on('profiles')->onDelete('cascade');
            $table->foreign('identity_id')->references('id')->on('gender_identities')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_gender_identities');
    }
};
