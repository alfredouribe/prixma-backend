<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\Report;

class ReportPolicy
{
    /**
     * Tanto `admin` como `superadmin` pueden ver la cola y revisar reportes.
     */
    public function viewAny(Admin $admin): bool
    {
        return in_array($admin->role, ['admin', 'superadmin'], true);
    }

    public function view(Admin $admin, Report $report): bool
    {
        return in_array($admin->role, ['admin', 'superadmin'], true);
    }

    /**
     * Habilita las acciones de cambio de estado ("Marcar como revisado" /
     * "Marcar como resuelto"). Usada explícitamente por las Actions del
     * recurso (no una autorización inline en el Resource).
     */
    public function review(Admin $admin, Report $report): bool
    {
        return in_array($admin->role, ['admin', 'superadmin'], true);
    }

    /**
     * Los Report nunca se crean/editan/eliminan a mano desde el panel (se
     * crean desde la app móvil, solo se transiciona el estado vía review()).
     */
    public function create(Admin $admin): bool
    {
        return false;
    }

    public function update(Admin $admin, Report $report): bool
    {
        return false;
    }

    public function delete(Admin $admin, Report $report): bool
    {
        return false;
    }
}
