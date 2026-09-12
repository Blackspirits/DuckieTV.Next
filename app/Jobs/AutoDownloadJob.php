<?php

namespace App\Jobs;

use App\Services\AutoDownloadService;
use App\Support\AutoDownloadDeadline;
use App\Support\AutoDownloadRuntimePolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class AutoDownloadJob implements ShouldBeUnique, ShouldQueue
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
        return 'auto-download-periodic-full-check';
    }

    public function uniqueFor(): int
    {
        return AutoDownloadRuntimePolicy::uniqueLifetimeSeconds();
    }

    /**
     * Dispatch uniqueness prevents backlog while this execution lock also
     * protects against database-queue re-reservation of an already running job.
     *
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->shared()
                ->dontRelease()
                ->expireAfter(AutoDownloadRuntimePolicy::lockLifetimeSeconds()),
        ];
    }

    public function handle(AutoDownloadService $autoDownload): void
    {
        $deadline = new AutoDownloadDeadline(AutoDownloadRuntimePolicy::scanBudgetSeconds());

        $autoDownload->check($deadline);
    }
}
