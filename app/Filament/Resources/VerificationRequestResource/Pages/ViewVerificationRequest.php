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
     * Texto mostrado cuando el archivo ya no existe en el disco local — la
     * solicitud ya fue aprobada/rechazada y VerificationService borró el
     * documento/selfie (son de vida corta, ver constitution.md → "Media
     * Upload Pipeline"). No es copy de marca: el panel admin es tooling
     * interno de staff, brand/copies.md no aplica aquí.
     */
    private const FILE_MISSING_TEXT = 'Documento ya no disponible — la solicitud ya fue revisada y el archivo se eliminó.';

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
                    ->description('Servido en streaming desde el disco privado del servidor — nunca S3, nunca URL pública. El archivo se elimina en cuanto la solicitud se resuelve.')
                    ->columns(2)
                    ->schema([
                        ImageEntry::make('document_path')
                            ->label('INE (frente)')
                            ->getStateUsing(fn (VerificationRequest $record): string => route(
                                'filament.admin.resources.verification-requests.document',
                                $record,
                            ))
                            ->visible(fn (VerificationRequest $record): bool => Storage::disk('local')->exists($record->document_path))
                            ->height(300),
                        TextEntry::make('document_path_missing')
                            ->label('INE (frente)')
                            ->state(self::FILE_MISSING_TEXT)
                            ->color('gray')
                            ->visible(fn (VerificationRequest $record): bool => ! Storage::disk('local')->exists($record->document_path)),

                        ImageEntry::make('selfie_path')
                            ->label('Selfie de comparación')
                            ->getStateUsing(fn (VerificationRequest $record): string => route(
                                'filament.admin.resources.verification-requests.selfie',
                                $record,
                            ))
                            ->visible(fn (VerificationRequest $record): bool => filled($record->selfie_path)
                                && Storage::disk('local')->exists($record->selfie_path))
                            ->height(300),
                        TextEntry::make('selfie_path_missing')
                            ->label('Selfie de comparación')
                            ->state(self::FILE_MISSING_TEXT)
                            ->color('gray')
                            ->visible(fn (VerificationRequest $record): bool => filled($record->selfie_path)
                                && ! Storage::disk('local')->exists($record->selfie_path)),
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
