<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RefreshDatabaseJob;
use App\Services\DatabaseMaintenanceLock;
use App\Services\DatabaseRefreshProgressService;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class RefreshDatabaseJobTest extends TestCase
{
    public function test_job_dispatches_one_refresh_job_per_snapshot_series(): void
    {
        Bus::fake();

        $progress = Mockery::mock(DatabaseRefreshProgressService::class);
        $progress->shouldReceive('running')->once();
        $progress->shouldReceive('setBatchId')->once();
        $progress->shouldNotReceive('complete');
        $progress->shouldNotReceive('fail');

        $lock = Mockery::mock(DatabaseMaintenanceLock::class);
        $lock->shouldNotReceive('release');

        $job = new RefreshDatabaseJob([11, 22], 'refresh-owner');
        $job->handle($progress, $lock);

        Bus::assertBatched(function (\Illuminate\Bus\PendingBatch $batch): bool {
            return $batch->jobs->count() === 2
                && $batch->name === 'Refreshing Database (2 series)';
        });
    }

    public function test_empty_snapshot_completes_and_releases_maintenance_lock(): void
    {
        Bus::fake();

        $progress = Mockery::mock(DatabaseRefreshProgressService::class);
        $progress->shouldReceive('running')->once()->ordered();
        $progress->shouldReceive('complete')->once()->ordered();
        $progress->shouldNotReceive('setBatchId');
        $progress->shouldNotReceive('fail');

        $lock = Mockery::mock(DatabaseMaintenanceLock::class);
        $lock->shouldReceive('release')->once()->with('refresh-owner')->ordered();

        $job = new RefreshDatabaseJob([], 'refresh-owner');
        $job->handle($progress, $lock);

        Bus::assertNothingBatched();
    }
}
