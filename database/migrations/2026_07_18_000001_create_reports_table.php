<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('reported_id')->constrained('users')->cascadeOnDelete();
            $table->enum('reason', ['harassment', 'discrimination', 'fake_profile', 'inappropriate_content', 'other']);
            $table->text('description')->nullable()->charset('utf8mb4')->collation('utf8mb4_unicode_ci');
            $table->enum('status', ['pending', 'reviewed', 'resolved'])->default('pending');
            $table->timestamps();

            $table->index('reporter_id');
            $table->index(['reported_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
