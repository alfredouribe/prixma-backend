<?php

namespace App\Filament\Widgets;

use App\Models\Event;
use App\Models\EventRsvp;
use App\Models\Notification;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Ver features/admin-dashboard/specs/plan.md → "Widgets" → EventsAndNotificationsWidget.
 * "Push enviados hoy": sent_at nulo no matchea whereDate, así que ya excluye
 * los envíos que no se lograron confirmar, sin filtro extra.
 */
class EventsAndNotificationsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $upcomingAttendees = EventRsvp::where('status', 'going')
            ->whereHas('event', fn ($q) => $q->where('event_date', '>=', now()))
            ->count();

        return [
            Stat::make('Próximos eventos', Event::where('event_date', '>=', now())->count())
                ->icon('heroicon-o-calendar')
                ->color('primary'),

            Stat::make('Asistentes confirmados', $upcomingAttendees)
                ->description('A próximos eventos')
                ->icon('heroicon-o-user-group')
                ->color('success'),

            Stat::make('Push enviados hoy', Notification::whereDate('sent_at', today())->count())
                ->icon('heroicon-o-bell')
                ->color('info'),
        ];
    }
}
