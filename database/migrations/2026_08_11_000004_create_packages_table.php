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
        Schema::create('packages', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('name');
            $table->text('description')->nullable()->charset('utf8mb4')->collation('utf8mb4_unicode_ci');

            // Informativo, sin cobro real todavía — ver
            // features/premium/specs/spec.md → "Catálogo de paquetes" →
            // Constraints.
            $table->decimal('price', 8, 2)->default(0);

            $table->enum('grant_type', [
                'premium_days',
                'boost_minutes',
                'see_likers_days',
                'rewind_credits',
            ]);

            // Días/minutos/créditos según grant_type.
            $table->unsignedInteger('grant_value');

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
            $table->index('grant_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
