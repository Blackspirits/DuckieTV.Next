<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RestoreBackupJob;
use App\Services\BackupService;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class RestoreBackupJobTest extends TestCase
{
    public function test_job_dispatches_batch_and_updates_cache()
    {
        // Mock Bus
        \Illuminate\Support\Facades\Bus::fake();

        // Mock Cache
        Cache::shouldReceive('put')->atLeast()->times(1);
        Cache::shouldReceive('get')->andReturn(['logs' => [], 'percent' => 0]);

        // Mock BackupService for settings restore
        $mockService = Mockery::mock(BackupService::class);
        $mockService->shouldReceive('restore')->once();

        // Data with 2 series
        $data = [
            'settings' => ['foo' => 'bar', 'useTrakt_id' => true],
            'series' => [
                '123' => [],
                '456' => [],
            ],
        ];

        $job = new RestoreBackupJob($data);
        $job->handle($mockService);

        // Assert Batch Dispatched
        \Illuminate\Support\Facades\Bus::assertBatched(function (\Illuminate\Bus\PendingBatch $batch) {
            if ($batch->jobs->count() !== 2 || $batch->name != 'Restoring Backup (2 shows)') {
                return false;
            }

            foreach ($batch->jobs as $restoreShowJob) {
                $property = new \ReflectionProperty($restoreShowJob, 'useTraktId');
                if ($property->getValue($restoreShowJob) !== true) {
                    return false;
                }
            }

            return true;
        });
    }
}
