<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\Event;

class EventPolicy
{
    /**
     * A diferencia del resto de recursos admin del proyecto (Report, User,
     * VerificationRequest — de solo lectura o sin creación), Event sí es
     * CRUD completo: es la única entidad de negocio que el staff crea
     * directamente desde el panel (ver domain.md → Event, "Creación de
     * eventos" en spec.md). Tanto `admin` como `superadmin` pueden
     * crear/editar/eliminar — no hay una restricción más fina como la de
     * AdminPolicy (gestión de cuentas de staff, exclusiva de superadmin).
     */
    public function viewAny(Admin $admin): bool
    {
        return in_array($admin->role, ['admin', 'superadmin'], true);
    }

    public function view(Admin $admin, Event $event): bool
    {
        return in_array($admin->role, ['admin', 'superadmin'], true);
    }

    public function create(Admin $admin): bool
    {
        return in_array($admin->role, ['admin', 'superadmin'], true);
    }

    public function update(Admin $admin, Event $event): bool
    {
        return in_array($admin->role, ['admin', 'superadmin'], true);
    }

    public function delete(Admin $admin, Event $event): bool
    {
        return in_array($admin->role, ['admin', 'superadmin'], true);
    }
}
