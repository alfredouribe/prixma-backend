<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_sexual_orientations', function (Blueprint $table) {
            $table->uuid('profile_id');
            $table->uuid('orientation_id');

            $table->primary(['profile_id', 'orientation_id']);
            $table->foreign('profile_id')->references('id')->on('profiles')->onDelete('cascade');
            $table->foreign('orientation_id')->references('id')->on('orientations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_sexual_orientations');
    }
};
