<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\Package;

/**
 * Mismo patrón que EventPolicy — Package es catálogo administrado por staff
 * (CRUD completo), no un recurso de solo lectura como ReportPolicy/UserPolicy.
 * Tanto `admin` como `superadmin` pueden crear/editar/eliminar. Ver
 * features/premium/specs/plan.md → "Catálogo de paquetes" → Filament.
 */
class PackagePolicy
{
    public function viewAny(Admin $admin): bool
    {
        return in_array($admin->role, ['admin', 'superadmin'], true);
    }

    public function view(Admin $admin, Package $package): bool
    {
        return in_array($admin->role, ['admin', 'superadmin'], true);
    }

    public function create(Admin $admin): bool
    {
        return in_array($admin->role, ['admin', 'superadmin'], true);
    }

    public function update(Admin $admin, Package $package): bool
    {
        return in_array($admin->role, ['admin', 'superadmin'], true);
    }

    public function delete(Admin $admin, Package $package): bool
    {
        return in_array($admin->role, ['admin', 'superadmin'], true);
    }
}
