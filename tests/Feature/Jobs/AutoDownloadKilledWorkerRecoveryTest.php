<?php

namespace Tests\Feature\Jobs;

use App\Services\AutoDownloadLifecycleService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AutoDownloadKilledWorkerRecoveryTest extends TestCase
{
    private ?string $databasePath = null;

    private ?string $serverLogPath = null;

    private ?Process $server = null;

    private ?Process $worker = null;

    protected function tearDown(): void
    {
        if ($this->worker !== null && $this->worker->isRunning()) {
            $this->worker->stop(0, 9);
        }

        if ($this->server !== null && $this->server->isRunning()) {
            $this->server->stop(1);
        }

        DB::disconnect('sqlite');
        DB::purge('sqlite');

        foreach ([$this->databasePath, $this->serverLogPath] as $path) {
            if ($path !== null && is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_killed_worker_leaves_one_reserved_row_that_a_new_process_recovers_on_attempt_two(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('This evidence probe requires POSIX process signalling.');
        }

        $this->databasePath = tempnam(sys_get_temp_dir(), 'duckietv-autodl-kill-db-');
        $this->serverLogPath = tempnam(sys_get_temp_dir(), 'duckietv-autodl-kill-http-');

        $this->assertNotFalse($this->databasePath);
        $this->assertNotFalse($this->serverLogPath);

        $port = $this->reserveLocalPort();
        $this->configureSharedDatabase();

        $this->assertSame(0, Artisan::call('migrate:fresh', [
            '--database' => 'sqlite',
            '--force' => true,
        ]));

        $settings = app(SettingsService::class);
        $settings->set('torrenting.enabled', true);
        $settings->set('torrenting.autodownload', true);
        $settings->set('torrenting.client', 'qBittorrent 4.1+');
        $settings->set('qbittorrent32plus.server', 'http://127.0.0.1');
        $settings->set('qbittorrent32plus.port', $port);
        $settings->set('qbittorrent32plus.username', 'duckie');
        $settings->set('qbittorrent32plus.password', 'secret');
        $settings->set('qbittorrent32plus.use_auth', true);

        $this->startBlockingServer($port);

        $lifecycle = app(AutoDownloadLifecycleService::class);
        $this->assertTrue($lifecycle->dispatchIfEligible());
        $this->assertSame(1, $this->autoDownloadJobCount());

        $this->worker = $this->makeWorker();
        $this->worker->start();

        $this->waitUntil(function (): bool {
            $reservedAt = DB::table('jobs')
                ->where('queue', AutoDownloadLifecycleService::QUEUE)
                ->value('reserved_at');

            return $reservedAt !== null && $this->serverLogPath !== null
                && is_file($this->serverLogPath)
                && filesize($this->serverLogPath) > 0;
        }, 'Worker did not reserve AutoDL and enter the blocking HTTP call.');

        $rowBeforeKill = DB::table('jobs')
            ->where('queue', AutoDownloadLifecycleService::QUEUE)
            ->first();

        $this->assertNotNull($rowBeforeKill);
        $this->assertSame(1, (int) $rowBeforeKill->attempts);
        $this->assertNotNull($rowBeforeKill->reserved_at);

        // A running unique job owns two independent locks: the finite
        // ShouldBeUnique dispatch lease and the shorter execution-overlap lock.
        $locksBeforeKill = DB::table('cache_locks')->orderBy('expiration')->get();
        $this->assertCount(2, $locksBeforeKill);

        $overlapLock = $locksBeforeKill->first();
        $uniqueLock = $locksBeforeKill->last();

        $this->assertNotNull($overlapLock);
        $this->assertNotNull($uniqueLock);
        $this->assertNotSame($overlapLock->key, $uniqueLock->key);
        $this->assertGreaterThan(
            (int) $overlapLock->expiration,
            (int) $uniqueLock->expiration
        );
        $this->assertGreaterThanOrEqual(
            20,
            (int) $uniqueLock->expiration - (int) $overlapLock->expiration
        );

        // Kill the actual queue worker while AutoDownloadService is blocked in
        // real localhost HTTP I/O. No Laravel success/failure cleanup can run.
        $this->worker->stop(0, 9);
        $this->assertFalse($this->worker->isRunning());

        $rowAfterKill = DB::table('jobs')
            ->where('queue', AutoDownloadLifecycleService::QUEUE)
            ->first();

        $this->assertNotNull($rowAfterKill);
        $this->assertSame((int) $rowBeforeKill->id, (int) $rowAfterKill->id);
        $this->assertSame(1, (int) $rowAfterKill->attempts);
        $this->assertNotNull($rowAfterKill->reserved_at);
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(2, DB::table('cache_locks')->count());

        // Compress 91 seconds of wall time. At real retry_after=90 the 85-second
        // overlap lock has expired, while the 120-second uniqueness lease is still
        // valid. Age the reservation and those two TTLs consistently.
        DB::table('jobs')
            ->where('id', $rowAfterKill->id)
            ->update(['reserved_at' => now()->subSeconds(91)->timestamp]);
        DB::table('cache_locks')
            ->where('key', $overlapLock->key)
            ->update(['expiration' => now()->subSecond()->timestamp]);
        DB::table('cache_locks')
            ->where('key', $uniqueLock->key)
            ->update(['expiration' => now()->addSeconds(29)->timestamp]);

        // A lifecycle trigger still cannot create a competing recovery row even
        // though the dispatch lease is finite: the persisted queue row is the gate.
        $this->assertFalse($lifecycle->dispatchIfEligible());
        $this->assertSame(1, $this->autoDownloadJobCount());

        // Prevent external I/O on the recovery execution. The exact same queued
        // payload is retried; only persisted feature eligibility changes.
        $settings->set('torrenting.autodownload', false);

        $recoveryWorker = $this->makeWorker();
        $recoveryWorker->setTimeout(30);
        $recoveryWorker->run();

        $this->assertTrue(
            $recoveryWorker->isSuccessful(),
            "Recovery worker failed:\n".$recoveryWorker->getErrorOutput().$recoveryWorker->getOutput()
        );
        $this->assertSame(0, $this->autoDownloadJobCount());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('cache_locks')->count());

        $settings->set('torrenting.autodownload', true);

        $this->assertTrue($lifecycle->dispatchIfEligible());
        $this->assertSame(1, $this->autoDownloadJobCount());
        $this->assertSame(1, DB::table('cache_locks')->count());
    }

    private function configureSharedDatabase(): void
    {
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
    }

    private function startBlockingServer(int $port): void
    {
        $router = base_path('tests/Fixtures/QBittorrentWebApi/blocking-router.php');

        $this->server = new Process([
            PHP_BINARY,
            '-S',
            '127.0.0.1:'.$port,
            $router,
        ], base_path(), [
            'QBITTORRENT_BLOCKING_LOG' => $this->serverLogPath,
        ]);
        $this->server->start();

        $this->waitUntil(function () use ($port): bool {
            $socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.05);
            if (! is_resource($socket)) {
                return false;
            }

            fclose($socket);

            return true;
        }, 'Blocking qBittorrent emulator did not start.');
    }

    private function makeWorker(): Process
    {
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

        return $worker;
    }

    private function autoDownloadJobCount(): int
    {
        return DB::table('jobs')
            ->where('queue', AutoDownloadLifecycleService::QUEUE)
            ->count();
    }

    private function waitUntil(callable $condition, string $failureMessage): void
    {
        for ($attempt = 0; $attempt < 250; $attempt++) {
            clearstatcache();

            if ($condition()) {
                return;
            }

            usleep(20_000);
        }

        $this->fail($failureMessage);
    }

    private function reserveLocalPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        $this->assertNotFalse($socket, "Unable to reserve local port: {$errorCode} {$errorMessage}");

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        $this->assertIsString($name);
        $port = (int) substr(strrchr($name, ':'), 1);
        $this->assertGreaterThan(0, $port);

        return $port;
    }
}
