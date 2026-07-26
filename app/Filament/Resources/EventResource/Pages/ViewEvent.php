<?php

namespace App\Filament\Resources\EventResource\Pages;

use App\Filament\Resources\EventResource;
use App\Models\Event;
use App\Models\EventRsvp;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Storage;

class ViewEvent extends ViewRecord
{
    protected static string $resource = EventResource::class;

    /**
     * Mismo criterio que ViewReport::NO_PHOTOS_TEXT / ViewUser::NO_*_TEXT —
     * sin copy aprobado en brand/copies.md para este estado vacío de
     * tooling interno de staff, texto neutro como fallback.
     */
    private const NO_ATTENDEES_TEXT = 'Nadie ha confirmado asistencia todavía';

    private const RSVP_STATUS_LABELS = [
        'interested' => 'Me interesa',
        'going' => 'Iré',
        'not_going' => 'No iré',
    ];

    public function infolist(Infolist $infolist): Infolist
    {
        /** @var Event $event */
        $event = $this->record;

        // Corrección 2026-07-26: la columna `image_url` ya no se persiste
        // (ver CreateEvent/EditEvent — el bucket real es privado, una URL
        // plana sin firmar da 403). `image_key` es la única fuente de
        // verdad; la URL firmada se genera aquí igual que en
        // `EventResource` (API móvil, `temporaryUrl()`), no una vez al
        // subir. Sin esto, el detalle del panel mostraba "Sin imagen"
        // incluso cuando la imagen sí existía en S3 (bug real reportado por
        // el humano — la app móvil sí la mostraba porque ya generaba la
        // URL firmada correctamente, solo este panel se quedó con la
        // columna vieja).
        $imageUrl = filled($event->image_key)
            ? Storage::disk('s3')->temporaryUrl($event->image_key, now()->addHours(4))
            : null;

        // Igual que ViewUser/ViewReport: cada fila necesita un fallback
        // calculado (display_name ?? email) por asistente, así que se
        // aplana a arrays antes de ->state() en vez de vincular
        // RepeatableEntry directamente a la relación `rsvps` (ver nota
        // técnica en features/safety/specs/plan.md sobre por qué la rama
        // ->state() con arrays es la que funciona para esto en Filament
        // 3.3.54).
        $attendeeRows = $event->rsvps()
            ->with('user.profile')
            ->latest()
            ->get()
            ->map(fn (EventRsvp $rsvp): array => [
                'attendee' => $rsvp->user->profile?->display_name ?? $rsvp->user->email,
                'status' => $rsvp->status,
                'status_label' => self::RSVP_STATUS_LABELS[$rsvp->status] ?? $rsvp->status,
                'updated_at' => $rsvp->updated_at,
            ])
            ->all();

        return $infolist
            ->schema([
                Section::make('Evento')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('title')->label('Título')->columnSpanFull(),

                        TextEntry::make('description')
                            ->label('Descripción')
                            ->columnSpanFull(),

                        TextEntry::make('category')
                            ->label('Categoría')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'pride' => 'purple',
                                'social' => 'info',
                                'art' => 'warning',
                                'activism' => 'danger',
                                default => 'gray',
                            })
                            ->formatStateUsing(fn (string $state): string => EventResource::categoryLabels()[$state] ?? $state),

                        TextEntry::make('event_date')->label('Fecha y hora')->dateTime('d/m/Y H:i'),

                        TextEntry::make('location_name')->label('Ubicación'),

                        TextEntry::make('coordinates')
                            ->label('Coordenadas')
                            ->state($event->latitude !== null && $event->longitude !== null
                                ? "{$event->latitude}, {$event->longitude}"
                                : null)
                            ->placeholder('Sin coordenadas'),

                        TextEntry::make('external_link')
                            ->label('Link externo')
                            ->url(fn (?string $state): ?string => $state, shouldOpenInNewTab: true)
                            ->placeholder('—'),

                        TextEntry::make('creator.name')
                            ->label('Creado por')
                            ->placeholder('—'),

                        TextEntry::make('created_at')->label('Creado el')->dateTime('d/m/Y H:i'),
                    ]),

                Section::make('Imagen')
                    ->schema([
                        ImageEntry::make('imageUrl')
                            ->hiddenLabel()
                            ->height(200)
                            ->state($imageUrl)
                            ->visible(filled($imageUrl)),

                        TextEntry::make('noImage')
                            ->hiddenLabel()
                            ->state('Sin imagen')
                            ->color('gray')
                            ->visible(blank($imageUrl)),
                    ]),

                // "Total de vistas" queda fuera de v1 — ver spec.md/plan.md.
                Section::make('Estadísticas de asistencia')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('interested_count')->label('Me interesa'),
                        TextEntry::make('going_count')->label('Iré'),
                        TextEntry::make('not_going_count')->label('No iré'),
                    ]),

                Section::make('Asistentes')
                    ->schema([
                        RepeatableEntry::make('attendeeRows')
                            ->hiddenLabel()
                            ->state($attendeeRows)
                            ->columns(3)
                            ->visible(filled($attendeeRows))
                            ->schema([
                                TextEntry::make('attendee')->label('Usuario'),
                                TextEntry::make('status_label')
                                    ->label('Estado')
                                    ->badge()
                                    // Solo se depende de $state (el label ya
                                    // resuelto), no de $record — mismo
                                    // motivo documentado en
                                    // ViewReport/ViewUser: dentro de un
                                    // RepeatableEntry alimentado por
                                    // ->state() con arrays, no vale la pena
                                    // arriesgar una resolución incorrecta de
                                    // $record por fila.
                                    ->color(fn (string $state): string => match ($state) {
                                        self::RSVP_STATUS_LABELS['going'] => 'success',
                                        self::RSVP_STATUS_LABELS['interested'] => 'warning',
                                        self::RSVP_STATUS_LABELS['not_going'] => 'gray',
                                        default => 'gray',
                                    }),
                                TextEntry::make('updated_at')->label('Última actualización')->dateTime('d/m/Y H:i'),
                            ]),

                        TextEntry::make('noAttendees')
                            ->hiddenLabel()
                            ->state(self::NO_ATTENDEES_TEXT)
                            ->color('gray')
                            ->visible(blank($attendeeRows)),
                    ]),
            ]);
    }
}
