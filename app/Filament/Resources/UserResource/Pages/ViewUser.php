<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\ReportResource;
use App\Filament\Resources\UserResource;
use App\Models\Report;
use App\Models\User;
use App\Models\UserMatch;
use App\Services\MatchingService;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Storage;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    /**
     * Mismo criterio que ViewReport::NO_PHOTOS_TEXT/NO_MESSAGES_TEXT — sin
     * copy aprobado en brand/copies.md para estos estados vacíos (tooling
     * interno de staff, exento por convención del proyecto), texto neutro
     * como fallback.
     */
    private const NO_PHOTOS_TEXT = 'Sin fotos';

    private const NO_REPORTS_SENT_TEXT = 'Este usuario no ha reportado a nadie';

    private const NO_REPORTS_RECEIVED_TEXT = 'Este usuario no ha sido reportado';

    private const NO_MATCHES_TEXT = 'Sin matches activos';

    private const VIDEO_PROCESSING_TEXT = 'Video en proceso';

    private const NO_VIDEO_TEXT = 'Sin video';

    public function infolist(Infolist $infolist): Infolist
    {
        /** @var User $user */
        $user = $this->record;
        $profile = $user->profile;

        $photos = $profile?->photos ?? collect();

        // Igual que ViewReport con los mensajes del día del reporte: estas
        // filas no se vinculan directamente a la relación real de Filament
        // (aunque `reportsSent`/`reportsReceived`/matches sí lo son a nivel
        // de modelo) porque cada fila necesita un fallback calculado
        // (display_name ?? email) — RepeatableEntry->state() con datos ya
        // aplanados a array es la única rama de Filament 3.3.54 confirmada
        // funcionando para eso en este proyecto (ver
        // features/safety/specs/plan.md → nota técnica de ViewReport). Las
        // fotos de abajo sí se vinculan por relación real porque no
        // necesitan ningún cálculo por fila (mismo patrón que
        // ViewVerificationRequest/ViewReport).
        $reportsSentRows = $user->reportsSent()
            ->with('reported.profile')
            ->latest()
            ->get()
            ->map(fn (Report $report): array => [
                'reason' => ReportResource::reasonLabels()[$report->reason] ?? $report->reason,
                'status' => ReportResource::statusLabels()[$report->status] ?? $report->status,
                'created_at' => $report->created_at,
                'target' => $report->reported->profile?->display_name ?? $report->reported->email,
            ])
            ->all();

        $reportsReceivedRows = $user->reportsReceived()
            ->with('reporter.profile')
            ->latest()
            ->get()
            ->map(fn (Report $report): array => [
                'reason' => ReportResource::reasonLabels()[$report->reason] ?? $report->reason,
                'status' => ReportResource::statusLabels()[$report->status] ?? $report->status,
                'created_at' => $report->created_at,
                'source' => $report->reporter->profile?->display_name ?? $report->reporter->email,
            ])
            ->all();

        // Solo matches vigentes en `matches` — los cancelados por bloqueo no
        // dejan rastro (SafetyService::blockUser() hace un delete físico,
        // `matches` no tiene soft delete). Alcance confirmado con el humano,
        // no se corrige aquí. Reusa MatchingService::getMatches() ya
        // existente (laravel-api) en vez de reimplementar la consulta —
        // ya devuelve `other_user` resuelto por fila.
        $matchRows = app(MatchingService::class)->getMatches($user)
            ->map(fn (UserMatch $match): array => [
                'other_user' => $match->other_user->profile?->display_name ?? $match->other_user->email,
                'created_at' => $match->created_at,
            ])
            ->all();

        // Misma regla de negocio que ProfileResource/PublicProfileResource
        // (backend, laravel-api): la key cruda de S3 (`video_url`) NUNCA se
        // expone — solo una URL firmada de corta duración, y solo si el
        // video ya terminó de procesarse (`video_processed`). Se calcula una
        // sola vez aquí, no dentro de un closure del schema que pudiera
        // reevaluarse varias veces por render.
        $signedVideoUrl = ($profile?->video_processed && $profile?->video_url)
            ? Storage::disk('s3')->temporaryUrl($profile->video_url, now()->addHours(4))
            : null;

        return $infolist
            ->schema([
                Section::make('Perfil')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('displayName')
                            ->label('Nombre')
                            ->state($profile?->display_name ?? '—'),
                        TextEntry::make('email')->label('Email'),
                        TextEntry::make('status')
                            ->label('Estado de cuenta')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'active' => 'success',
                                'suspended' => 'warning',
                                'banned' => 'danger',
                                default => 'gray',
                            })
                            ->formatStateUsing(fn (string $state): string => UserResource::statusLabels()[$state] ?? $state),
                        TextEntry::make('verificationStatus')
                            ->label('Verificación')
                            ->badge()
                            ->state($profile?->verification_status ?? 'unverified')
                            ->color(fn (string $state): string => match ($state) {
                                'unverified' => 'gray',
                                'pending' => 'warning',
                                'verified' => 'success',
                                'rejected' => 'danger',
                                default => 'gray',
                            })
                            ->formatStateUsing(fn (string $state): string => UserResource::verificationLabels()[$state] ?? $state),
                        TextEntry::make('created_at')->label('Registrado el')->dateTime('d/m/Y H:i'),
                        TextEntry::make('age')
                            ->label('Edad')
                            ->state($user->date_of_birth?->age)
                            ->placeholder('—'),
                        TextEntry::make('bio')
                            ->label('Bio')
                            ->state($profile?->bio)
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('city')
                            ->label('Ciudad')
                            ->state($profile?->city)
                            ->placeholder('—'),
                        TextEntry::make('intention')
                            ->label('Intención')
                            ->state($profile?->intention)
                            ->placeholder('—'),
                        TextEntry::make('genderIdentities')
                            ->label('Identidad de género')
                            ->state(static::joinCatalogWithCustom(
                                $profile?->genderIdentities,
                                $profile?->custom_gender_identity,
                            )),
                        TextEntry::make('orientations')
                            ->label('Orientación')
                            ->state(static::joinCatalogWithCustom(
                                $profile?->orientations,
                                $profile?->custom_orientation,
                            )),
                        TextEntry::make('pronouns')
                            ->label('Pronombres')
                            ->state(static::joinCatalogWithCustom(
                                $profile?->pronouns,
                                $profile?->custom_pronouns,
                            )),
                    ]),

                Section::make('Fotos')
                    ->schema([
                        // Vinculado directamente a la relación real
                        // (profile.photos, ya con eager load desde
                        // UserResource::getEloquentQuery()) — mismo patrón
                        // probado en ViewVerificationRequest/ViewReport.
                        RepeatableEntry::make('profile.photos')
                            ->hiddenLabel()
                            ->columns(4)
                            ->visible($photos->isNotEmpty())
                            ->schema([
                                ImageEntry::make('url')->hiddenLabel()->height(150),
                            ]),

                        TextEntry::make('noPhotos')
                            ->hiddenLabel()
                            ->state(self::NO_PHOTOS_TEXT)
                            ->color('gray')
                            ->visible($photos->isEmpty()),
                    ]),

                Section::make('Video de perfil')
                    ->schema([
                        ViewEntry::make('videoUrl')
                            ->hiddenLabel()
                            ->view('filament.infolists.video-player', ['url' => $signedVideoUrl])
                            ->visible($signedVideoUrl !== null),

                        TextEntry::make('videoProcessing')
                            ->hiddenLabel()
                            ->state(self::VIDEO_PROCESSING_TEXT)
                            ->color('gray')
                            ->visible(filled($profile?->video_url) && ! $profile?->video_processed),

                        TextEntry::make('noVideo')
                            ->hiddenLabel()
                            ->state(self::NO_VIDEO_TEXT)
                            ->color('gray')
                            ->visible(blank($profile?->video_url)),
                    ]),

                Section::make('Reportes enviados')
                    ->description('Reportes que este usuario hizo contra otras cuentas.')
                    ->schema([
                        RepeatableEntry::make('reportsSentRows')
                            ->hiddenLabel()
                            ->state($reportsSentRows)
                            ->columns(3)
                            ->visible(filled($reportsSentRows))
                            ->schema([
                                TextEntry::make('target')->label('A quién reportó'),
                                TextEntry::make('reason')->label('Razón')->badge()->color('gray'),
                                TextEntry::make('status')->label('Estado')->badge()->color('gray'),
                                TextEntry::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i'),
                            ]),

                        TextEntry::make('noReportsSent')
                            ->hiddenLabel()
                            ->state(self::NO_REPORTS_SENT_TEXT)
                            ->color('gray')
                            ->visible(blank($reportsSentRows)),
                    ]),

                Section::make('Reportes recibidos')
                    ->description('Reportes que otras cuentas hicieron contra este usuario.')
                    ->schema([
                        RepeatableEntry::make('reportsReceivedRows')
                            ->hiddenLabel()
                            ->state($reportsReceivedRows)
                            ->columns(3)
                            ->visible(filled($reportsReceivedRows))
                            ->schema([
                                TextEntry::make('source')->label('Quién reportó'),
                                TextEntry::make('reason')->label('Razón')->badge()->color('gray'),
                                TextEntry::make('status')->label('Estado')->badge()->color('gray'),
                                TextEntry::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i'),
                            ]),

                        TextEntry::make('noReportsReceived')
                            ->hiddenLabel()
                            ->state(self::NO_REPORTS_RECEIVED_TEXT)
                            ->color('gray')
                            ->visible(blank($reportsReceivedRows)),
                    ]),

                Section::make('Matches activos')
                    ->description('Solo matches vigentes — un match anulado por bloqueo no deja rastro (ver nota en features/profile/specs/plan.md).')
                    ->schema([
                        RepeatableEntry::make('matchRows')
                            ->hiddenLabel()
                            ->state($matchRows)
                            ->columns(2)
                            ->visible(filled($matchRows))
                            ->schema([
                                TextEntry::make('other_user')->label('Con quién'),
                                TextEntry::make('created_at')->label('Desde')->dateTime('d/m/Y H:i'),
                            ]),

                        TextEntry::make('noMatches')
                            ->hiddenLabel()
                            ->state(self::NO_MATCHES_TEXT)
                            ->color('gray')
                            ->visible(blank($matchRows)),
                    ]),
            ]);
    }

    /**
     * Junta los labels del catálogo (ej. géneros seleccionados) con el
     * valor libre `custom_*`, separados por coma. Devuelve '—' si no hay
     * nada de ninguna de las dos fuentes — mismo criterio de placeholder
     * usado en el resto del infolist.
     */
    private static function joinCatalogWithCustom(?\Illuminate\Support\Collection $catalogItems, ?string $customValue): string
    {
        $labels = ($catalogItems ?? collect())->pluck('label')->filter()->values()->all();

        if (filled($customValue)) {
            $labels[] = $customValue;
        }

        return filled($labels) ? implode(', ', $labels) : '—';
    }
}
