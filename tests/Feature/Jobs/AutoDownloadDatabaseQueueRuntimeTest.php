<?php

namespace Tests\Feature\Jobs;

use App\Services\AutoDownloadLifecycleService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AutoDownloadDatabaseQueueRuntimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'queue.default' => 'database',
            'cache.default' => 'database',
        ]);

        app('cache')->setDefaultDriver('database');
    }

    public function test_lifecycle_dispatch_uses_database_autodownload_queue_and_releases_lock_after_worker_completion(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('torrenting.enabled', true);
        $settings->set('torrenting.autodownload', true);

        $lifecycle = app(AutoDownloadLifecycleService::class);

        $this->assertTrue($lifecycle->dispatchIfEligible());
        $this->assertFalse($lifecycle->dispatchIfEligible());

        $this->assertSame(
            1,
            DB::table('jobs')->where('queue', AutoDownloadLifecycleService::QUEUE)->count()
        );
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('cache_locks')->count());

        // Isolate queue/lock/worker lifecycle from external torrent/search I/O.
        $settings->set('torrenting.autodownload', false);

        $exitCode = Artisan::call('queue:work', [
            'connection' => AutoDownloadLifecycleService::CONNECTION,
            '--queue' => AutoDownloadLifecycleService::QUEUE,
            '--once' => true,
            '--sleep' => 0,
            '--tries' => 1,
            '--timeout' => 75,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('cache_locks')->count());

        $settings->set('torrenting.autodownload', true);

        $this->assertTrue($lifecycle->dispatchIfEligible());
        $this->assertSame(
            1,
            DB::table('jobs')->where('queue', AutoDownloadLifecycleService::QUEUE)->count()
        );
        $this->assertSame(1, DB::table('cache_locks')->count());
    }
}
