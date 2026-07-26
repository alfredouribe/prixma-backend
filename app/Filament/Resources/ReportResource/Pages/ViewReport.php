<?php

namespace App\Filament\Resources\ReportResource\Pages;

use App\Filament\Resources\ReportResource;
use App\Models\Message;
use App\Models\Report;
use App\Services\SafetyService;
use Filament\Actions;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewReport extends ViewRecord
{
    protected static string $resource = ReportResource::class;

    /**
     * Texto mostrado cuando el usuario reportado no tiene fotos de perfil.
     * No hay un texto aprobado en brand/copies.md para este caso (ese
     * archivo cubre copy de cara al usuario final, no tooling interno de
     * staff) — se usa un texto neutro/descriptivo como fallback.
     */
    private const NO_PHOTOS_TEXT = 'Sin fotos';

    /**
     * Mismo criterio que NO_PHOTOS_TEXT: sin copy aprobado equivalente,
     * texto neutro como fallback.
     */
    private const NO_MESSAGES_TEXT = 'Sin mensajes ese día';

    public function infolist(Infolist $infolist): Infolist
    {
        $report = $this->record;

        // Calculados una sola vez aquí (no dentro de closures del schema)
        // para no repetir la query en cada evaluación de ->visible().
        $photos = app(SafetyService::class)->getReportedUserPhotos($report);
        $messages = app(SafetyService::class)->getReportedUserMessagesForReportDate($report);

        // RepeatableEntry, cuando se le pasa una colección de modelos Eloquent
        // vía ->state(), no resuelve el $record correcto para cada fila hijo
        // (ComponentContainer::getState() en Filament 3.3 recorre hacia el
        // contenedor padre en vez de usar el ->record() que sí se asignó por
        // fila, así que las columnas terminan resolviendo el Report de nivel
        // superior en vez del Message de esa fila — confirmado empíricamente).
        // Por eso cada mensaje se aplana aquí a un array plano con el texto ya
        // resuelto (nombre del remitente, distinguiendo reportado vs. la otra
        // persona) — con arrays sí funciona (ver rama `elseif (is_array($itemData))`
        // en RepeatableEntry::getChildComponentContainers()).
        $messageRows = $messages
            ->map(function (Message $message) use ($report): array {
                $senderName = $message->sender->profile?->display_name ?? $message->sender->email;
                $isReportedSender = $message->sender_id === $report->reported_id;

                return [
                    'sender_label' => $isReportedSender ? "{$senderName} (reportado)" : $senderName,
                    'created_at' => $message->created_at,
                    'content' => $message->content,
                ];
            })
            ->all();

        return $infolist
            ->schema([
                Section::make('Reporte')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('reporter.profile.display_name')
                            ->label('Reportante')
                            ->getStateUsing(fn (Report $record): string => $record->reporter->profile?->display_name
                                ?? $record->reporter->email),
                        TextEntry::make('reporter.email')->label('Email del reportante'),

                        TextEntry::make('reported.profile.display_name')
                            ->label('Reportado')
                            ->getStateUsing(fn (Report $record): string => $record->reported->profile?->display_name
                                ?? $record->reported->email),
                        TextEntry::make('reported.email')->label('Email del reportado'),

                        TextEntry::make('reason')
                            ->label('Razón')
                            ->badge()
                            ->color('gray')
                            ->formatStateUsing(fn (string $state): string => ReportResource::reasonLabels()[$state] ?? $state),

                        TextEntry::make('status')
                            ->label('Estado')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'pending' => 'warning',
                                'reviewed' => 'info',
                                'resolved' => 'success',
                                default => 'gray',
                            })
                            ->formatStateUsing(fn (string $state): string => ReportResource::statusLabels()[$state] ?? $state),

                        TextEntry::make('created_at')->label('Reportado el')->dateTime('d/m/Y H:i'),
                        TextEntry::make('updated_at')->label('Última actualización')->dateTime('d/m/Y H:i'),

                        TextEntry::make('description')
                            ->label('Descripción')
                            ->columnSpanFull()
                            ->placeholder('Sin descripción')
                            ->visible(fn (Report $record): bool => filled($record->description)),
                    ]),

                Section::make('Fotos del perfil reportado')
                    ->schema([
                        // Vinculado directamente a la relación real
                        // (reported → profile → photos, ya con eager load
                        // desde ReportResource::getEloquentQuery()) — mismo
                        // patrón ya usado y verificado en
                        // ViewVerificationRequest con `profile.photos`, a
                        // diferencia de la sección de mensajes de abajo (que
                        // no es una relación real navegable, es una consulta
                        // compuesta con filtro de fecha/límite).
                        RepeatableEntry::make('reported.profile.photos')
                            ->hiddenLabel()
                            ->columns(4)
                            ->visible($photos->isNotEmpty())
                            ->schema([
                                ImageEntry::make('url')->hiddenLabel()->height(150),
                            ]),

                        TextEntry::make('noReportedPhotos')
                            ->hiddenLabel()
                            ->state(self::NO_PHOTOS_TEXT)
                            ->color('gray')
                            ->visible($photos->isEmpty()),
                    ]),

                Section::make('Mensajes del día del reporte')
                    ->description('Hasta 20 mensajes más recientes del día calendario en que se creó el reporte ('.$report->created_at->format('d/m/Y').'), de todas las conversaciones donde participó el usuario reportado — incluye ambos lados de la conversación.')
                    ->schema([
                        RepeatableEntry::make('reportedMessages')
                            ->hiddenLabel()
                            ->state($messageRows)
                            ->columns(3)
                            ->visible($messages->isNotEmpty())
                            ->schema([
                                TextEntry::make('sender_label')
                                    ->label('De')
                                    ->badge()
                                    ->color(fn (string $state): string => str_ends_with($state, '(reportado)') ? 'danger' : 'gray'),

                                TextEntry::make('created_at')
                                    ->label('Hora')
                                    ->dateTime('d/m/Y H:i'),

                                TextEntry::make('content')
                                    ->label('Mensaje')
                                    ->columnSpanFull(),
                            ]),

                        TextEntry::make('noReportedMessages')
                            ->hiddenLabel()
                            ->state(self::NO_MESSAGES_TEXT)
                            ->color('gray')
                            ->visible($messages->isEmpty()),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('markReviewed')
                ->label('Marcar como revisado')
                ->color('info')
                ->icon('heroicon-o-eye')
                ->requiresConfirmation()
                ->modalDescription('¿Confirmas que este reporte ya fue revisado por el equipo?')
                ->visible(fn (Report $record): bool => $record->status !== 'reviewed')
                ->authorize(fn (Report $record): bool => (bool) auth('admin')->user()?->can('review', $record))
                ->action(function (Report $record) {
                    app(SafetyService::class)->markReportAsReviewed($record);

                    $this->record->refresh();

                    Notification::make()
                        ->title('Reporte marcado como revisado')
                        ->success()
                        ->send();
                }),

            Actions\Action::make('markResolved')
                ->label('Marcar como resuelto')
                ->color('success')
                ->icon('heroicon-o-check-circle')
                ->requiresConfirmation()
                ->modalDescription('¿Confirmas que este reporte quedó resuelto? Esta acción no aplica ninguna sanción al usuario reportado — eso queda fuera del alcance del panel por ahora.')
                ->visible(fn (Report $record): bool => $record->status !== 'resolved')
                ->authorize(fn (Report $record): bool => (bool) auth('admin')->user()?->can('review', $record))
                ->action(function (Report $record) {
                    app(SafetyService::class)->markReportAsResolved($record);

                    $this->record->refresh();

                    Notification::make()
                        ->title('Reporte marcado como resuelto')
                        ->success()
                        ->send();
                }),
        ];
    }
}
