<?php

use App\Services\SubscriptionLifecycleService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('subscriptions:sync-status {--warning-days=3}', function () {
    $warningDays = (int) $this->option('warning-days');
    Log::info('Subscription sync started.', ['warning_days' => $warningDays]);
    $result = app(SubscriptionLifecycleService::class)->syncStatuses($warningDays);

    $this->info('Subscription sync completed.');
    $this->line('Warnings marked: ' . $result['warnings_marked']);
    $this->line('Expired processed: ' . $result['expired_processed']);
    Log::info('Subscription sync completed.', [
        'warnings_marked' => $result['warnings_marked'],
        'expired_processed' => $result['expired_processed'],
    ]);
})->purpose('Sync subscription warnings and expiry status.');

Schedule::command('subscriptions:sync-status')->hourly();
