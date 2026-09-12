<?php

namespace Tests\Feature\Jobs;

use App\Jobs\AutoDownloadJob;
use App\Services\AutoDownloadLifecycleService;
use App\Services\AutoDownloadService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class AutoDownloadDatabaseQueueRuntimeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();

        parent::tearDown();
    }

    public function test_database_queue_executes_one_unique_periodic_scan_and_releases_the_dispatch_lock(): void
    {
        config([
            'queue.default' => 'database',
            'cache.default' => 'database',
        ]);

        Carbon::setTestNow('2026-09-12 12:00:00');

        $settings = app(SettingsService::class);
        $settings->set('torrenting.enabled', true);
        $settings->set('torrenting.autodownload', true);

        $service = Mockery::mock(AutoDownloadService::class);
        $service->shouldReceive('check')
            ->once()
            ->withArgs(fn (?Carbon $deadline): bool => $deadline !== null
                && $deadline->equalTo(now()->addSeconds(AutoDownloadJob::SCAN_BUDGET_SECONDS)));
        $this->app->instance(AutoDownloadService::class, $service);

        $lifecycle = app(AutoDownloadLifecycleService::class);

        $this->assertTrue($lifecycle->dispatchIfEligible());
        $this->assertTrue($lifecycle->dispatchIfEligible());

        $this->assertSame(1, DB::table('jobs')->where('queue', AutoDownloadLifecycleService::QUEUE)->count());

        $exitCode = Artisan::call('queue:work', [
            'connection' => AutoDownloadLifecycleService::CONNECTION,
            '--queue' => AutoDownloadLifecycleService::QUEUE,
            '--once' => true,
            '--tries' => 1,
            '--timeout' => 75,
            '--sleep' => 0,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());

        // A successful worker execution must release the ShouldBeUnique lock,
        // otherwise future periodic scans would be suppressed until uniqueFor expires.
        $this->assertTrue($lifecycle->dispatchIfEligible());
        $this->assertSame(1, DB::table('jobs')->where('queue', AutoDownloadLifecycleService::QUEUE)->count());
    }
}
