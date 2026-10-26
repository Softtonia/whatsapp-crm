<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('leads:send-follow-up-reminders')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('campaigns:dispatch-scheduled')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('admin-password-otps:cleanup')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('tenants:dispatch-scheduled-offboarding')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('tenants:health-check')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('subscriptions:process-lifecycle')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('tenants:domains-reconcile')
    ->daily()
    ->withoutOverlapping();

Schedule::command('audit:prune --force')
    ->daily()
    ->withoutOverlapping();

