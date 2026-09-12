<?php

namespace App\Jobs;

use App\Services\AutoDownloadLifecycleService;
use App\Support\AutoDownloadRuntimePolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AutoDownloadTickJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct()
    {
        $this->onConnection('autodownload');
        $this->onQueue('autodownload');
    }

    public function uniqueId(): string
    {
        return 'auto-download-lifecycle-tick';
    }

    public function uniqueFor(): int
    {
        return (AutoDownloadRuntimePolicy::CADENCE_MINUTES + 1) * 60;
    }

    public function handle(AutoDownloadLifecycleService $lifecycle): void
    {
        $lifecycle->onCadenceTick();
    }
}
