<?php

namespace App\Providers\Filament;

use App\Http\Controllers\Admin\VerificationDocumentController;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->authGuard('admin') // guard dedicado para Admin — nunca 'web'/'sanctum' de los usuarios finales
            ->colors([
                'primary' => Color::Purple,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                Widgets\FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            // Ruta autenticada por el guard `admin` del panel (misma sesión,
            // mismo middleware de auth que las Resources) para hacer streaming
            // del documento/selfie de verificación desde el disco local.
            // Nunca S3, nunca URL firmada — ver constitution.md → "Media
            // Upload Pipeline" (excepción documentada para Verification).
            ->authenticatedRoutes(function (Panel $panel): void {
                Route::get('/verification-requests/{verificationRequest}/document', [VerificationDocumentController::class, 'document'])
                    ->name('resources.verification-requests.document');

                Route::get('/verification-requests/{verificationRequest}/selfie', [VerificationDocumentController::class, 'selfie'])
                    ->name('resources.verification-requests.selfie');
            });
    }
}
