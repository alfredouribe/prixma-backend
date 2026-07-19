<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Agrega 'blocked' al enum `status` de `conversations` (hoy: active,
     * pending, rejected). Ver features/safety/specs/plan.md → "Integración
     * con Chat y Matches". Laravel/Doctrine DBAL no soporta modificar
     * columnas ENUM de forma portable, por eso se usa DDL crudo aquí — es
     * definición de esquema, no lógica de negocio (constitution.md →
     * "No business logic in migrations" no prohíbe DDL).
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE conversations MODIFY COLUMN status ENUM('active', 'pending', 'rejected', 'blocked') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE conversations MODIFY COLUMN status ENUM('active', 'pending', 'rejected') NOT NULL DEFAULT 'pending'");
    }
};
