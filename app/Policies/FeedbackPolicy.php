<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\Feedback;

/**
 * Mismo patrón que PackageGrantPolicy — Feedback es un log inmutable (bug
 * report / comentario libre enviado desde la app), nadie en el panel lo crea,
 * edita ni borra a mano. Solo view/viewAny; create/update/delete siempre
 * false. Ver CLAUDE.md (pedido nuevo, sin spec previa).
 */
class FeedbackPolicy
{
    public function viewAny(Admin $admin): bool
    {
        return in_array($admin->role, ['admin', 'superadmin'], true);
    }

    public function view(Admin $admin, Feedback $feedback): bool
    {
        return in_array($admin->role, ['admin', 'superadmin'], true);
    }

    public function create(Admin $admin): bool
    {
        return false;
    }

    public function update(Admin $admin, Feedback $feedback): bool
    {
        return false;
    }

    public function delete(Admin $admin, Feedback $feedback): bool
    {
        return false;
    }
}
