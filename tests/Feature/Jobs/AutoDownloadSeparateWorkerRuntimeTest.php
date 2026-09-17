<?php

namespace Tests\Feature\Jobs;

use App\Services\AutoDownloadLifecycleService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AutoDownloadSeparateWorkerRuntimeTest extends TestCase
{
    private ?string $databasePath = null;

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        DB::purge('sqlite');

        if ($this->databasePath !== null && is_file($this->databasePath)) {
            @unlink($this->databasePath);
        }

        parent::tearDown();
    }

    public function test_separate_php_worker_consumes_lifecycle_job_and_releases_database_lock(): void
    {
        $this->databasePath = tempnam(sys_get_temp_dir(), 'duckietv-autodl-');
        $this->assertNotFalse($this->databasePath);

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->databasePath,
            'queue.default' => 'database',
            'queue.connections.database.connection' => 'sqlite',
            'cache.default' => 'database',
            'cache.stores.database.connection' => 'sqlite',
            'cache.stores.database.lock_connection' => 'sqlite',
        ]);

        DB::purge('sqlite');
        app('cache')->forgetDriver('database');
        app('cache')->setDefaultDriver('database');

        $this->assertSame(0, Artisan::call('migrate:fresh', [
            '--database' => 'sqlite',
            '--force' => true,
        ]));

        $settings = app(SettingsService::class);
        $settings->set('torrenting.enabled', true);
        $settings->set('torrenting.autodownload', true);

        $lifecycle = app(AutoDownloadLifecycleService::class);
        $this->assertTrue($lifecycle->dispatchIfEligible());

        $this->assertSame(
            1,
            DB::table('jobs')->where('queue', AutoDownloadLifecycleService::QUEUE)->count()
        );
        $this->assertSame(1, DB::table('cache_locks')->count());

        // Keep this test focused on process/queue/lock behavior. The separate
        // worker sees the same persisted settings database and therefore
        // executes the queued wrapper without external search/client I/O.
        $settings->set('torrenting.autodownload', false);

        $worker = new Process([
            PHP_BINARY,
            base_path('artisan'),
            'queue:work',
            AutoDownloadLifecycleService::CONNECTION,
            '--once',
            '--queue='.AutoDownloadLifecycleService::QUEUE,
            '--tries=1',
            '--timeout=75',
            '--sleep=0',
        ], base_path());

        $worker->setEnv([
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $this->databasePath,
            'DB_QUEUE_CONNECTION' => 'sqlite',
            'DB_CACHE_CONNECTION' => 'sqlite',
            'DB_CACHE_LOCK_CONNECTION' => 'sqlite',
            'QUEUE_CONNECTION' => 'database',
            'CACHE_STORE' => 'database',
        ]);
        $worker->setTimeout(30);
        $worker->run();

        $this->assertTrue(
            $worker->isSuccessful(),
            "Separate queue worker failed:\n".$worker->getErrorOutput().$worker->getOutput()
        );
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('cache_locks')->count());

        // Re-enable through the same lifecycle path. If the child process did
        // not release the serialized ShouldBeUnique owner, this dispatch would
        // remain suppressed until the uniqueness lease expired.
        $settings->set('torrenting.autodownload', true);

        $this->assertTrue($lifecycle->dispatchIfEligible());
        $this->assertSame(
            1,
            DB::table('jobs')->where('queue', AutoDownloadLifecycleService::QUEUE)->count()
        );
        $this->assertSame(1, DB::table('cache_locks')->count());
    }
}
