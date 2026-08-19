<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\PackageGrant;

/**
 * Mismo patrón que ReportPolicy, pero más simple — un PackageGrant es un log
 * inmutable, no hay ninguna transición de estado que un admin pueda hacer
 * sobre él (a diferencia de Report, que sí tiene `review()` porque staff
 * actúa sobre un reporte). Solo view/viewAny; create/update/delete siempre
 * false. Ver features/premium/specs/plan.md → "Historial de paquetes
 * otorgados".
 */
class PackageGrantPolicy
{
    public function viewAny(Admin $admin): bool
    {
        return in_array($admin->role, ['admin', 'superadmin'], true);
    }

    public function view(Admin $admin, PackageGrant $packageGrant): bool
    {
        return in_array($admin->role, ['admin', 'superadmin'], true);
    }

    public function create(Admin $admin): bool
    {
        return false;
    }

    public function update(Admin $admin, PackageGrant $packageGrant): bool
    {
        return false;
    }

    public function delete(Admin $admin, PackageGrant $packageGrant): bool
    {
        return false;
    }
}
