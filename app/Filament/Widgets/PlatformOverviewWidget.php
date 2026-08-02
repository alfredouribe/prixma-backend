<?php

namespace App\Filament\Widgets;

use App\Models\EventRsvp;
use App\Models\Message;
use App\Models\Swipe;
use App\Models\User;
use App\Services\PresenceService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Ver features/admin-dashboard/specs/plan.md → "Widgets" → PlatformOverviewWidget.
 *
 * "Activos hoy" es un proxy honesto (no un DAU real rastreado) — usuarios
 * distintos con al menos un swipe, mensaje o RSVP hoy. Decisión confirmada
 * con el humano en spec.md, no inventar tracking nuevo.
 */
class PlatformOverviewWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $today = now()->toDateString();

        $activeTodayCount = Swipe::whereDate('created_at', $today)->pluck('swiper_id')
            ->merge(Message::whereDate('created_at', $today)->pluck('sender_id'))
            ->merge(EventRsvp::whereDate('created_at', $today)->pluck('user_id'))
            ->unique()
            ->count();

        return [
            Stat::make('Usuarios totales', User::count())
                ->icon('heroicon-o-users')
                ->color('primary'),

            Stat::make('En línea ahora', count(app(PresenceService::class)->onlineUserIds()))
                ->description('Conectados al canal de presencia')
                ->icon('heroicon-o-signal')
                ->color('success'),

            Stat::make('Activos hoy', $activeTodayCount)
                ->description('Swipe, mensaje o RSVP hoy')
                ->icon('heroicon-o-bolt')
                ->color('info'),

            Stat::make('Nuevos hoy', User::whereDate('created_at', $today)->count())
                ->icon('heroicon-o-user-plus')
                ->color('gray'),
        ];
    }
}
