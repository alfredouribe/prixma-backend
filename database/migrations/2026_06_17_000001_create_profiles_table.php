<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->unique();
            $table->string('display_name');
            $table->string('custom_gender_identity', 100)->nullable();
            $table->string('custom_orientation', 100)->nullable();
            $table->string('custom_pronouns', 100)->nullable();
            $table->string('custom_interests', 200)->nullable();
            $table->enum('intention', ['partner', 'friendship', 'community', 'mentorship'])->nullable();
            $table->text('bio')->nullable();
            $table->string('video_url')->nullable();
            $table->boolean('video_processed')->default(false);
            $table->string('photo_url')->nullable();
            $table->tinyInteger('onboarding_step')->unsigned()->default(0);
            $table->boolean('onboarding_completed')->default(false);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
