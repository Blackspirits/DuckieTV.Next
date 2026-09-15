<?php

namespace App\Console\Commands\DuckieTV;

use Illuminate\Console\Command;

class FixDatabaseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'duckietv:fix-database
                            {--flush : Flush all pending and failed jobs}
                            {--force : Skip confirmations}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Diagnose and fix SQLite database locking issues (enables WAL mode, releases expired queue reservations)';

    /**
     * The SQLite database files to check.
     */
    private array $databases = [];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->components->info('DuckieTV Database Recovery Tool');
        $this->newLine();

        $this->discoverDatabases();

        if (empty($this->databases)) {
            $this->components->error('No SQLite database files found.');

            return self::FAILURE;
        }

        $hasErrors = false;

        foreach ($this->databases as $label => $path) {
            if (! $this->fixDatabase($label, $path)) {
                $hasErrors = true;
            }
        }

        $this->newLine();
        $this->handleStuckJobs();

        $this->newLine();
        if ($hasErrors) {
            $this->components->warn('Recovery completed with warnings. Check the output above.');
        } else {
            $this->components->info('All databases are healthy. You can now start the app.');
        }

        return $hasErrors ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Discover all SQLite database files used by the application.
     */
    private function discoverDatabases(): void
    {
        // Main app database
        $appDb = config('database.connections.sqlite.database');
        if ($appDb && file_exists($appDb)) {
            $this->databases['App (sqlite)'] = $appDb;
        }

        // NativePHP internal database (queue jobs, batches)
        $nativeDb = config('database.connections.nativephp.database');
        if ($nativeDb && file_exists($nativeDb)) {
            $this->databases['NativePHP (nativephp)'] = $nativeDb;
        }

        // Fallback: check the standard path if config doesn't resolve
        if (! isset($this->databases['NativePHP (nativephp)'])) {
            $fallback = database_path('nativephp.sqlite');
            if (file_exists($fallback)) {
                $this->databases['NativePHP (nativephp)'] = $fallback;
            }
        }

        $this->components->twoColumnDetail('Databases found', (string) count($this->databases));

        foreach ($this->databases as $label => $path) {
            $this->components->twoColumnDetail("  {$label}", $path);
        }
    }

    /**
     * Fix a single SQLite database: enable WAL, set busy_timeout, check integrity.
     */
    private function fixDatabase(string $label, string $path): bool
    {
        $this->newLine();
        $this->components->task("Checking {$label}", function () {
            return true; // Just a visual header
        });

        $ok = true;

        try {
            $pdo = new \PDO("sqlite:{$path}");
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            // 1. Check current journal mode
            $journalMode = $pdo->query('PRAGMA journal_mode')->fetchColumn();

            if (strtolower($journalMode) !== 'wal') {
                $result = $pdo->query('PRAGMA journal_mode = WAL')->fetchColumn();
                if (strtolower($result) === 'wal') {
                    $this->components->twoColumnDetail('  Journal mode', '<fg=green>Switched to WAL ✓</>');
                } else {
                    $this->components->twoColumnDetail('  Journal mode', "<fg=red>Failed to enable WAL (got: {$result})</>");
                    $ok = false;
                }
            } else {
                $this->components->twoColumnDetail('  Journal mode', '<fg=green>WAL ✓</>');
            }

            // 2. Set busy_timeout
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $this->components->twoColumnDetail('  Busy timeout', '<fg=green>5000ms ✓</>');

            // 3. Set synchronous mode
            $pdo->exec('PRAGMA synchronous = NORMAL');
            $this->components->twoColumnDetail('  Synchronous', '<fg=green>NORMAL ✓</>');

            // 4. Integrity check
            $integrity = $pdo->query('PRAGMA integrity_check')->fetchColumn();
            if ($integrity === 'ok') {
                $this->components->twoColumnDetail('  Integrity check', '<fg=green>OK ✓</>');
            } else {
                $this->components->twoColumnDetail('  Integrity check', "<fg=red>FAILED: {$integrity}</>");
                $this->components->warn('  Database may be corrupted. Consider restoring from a backup.');
                $ok = false;
            }

            // 5. Check for stale WAL file size (can indicate unclean shutdown)
            $walFile = $path.'-wal';
            if (file_exists($walFile)) {
                $walSize = filesize($walFile);
                $walSizeHuman = $this->humanFilesize($walSize);
                $this->components->twoColumnDetail('  WAL file size', $walSizeHuman);

                if ($walSize > 10 * 1024 * 1024) { // > 10MB
                    $this->components->warn('  Large WAL file detected. Running checkpoint...');
                    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
                    $this->components->twoColumnDetail('  WAL checkpoint', '<fg=green>Compacted ✓</>');
                }
            }

            $pdo = null; // Close connection

        } catch (\PDOException $e) {
            $this->components->error("  Failed to open database: {$e->getMessage()}");
            $ok = false;
        }

        return $ok;
    }

    /**
     * Handle stuck and failed jobs in the NativePHP queue database.
     */
    private function handleStuckJobs(): void
    {
        $nativeDb = $this->databases['NativePHP (nativephp)'] ?? null;

        if (! $nativeDb) {
            $this->components->info('No NativePHP database found, skipping job cleanup.');

            return;
        }

        $this->components->task('Checking queue jobs', function () {
            return true;
        });

        try {
            $pdo = new \PDO("sqlite:{$nativeDb}");
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            // Check if jobs table exists
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='jobs'")->fetchColumn();
            if (! $tables) {
                $this->components->twoColumnDetail('  Jobs table', 'Not found (clean state)');

                return;
            }

            // Match Laravel's database queue reservation expiry rule. A fresh
            // reservation may still belong to a live worker and must never be
            // released by this recovery command.
            $retryAfter = max(1, (int) config('queue.connections.database.retry_after', 90));
            $staleBefore = time() - $retryAfter;

            $staleStatement = $pdo->prepare(
                'SELECT COUNT(*) FROM jobs WHERE reserved_at IS NOT NULL AND reserved_at <= :stale_before'
            );
            $staleStatement->execute(['stale_before' => $staleBefore]);
            $staleCount = (int) $staleStatement->fetchColumn();

            $activeStatement = $pdo->prepare(
                'SELECT COUNT(*) FROM jobs WHERE reserved_at IS NOT NULL AND reserved_at > :stale_before'
            );
            $activeStatement->execute(['stale_before' => $staleBefore]);
            $activeCount = (int) $activeStatement->fetchColumn();

            $pendingCount = (int) $pdo->query('SELECT COUNT(*) FROM jobs WHERE reserved_at IS NULL')->fetchColumn();
            $failedCount = 0;

            $failedTableExists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='failed_jobs'")->fetchColumn();
            if ($failedTableExists) {
                $failedCount = (int) $pdo->query('SELECT COUNT(*) FROM failed_jobs')->fetchColumn();
            }

            $this->components->twoColumnDetail('  Active reserved jobs', (string) $activeCount);
            $this->components->twoColumnDetail(
                "  Expired reservations (>{$retryAfter}s)",
                (string) $staleCount
            );
            $this->components->twoColumnDetail('  Pending jobs', (string) $pendingCount);
            $this->components->twoColumnDetail('  Failed jobs', (string) $failedCount);

            // Release only reservations Laravel itself considers expired.
            // Preserve attempts: resetting it would grant already-attempted work
            // a fresh retry budget and break queue max-attempt semantics.
            if ($staleCount > 0) {
                $releaseStatement = $pdo->prepare(
                    'UPDATE jobs SET reserved_at = NULL WHERE reserved_at IS NOT NULL AND reserved_at <= :stale_before'
                );
                $releaseStatement->execute(['stale_before' => $staleBefore]);

                $this->components->twoColumnDetail(
                    '  Released expired reservations',
                    "<fg=green>{$staleCount} released ✓</>"
                );
            }

            $reservedCount = $activeCount + $staleCount;

            // Flush all jobs if explicitly requested. This remains deliberately
            // destructive and may include active reservations after confirmation.
            if ($this->option('flush') && ($pendingCount + $reservedCount + $failedCount > 0)) {
                $shouldFlush = $this->option('force') || $this->confirm(
                    "Flush all {$pendingCount} pending, {$reservedCount} reserved, and {$failedCount} failed jobs?",
                    false
                );

                if ($shouldFlush) {
                    $pdo->exec('DELETE FROM jobs');

                    if ($failedTableExists) {
                        $pdo->exec('DELETE FROM failed_jobs');
                    }

                    $batchTableExists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='job_batches'")->fetchColumn();
                    if ($batchTableExists) {
                        $pdo->exec('DELETE FROM job_batches');
                    }

                    $this->components->twoColumnDetail('  Flushed all jobs', '<fg=green>Done ✓</>');
                }
            } elseif (! $this->option('flush') && ($staleCount + $failedCount > 0)) {
                $this->line('');
                $this->line('  <fg=yellow>Tip:</> Run with <fg=white>--flush</> to also clear all pending and failed jobs.');
            }

            $pdo = null;

        } catch (\PDOException $e) {
            $this->components->error("  Failed to check jobs: {$e->getMessage()}");
        }
    }

    /**
     * Format bytes into a human-readable string.
     */
    private function humanFilesize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 1).' '.$units[$i];
    }
}
