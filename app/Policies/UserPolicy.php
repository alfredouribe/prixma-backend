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
 * Alcance confirmado con el humano — solo lectura por ahora, sin acciones de
 * suspender/banear/editar (esas se planean en una sesión futura aparte, ver
 * features/profile/specs/plan.md → "Panel admin — gestión de usuarios").
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
