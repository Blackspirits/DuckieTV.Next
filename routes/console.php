<?php

use App\Jobs\PruneAutoDLActivitiesJob;
use App\Services\AutoDownloadLifecycleService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    app(AutoDownloadLifecycleService::class)->dispatchIfEligible();
})
    ->name('auto-download:lifecycle')
    ->everyFifteenMinutes();

Schedule::job(new PruneAutoDLActivitiesJob)->daily();
