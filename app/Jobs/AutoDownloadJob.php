<?php

namespace App\Jobs;

use App\Services\AutoDownloadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/** @psalm-api Laravel queue entrypoint; public members are consumed by the queue framework. */
class AutoDownloadJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Cooperative budget used by AutoDownloadService before starting more expensive work. */
    public const SCAN_BUDGET_SECONDS = 45;

    /** Execution lock expiry: above bounded scan runtime, below this queue's retry_after. */
    public const OVERLAP_EXPIRY_SECONDS = 85;

    /** Dispatch lock remains bounded while covering normal pending/running lifecycle. */
    public int $uniqueFor = 120;

    /** Secondary PCNTL safeguard; above bounded normal runtime and below overlap expiry. */
    public int $timeout = 75;

    public int $tries = 1;

    public function uniqueId(): string
    {
        return 'periodic-full-scan';
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('auto-download:periodic-full-scan'))
                ->dontRelease()
                ->expireAfter(self::OVERLAP_EXPIRY_SECONDS),
        ];
    }

    public function handle(AutoDownloadService $service): void
    {
        $service->check(now()->addSeconds(self::SCAN_BUDGET_SECONDS));
    }
}
