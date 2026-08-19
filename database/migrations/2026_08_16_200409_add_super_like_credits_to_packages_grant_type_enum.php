<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `grant_type` es un ENUM real de MySQL (ver create_packages_table) —
     * agregar un valor nuevo requiere ALTER TABLE, Schema::table() no tiene
     * un método nativo para modificar valores de enum. Ver
     * features/premium/specs/plan.md → "Super likes extra".
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE packages MODIFY grant_type ENUM('premium_days', 'boost_minutes', 'see_likers_days', 'rewind_credits', 'super_like_credits') NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE packages MODIFY grant_type ENUM('premium_days', 'boost_minutes', 'see_likers_days', 'rewind_credits') NOT NULL");
    }
};
