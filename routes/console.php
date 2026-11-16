<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Libera el stock de órdenes no pagadas (requiere `php artisan schedule:work` o un cron con `schedule:run`)
Schedule::command('orders:expire-pending')->everyFiveMinutes()->withoutOverlapping();
