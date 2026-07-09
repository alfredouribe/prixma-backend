<?php

namespace App\Filament\Resources\VerificationRequestResource\Pages;

use App\Filament\Resources\VerificationRequestResource;
use App\Models\VerificationRequest;
use App\Services\VerificationService;
use Filament\Actions;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Storage;

class ViewVerificationRequest extends ViewRecord
{
    protected static string $resource = VerificationRequestResource::class;

    /**
     * Duración de las URLs firmadas para ver el documento/selfie. Nunca se
     * cachean — se generan cada vez que se abre el detalle.
     */
    private const SIGNED_URL_TTL_MINUTES = 5;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Solicitud')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('profile.display_name')->label('Usuario'),
                        TextEntry::make('profile.user.email')->label('Email'),
                        TextEntry::make('status')
                            ->label('Estado')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'pending' => 'warning',
                                'approved' => 'success',
                                'rejected' => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('created_at')->label('Solicitado')->dateTime('d/m/Y H:i'),
                        TextEntry::make('reviewedBy.name')->label('Revisado por')->placeholder('—'),
                        TextEntry::make('reviewed_at')->label('Revisado el')->dateTime('d/m/Y H:i')->placeholder('—'),
                        TextEntry::make('rejection_reason')
                            ->label('Motivo de rechazo')
                            ->columnSpanFull()
                            ->visible(fn (VerificationRequest $record): bool => filled($record->rejection_reason)),
                    ]),

                Section::make('Documento de identidad')
                    ->description('URLs firmadas de corta duración — no se cachean.')
                    ->columns(2)
                    ->schema([
                        ImageEntry::make('document_s3_key')
                            ->label('INE (frente)')
                            ->getStateUsing(fn (VerificationRequest $record): string => Storage::disk('s3_identity')
                                ->temporaryUrl($record->document_s3_key, now()->addMinutes(self::SIGNED_URL_TTL_MINUTES)))
                            ->height(300),
                        ImageEntry::make('selfie_s3_key')
                            ->label('Selfie de comparación')
                            ->getStateUsing(fn (VerificationRequest $record): ?string => filled($record->selfie_s3_key)
                                ? Storage::disk('s3_identity')->temporaryUrl($record->selfie_s3_key, now()->addMinutes(self::SIGNED_URL_TTL_MINUTES))
                                : null)
                            ->visible(fn (VerificationRequest $record): bool => filled($record->selfie_s3_key))
                            ->height(300),
                    ]),

                Section::make('Fotos de perfil (para comparar)')
                    ->schema([
                        RepeatableEntry::make('profile.photos')
                            ->hiddenLabel()
                            ->columns(3)
                            ->schema([
                                ImageEntry::make('url')->hiddenLabel()->height(150),
                            ]),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('approve')
                ->label('Aprobar')
                ->color('success')
                ->icon('heroicon-o-check-circle')
                ->requiresConfirmation()
                ->modalDescription('¿Confirmas que el documento es válido y corresponde al perfil? Esta acción marca el perfil como verificado.')
                ->visible(fn (VerificationRequest $record): bool => $record->status === 'pending')
                ->authorize(fn (VerificationRequest $record): bool => (bool) auth('admin')->user()?->can('review', $record))
                ->action(function (VerificationRequest $record) {
                    app(VerificationService::class)->approve($record, auth('admin')->user());

                    $this->record->refresh();

                    Notification::make()
                        ->title('Solicitud aprobada')
                        ->success()
                        ->send();
                }),

            Actions\Action::make('reject')
                ->label('Rechazar')
                ->color('danger')
                ->icon('heroicon-o-x-circle')
                ->requiresConfirmation()
                ->form([
                    Textarea::make('rejection_reason')
                        ->label('Motivo de rechazo')
                        ->required()
                        ->maxLength(300),
                ])
                ->visible(fn (VerificationRequest $record): bool => $record->status === 'pending')
                ->authorize(fn (VerificationRequest $record): bool => (bool) auth('admin')->user()?->can('review', $record))
                ->action(function (VerificationRequest $record, array $data) {
                    app(VerificationService::class)->reject($record, auth('admin')->user(), $data['rejection_reason']);

                    $this->record->refresh();

                    Notification::make()
                        ->title('Solicitud rechazada')
                        ->success()
                        ->send();
                }),
        ];
    }
}
