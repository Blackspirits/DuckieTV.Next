<?php

namespace Tests\Feature\Jobs;

use App\Services\AutoDownloadLifecycleService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AutoDownloadCrashRecoveryTest extends TestCase
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

    public function test_stale_reserved_row_recovers_itself_without_allowing_a_new_dispatch_to_steal_uniqueness(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('torrenting.enabled', true);
        $settings->set('torrenting.autodownload', true);

        $lifecycle = app(AutoDownloadLifecycleService::class);
        $this->assertTrue($lifecycle->dispatchIfEligible());

        $queue = app('queue')->connection('database');
        $reservedJob = $queue->pop(AutoDownloadLifecycleService::QUEUE);

        $this->assertNotNull($reservedJob);
        $this->assertSame(1, $reservedJob->attempts());
        $this->assertSame(1, DB::table('jobs')->where('queue', AutoDownloadLifecycleService::QUEUE)->count());
        $this->assertSame(1, DB::table('cache_locks')->count());

        // Simulate the worker process dying and the app remaining unavailable
        // beyond both retry_after (90s) and the finite uniqueness lease (120s).
        DB::table('jobs')
            ->where('queue', AutoDownloadLifecycleService::QUEUE)
            ->update(['reserved_at' => now()->subSeconds(121)->timestamp]);
        DB::table('cache_locks')->update(['expiration' => now()->subSecond()->timestamp]);

        // Startup/reconnect must preserve the persisted stale row as the one
        // recovery unit instead of creating a second job with a new lock owner.
        $this->assertFalse($lifecycle->dispatchIfEligible());
        $this->assertSame(1, DB::table('jobs')->where('queue', AutoDownloadLifecycleService::QUEUE)->count());

        // Make business work a no-op: this test targets queue crash recovery.
        $settings->set('torrenting.autodownload', false);

        $exitCode = Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => AutoDownloadLifecycleService::QUEUE,
            '--once' => true,
            '--sleep' => 0,
            '--tries' => 1,
            '--timeout' => 75,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, DB::table('jobs')->where('queue', AutoDownloadLifecycleService::QUEUE)->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('cache_locks')->count());

        $settings->set('torrenting.autodownload', true);
        $this->assertTrue($lifecycle->dispatchIfEligible());
        $this->assertSame(1, DB::table('jobs')->where('queue', AutoDownloadLifecycleService::QUEUE)->count());
    }

    public function test_two_consecutive_crashes_exhaust_the_persisted_row_before_a_fresh_dispatch_is_allowed(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('torrenting.enabled', true);
        $settings->set('torrenting.autodownload', true);

        $lifecycle = app(AutoDownloadLifecycleService::class);
        $this->assertTrue($lifecycle->dispatchIfEligible());

        $queue = app('queue')->connection('database');

        // Crash #1: the first worker reserves the row and disappears.
        $firstAttempt = $queue->pop(AutoDownloadLifecycleService::QUEUE);
        $this->assertNotNull($firstAttempt);
        $this->assertSame(1, $firstAttempt->attempts());

        DB::table('jobs')
            ->where('queue', AutoDownloadLifecycleService::QUEUE)
            ->update(['reserved_at' => now()->subSeconds(121)->timestamp]);
        DB::table('cache_locks')->update(['expiration' => now()->subSecond()->timestamp]);

        // Even after retry_after and uniqueFor have expired, the persisted row
        // remains the sole recovery unit and blocks a competing lifecycle dispatch.
        $this->assertFalse($lifecycle->dispatchIfEligible());
        $this->assertSame(1, DB::table('jobs')->where('queue', AutoDownloadLifecycleService::QUEUE)->count());

        // Crash #2: the recovery worker re-reserves the same row on attempt 2
        // and also disappears before completing the scan.
        $secondAttempt = $queue->pop(AutoDownloadLifecycleService::QUEUE);
        $this->assertNotNull($secondAttempt);
        $this->assertSame(2, $secondAttempt->attempts());

        DB::table('jobs')
            ->where('queue', AutoDownloadLifecycleService::QUEUE)
            ->update(['reserved_at' => now()->subSeconds(121)->timestamp]);
        DB::table('cache_locks')->update(['expiration' => now()->subSecond()->timestamp]);

        $this->assertFalse($lifecycle->dispatchIfEligible());
        $this->assertSame(1, DB::table('jobs')->where('queue', AutoDownloadLifecycleService::QUEUE)->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());

        // The next reservation exceeds AutoDownloadJob::$tries=2. Laravel must
        // fail and remove the exhausted persisted row before a fresh trigger can
        // become the next recovery unit.
        $exitCode = Artisan::call('queue:work', [
            'connection' => AutoDownloadLifecycleService::CONNECTION,
            '--queue' => AutoDownloadLifecycleService::QUEUE,
            '--once' => true,
            '--sleep' => 0,
            '--tries' => 1,
            '--timeout' => 75,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, DB::table('jobs')->where('queue', AutoDownloadLifecycleService::QUEUE)->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('cache_locks')->count());

        $this->assertTrue($lifecycle->dispatchIfEligible());
        $this->assertSame(1, DB::table('jobs')->where('queue', AutoDownloadLifecycleService::QUEUE)->count());
        $this->assertSame(1, DB::table('cache_locks')->count());
    }
}
