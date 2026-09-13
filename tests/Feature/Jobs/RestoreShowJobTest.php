<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RestoreShowJob;
use App\Services\BackupService;
use Illuminate\Bus\Batch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class RestoreShowJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_calls_service_and_updates_cache()
    {
        // Mock Batch
        $batch = Mockery::mock(Batch::class);
        $batch->shouldReceive('cancelled')->andReturn(false);
        $batch->shouldReceive('progress')->andReturn(50);

        // Mock BackupService
        $service = Mockery::mock(BackupService::class);
        $service->shouldReceive('restoreShow')
            ->with('123', [], Mockery::type('callable'), true)
            ->once()
            ->andReturnUsing(function ($id, $data, $callback, $useTraktId) {
                $callback(100, 'Done');

                return true;
            });

        // Mock Cache
        Cache::shouldReceive('get')->andReturn(['logs' => []]);
        Cache::shouldReceive('put')->once()->with('backup_progress', Mockery::on(function ($data) {
            return $data['percent'] === 50 && $data['message'] === 'Done';
        }));

        // Use Test Double
        $job = new TestRestoreShowJob('123', [], true);
        $job->setBatch($batch);

        $job->handle($service);

        $this->assertTrue(true);
    }

    public function test_retry_policy_separates_rate_limit_releases_from_real_exceptions()
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.connection' => config('database.default'),
        ]);

        RestoreShowJob::dispatch('123', []);

        $payload = json_decode(
            (string) \Illuminate\Support\Facades\DB::table('jobs')->value('payload'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame(0, $payload['maxTries']);
        $this->assertSame(3, $payload['maxExceptions']);
        $this->assertNull($payload['retryUntil']);
        $this->assertSame('5,15', $payload['backoff']);
    }

    public function test_handle_aborts_if_cancelled()
    {
        $batch = Mockery::mock(Batch::class);
        $batch->shouldReceive('cancelled')->andReturn(true);

        $service = Mockery::mock(BackupService::class);
        $service->shouldReceive('restoreShow')->never();

        $job = new TestRestoreShowJob('123', []);
        $job->setBatch($batch);

        $job->handle($service);

        $this->assertTrue(true);
    }
}

class TestRestoreShowJob extends RestoreShowJob
{
    protected $testBatch;

    public function setBatch($batch)
    {
        $this->testBatch = $batch;
    }

    public function batch()
    {
        return $this->testBatch;
    }
}
