<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
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
        RateLimiter::for('api', fn ($r) => Limit::perMinute(60)->by($r->ip()));
        RateLimiter::for('register', fn ($r) => Limit::perMinute(5)->by($r->ip()));
        RateLimiter::for('login', fn ($r) => Limit::perMinute(10)->by($r->ip()));
        RateLimiter::for('email', fn ($r) => Limit::perMinute(3)->by(
            $r->user()
                ? 'user:'.$r->user()->id
                : $r->ip().'|'.hash('sha256', mb_strtolower(trim((string) $r->input('email'))))
        ));
        VerifyEmail::createUrlUsing(fn ($user) => rtrim(config('babi.frontend_url'), '/').'/?emailVerify='.rawurlencode(URL::temporarySignedRoute(
            'verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)]
        )));
    }
}
