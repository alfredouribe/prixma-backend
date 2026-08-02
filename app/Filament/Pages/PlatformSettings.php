<?php

namespace App\Filament\Pages;

use App\Models\PlatformSetting;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Configuración global de la versión reducida de Prixma+ (sin cobros
 * reales) — ver features/premium/specs/plan.md → "Filament". Fila única
 * (`PlatformSetting::current()`), no un Resource — no hay lista de
 * registros, es un formulario de ajustes.
 */
class PlatformSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'Herramientas';

    protected static ?string $navigationLabel = 'Premium (config)';

    protected static ?string $title = 'Configuración de Premium';

    protected static string $view = 'filament.pages.platform-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $admin = auth('admin')->user();

        return $admin && in_array($admin->role, ['admin', 'superadmin'], true);
    }

    public function mount(): void
    {
        $settings = PlatformSetting::current();

        $this->form->fill([
            'free_swipes_per_ad' => $settings->free_swipes_per_ad,
            'free_likes_per_day' => $settings->free_likes_per_day,
            'free_chat_minutes_before_ad' => $settings->free_chat_minutes_before_ad,
            'chat_ad_video_key' => $settings->chat_ad_video_key,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('free_swipes_per_ad')
                    ->label('Cards de Explorar entre cada anuncio')
                    ->numeric()
                    ->minValue(1)
                    ->required(),

                TextInput::make('free_likes_per_day')
                    ->label('Likes/super likes gratis al día')
                    ->numeric()
                    ->minValue(1)
                    ->required(),

                TextInput::make('free_chat_minutes_before_ad')
                    ->label('Minutos de chat antes del video de anuncio')
                    ->numeric()
                    ->minValue(1)
                    ->required(),

                FileUpload::make('chat_ad_video_key')
                    ->label('Video de anuncio en chat')
                    ->disk('s3')
                    ->directory('premium')
                    ->visibility('private')
                    ->acceptedFileTypes(['video/mp4', 'video/quicktime'])
                    ->maxSize(51200)
                    ->nullable()
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        PlatformSetting::current()->update($data);

        Notification::make()->title('Configuración guardada.')->success()->send();
    }
}
