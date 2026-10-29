<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\User;

/**
 * Gatea `UserResource` (panel admin, solo lectura — revisión de cuentas de
 * usuarios finales). No colisiona con ninguna policy existente: no había
 * `UserPolicy` en el proyecto antes de este cambio (confirmado por búsqueda
 * previa a crear este archivo).
 *
 * El recurso en sí (form()/getEloquentQuery()) sigue siendo de solo lectura
 * — sin create/update genérico — pero desde 2026-10-23 tiene varias
 * excepciones puntuales de mutación (togglePremium, unban, grantPackage,
 * banUser, deleteAccount, eliminar foto puntual), todas acciones de
 * Filament dedicadas y confirmadas, no un formulario de edición general.
 * Ninguna de esas acciones llama a `update()`/`delete()` de esta policy —
 * se autorizan solo por `viewAny`/`view` (llegar al recurso ya basta, mismo
 * patrón que togglePremium/unban/grantPackage desde que existen). `update`/
 * `delete` se mantienen en `false` a propósito: no hay ningún formulario de
 * edición ni un `DeleteAction` genérico sobre `User` en este panel.
 */
class UserPolicy
{
    /**
     * Tanto `admin` como `superadmin` pueden ver el listado y el detalle.
     */
    public function viewAny(Admin $admin): bool
    {
        return in_array($admin->role, ['admin', 'superadmin'], true);
    }

    public function view(Admin $admin, User $user): bool
    {
        return in_array($admin->role, ['admin', 'superadmin'], true);
    }

    /**
     * Sin acciones de mutación desde el panel — los usuarios finales solo se
     * gestionan desde la app móvil (registro, onboarding, etc.).
     */
    public function create(Admin $admin): bool
    {
        return false;
    }

    public function update(Admin $admin, User $user): bool
    {
        return false;
    }

    public function delete(Admin $admin, User $user): bool
    {
        return false;
    }
}
