<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
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
        // Detrás de un proxy (Render, balanceadores) solo se confía en X-Forwarded-* si se configura,
        // así los límites por IP usan la IP real del cliente y no la del proxy.
        $proxies = config('app.trusted_proxies');
        if (is_string($proxies) && $proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        if (config('app.force_https')) {
            URL::forceScheme('https');
        }

        // Límite general de la API: 60 peticiones por minuto por usuario (o por IP si no hay sesión)
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user()?->id ?: $request->ip()));

        // Registro e inicio de sesión: 10 intentos por minuto por IP (frena fuerza bruta y spam de cuentas)
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // Crear órdenes y pagos: 10 por minuto por usuario (evita acaparar stock o abusar de Stripe)
        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(10)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
