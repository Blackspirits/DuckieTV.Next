<?php

namespace App\Jobs;

use App\Services\DatabaseMaintenanceLock;
use App\Services\DatabaseRefreshProgressService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class RefreshDatabaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @psalm-suppress PossiblyUnusedProperty Laravel reads this queue payload contract reflectively. */
    public $timeout = 3600;

    public function __construct(
        protected array $seriesIds,
        protected ?string $maintenanceLockOwner = null
    ) {}

    /** @psalm-suppress PossiblyUnusedMethod Laravel invokes queue handlers reflectively. */
    public function handle(
        DatabaseRefreshProgressService $progress,
        DatabaseMaintenanceLock $maintenanceLock
    ): void {
        $lockTransferredToBatch = false;

        try {
            $progress->running();

            $jobs = array_map(
                fn (int $seriesId): RefreshSeriesJob => new RefreshSeriesJob($seriesId),
                $this->seriesIds
            );

            if ($jobs === []) {
                $progress->complete();
                $maintenanceLock->release($this->maintenanceLockOwner);

                return;
            }

            $maintenanceLockOwner = $this->maintenanceLockOwner;

            $batch = Bus::batch($jobs)
                ->then(function () {
                    app(DatabaseRefreshProgressService::class)->complete();
                })
                ->catch(function (\Illuminate\Bus\Batch $batch, \Throwable $e) {
                    Log::error('Database refresh batch failed unexpectedly.', [
                        'batch_id' => $batch->id,
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]);
                    app(DatabaseRefreshProgressService::class)->fail();
                })
                ->finally(function () use ($maintenanceLockOwner) {
                    app(DatabaseMaintenanceLock::class)->release($maintenanceLockOwner);
                })
                ->allowFailures()
                ->name('Refreshing Database ('.count($jobs).' series)')
                ->dispatch();

            $lockTransferredToBatch = true;
            $progress->setBatchId($batch->id);
        } catch (\Throwable $e) {
            Log::error('RefreshDatabaseJob failed.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'lock_transferred_to_batch' => $lockTransferredToBatch,
            ]);

            if ($lockTransferredToBatch) {
                return;
            }

            $maintenanceLock->release($this->maintenanceLockOwner);
            $progress->fail();

            throw $e;
        }
    }
}
