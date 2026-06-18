<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_interests', function (Blueprint $table) {
            $table->uuid('profile_id');
            $table->uuid('interest_id');

            $table->primary(['profile_id', 'interest_id']);
            $table->foreign('profile_id')->references('id')->on('profiles')->onDelete('cascade');
            $table->foreign('interest_id')->references('id')->on('interests')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_interests');
    }
};
