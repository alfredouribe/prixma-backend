<?php

namespace App\Policies;

use App\Models\Admin;

class AdminPolicy
{
    /**
     * Solo `superadmin` puede ver/gestionar cuentas de staff.
     * Un `admin` normal no ve el listado de AdminResource.
     */
    public function viewAny(Admin $admin): bool
    {
        return $admin->role === 'superadmin';
    }

    public function view(Admin $admin, Admin $model): bool
    {
        return $admin->role === 'superadmin';
    }

    public function create(Admin $admin): bool
    {
        return $admin->role === 'superadmin';
    }

    public function update(Admin $admin, Admin $model): bool
    {
        return $admin->role === 'superadmin';
    }

    public function delete(Admin $admin, Admin $model): bool
    {
        return $admin->role === 'superadmin';
    }
}
