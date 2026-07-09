<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\VerificationRequest;

class VerificationRequestPolicy
{
    /**
     * Tanto `admin` como `superadmin` pueden ver la cola y revisar solicitudes.
     */
    public function viewAny(Admin $admin): bool
    {
        return in_array($admin->role, ['admin', 'superadmin'], true);
    }

    public function view(Admin $admin, VerificationRequest $verificationRequest): bool
    {
        return in_array($admin->role, ['admin', 'superadmin'], true);
    }

    /**
     * Habilita las acciones de aprobar/rechazar. Usada explícitamente por
     * las Actions del recurso (no una autorización inline en el Resource).
     */
    public function review(Admin $admin, VerificationRequest $verificationRequest): bool
    {
        return in_array($admin->role, ['admin', 'superadmin'], true);
    }

    /**
     * Las VerificationRequest nunca se crean ni editan a mano desde el panel
     * (se crean desde la app móvil, se modifican solo vía approve/reject).
     */
    public function create(Admin $admin): bool
    {
        return false;
    }

    public function update(Admin $admin, VerificationRequest $verificationRequest): bool
    {
        return false;
    }

    public function delete(Admin $admin, VerificationRequest $verificationRequest): bool
    {
        return false;
    }
}
