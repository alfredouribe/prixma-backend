<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Models\ProfilePhoto;
use App\Services\ProfileService;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Permite eliminar una foto puntual del perfil del usuario — pedido real del
 * humano (ver features/profile/specs/plan.md → "Eliminar foto específica"),
 * la sección "Fotos" de ViewUser (Infolist) es de solo lectura por diseño en
 * Filament y no soporta una Action por fila.
 *
 * `$relationship = 'photos'` apunta a `User::photos()` (hasManyThrough vía
 * Profile) — nuevo en el modelo, agregado solo para que este RelationManager
 * tenga una relación real que nombrar. Las fotos realmente cuelgan de
 * `Profile`, no directo de `User` (ver domain.md → ProfilePhoto), así que sin
 * ese método no habría ninguna relación de Eloquent en `User` que usar aquí.
 *
 * El hallazgo técnico documentado en features/safety/specs/plan.md
 * ("RepeatableEntry::make(...)->state(...) no resuelve el $record correcto
 * por fila") es un bug de Infolists, no de Tables — no aplica aquí: este es
 * un `Filament\Tables\Table` real (mismo mecanismo que cualquier tabla del
 * panel), cada fila resuelve su propio $record de forma nativa y correcta.
 */
class PhotosRelationManager extends RelationManager
{
    protected static string $relationship = 'photos';

    protected static ?string $title = 'Fotos';

    protected static ?string $icon = 'heroicon-o-photo';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('url')
                    ->label('Foto')
                    ->height(100)
                    ->square(),

                Tables\Columns\TextColumn::make('position')
                    ->label('Posición')
                    ->sortable(),
            ])
            ->defaultSort('position')
            // Sin acción de "crear": las fotos solo se suben desde la app
            // móvil (Media Upload Pipeline, constitution.md) — nunca desde
            // el panel admin.
            ->headerActions([])
            ->actions([
                // Delega por completo en ProfileService::deletePhoto() (ya
                // existente, usado también por el endpoint de auto-servicio
                // del usuario) — ninguna lógica de negocio vive aquí
                // (constitution.md → "No business logic in the panel").
                // Reordena las posiciones restantes y borra de S3 por sí
                // solo; esta Action solo confirma y lo invoca.
                Tables\Actions\Action::make('deletePhoto')
                    ->label('Eliminar')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('¿Eliminar esta foto del perfil del usuario? Se borra también del almacenamiento (S3) y las demás fotos se reordenan. Esta acción no se puede deshacer.')
                    ->action(function (ProfilePhoto $record): void {
                        app(ProfileService::class)->deletePhoto($this->getOwnerRecord(), $record->id);

                        Notification::make()
                            ->title('Foto eliminada')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([]);
    }
}
