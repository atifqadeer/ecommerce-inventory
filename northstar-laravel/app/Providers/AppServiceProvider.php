<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Password reset emails link to the Vue reset page.
        ResetPassword::createUrlUsing(fn ($user, string $token) => url('/reset-password').'?'.http_build_query([
            'token' => $token,
            'email' => $user->email,
        ]));
    }
}
