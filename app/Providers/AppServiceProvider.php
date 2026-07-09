<?php

namespace App\Providers;

use App\Models\Admin;
use App\Models\VerificationRequest;
use App\Observers\VerificationRequestObserver;
use App\Policies\AdminPolicy;
use App\Policies\VerificationRequestPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        VerificationRequest::observe(VerificationRequestObserver::class);

        // Policies del panel de administración (Filament) — registradas
        // explícitamente, nunca checks inline de rol dentro de los Resources.
        Gate::policy(VerificationRequest::class, VerificationRequestPolicy::class);
        Gate::policy(Admin::class, AdminPolicy::class);

        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return 'prixma://reset-password?token='.$token.'&email='.urlencode($notifiable->getEmailForPasswordReset());
        });
    }
}
