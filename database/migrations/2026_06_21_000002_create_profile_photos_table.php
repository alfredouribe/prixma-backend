<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_photos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('profile_id');
            $table->string('url');
            $table->string('key');
            $table->unsignedTinyInteger('position');
            $table->timestamps();

            $table->foreign('profile_id')->references('id')->on('profiles')->onDelete('cascade');
            $table->index('profile_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_photos');
    }
};
