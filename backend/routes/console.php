<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('babi:reconcile-media')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('babi:send-vaccination-reminders')->everyMinute()->withoutOverlapping();
Schedule::command('babi:send-vaccination-push-reminders')->everyMinute()->withoutOverlapping();
Schedule::command('babi:send-appointment-reminders')->everyMinute()->withoutOverlapping();
Schedule::command('babi:send-memory-notifications')->everyMinute()->withoutOverlapping();
Schedule::command('babi:send-milestone-reminders')->everyMinute()->timezone('Asia/Ho_Chi_Minh')->withoutOverlapping();
Schedule::command('babi:sync-milestone-reminders')->dailyAt('02:15')->timezone('Asia/Ho_Chi_Minh')->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
