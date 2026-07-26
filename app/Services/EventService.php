<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventRsvp;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EventService
{
    private const VALID_CATEGORIES = ['pride', 'social', 'art', 'activism'];

    /**
     * Lista eventos para la app móvil. `status: not_going` del usuario
     * autenticado oculta el evento de esta lista principal (ver
     * domain.md → EventRsvp), pero el evento sigue siendo consultable vía
     * find() (detalle).
     */
    public function list(User $user, array $filters = []): LengthAwarePaginator
    {
        $perPage = min((int) ($filters['per_page'] ?? 15), 50);
        $perPage = $perPage > 0 ? $perPage : 15;
        $page = max((int) ($filters['page'] ?? 1), 1);

        $query = Event::query()
            ->withCount([
                'rsvps as interested_count' => fn (Builder $q) => $q->where('status', 'interested'),
                'rsvps as going_count'      => fn (Builder $q) => $q->where('status', 'going'),
            ])
            ->with(['rsvps' => fn ($q) => $q->where('user_id', $user->id)])
            ->whereDoesntHave('rsvps', function (Builder $q) use ($user) {
                $q->where('user_id', $user->id)->where('status', 'not_going');
            });

        if (!empty($filters['category']) && in_array($filters['category'], self::VALID_CATEGORIES, true)) {
            $query->where('category', $filters['category']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('event_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('event_date', '<=', $filters['date_to']);
        }

        $this->applyProximityFilter($query, $filters);

        return $query->orderBy('event_date')->paginate($perPage, ['*'], 'page', $page);
    }

    public function find(User $user, string $eventId): Event
    {
        return Event::query()
            ->withCount([
                'rsvps as interested_count' => fn (Builder $q) => $q->where('status', 'interested'),
                'rsvps as going_count'      => fn (Builder $q) => $q->where('status', 'going'),
            ])
            ->with(['rsvps' => fn ($q) => $q->where('user_id', $user->id)])
            ->findOrFail($eventId);
    }

    /**
     * Confirma/cambia la asistencia del usuario a un evento. Upsert — un
     * usuario solo puede tener un estado por evento (ver domain.md →
     * EventRsvp, UNIQUE(event_id, user_id)).
     */
    public function rsvp(User $user, string $eventId, string $status): Event
    {
        $event = Event::findOrFail($eventId);

        EventRsvp::updateOrCreate(
            ['event_id' => $event->id, 'user_id' => $user->id],
            ['status' => $status]
        );

        return $this->find($user, $event->id);
    }

    /**
     * Filtro de proximidad — `ST_Distance_Sphere` de MySQL, la única
     * excepción documentada a "no raw SQL" (constitution.md → "Location &
     * Proximity" / "Forbidden Practices"). Solo se aplica si vienen los
     * tres parámetros (lat, lng, radius); eventos sin coordenadas quedan
     * excluidos del resultado cuando el filtro está activo.
     */
    private function applyProximityFilter(Builder $query, array $filters): void
    {
        $lat = $filters['lat'] ?? null;
        $lng = $filters['lng'] ?? null;
        $radiusKm = $filters['radius'] ?? null;

        if ($lat === null || $lng === null || $radiusKm === null) {
            return;
        }

        $query->whereRaw(
            'ST_Distance_Sphere(POINT(longitude, latitude), POINT(?, ?)) <= ?',
            [(float) $lng, (float) $lat, (float) $radiusKm * 1000]
        );
    }
}
