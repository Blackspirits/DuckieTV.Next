<?php

use App\Jobs\PruneAutoDLActivitiesJob;
use App\Services\AutoDownloadLifecycleService;
use App\Services\TraktUpdateLifecycleService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    app(AutoDownloadLifecycleService::class)->recoverExpiredReservation();
})
    ->name('auto-download:recovery')
    ->everyMinute();

Schedule::call(function (): void {
    app(AutoDownloadLifecycleService::class)->dispatchIfEligible();
})
    ->name('auto-download:lifecycle')
    ->everyFifteenMinutes();

Schedule::call(function (): void {
    app(TraktUpdateLifecycleService::class)->dispatchIfDue();
})
    ->name('trakt-update:lifecycle')
    ->everyMinute();

Schedule::job(new PruneAutoDLActivitiesJob)->daily();
