<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_matching_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->tinyInteger('age_min')->unsigned()->default(18);
            $table->tinyInteger('age_max')->unsigned()->default(55);
            $table->smallInteger('max_distance_km')->unsigned()->default(50);
            $table->json('intentions')->nullable();
            $table->json('gender_identities')->nullable();
            $table->json('orientations')->nullable();
            $table->boolean('verified_only')->default(false);
            $table->boolean('has_video_only')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_matching_preferences');
    }
};
