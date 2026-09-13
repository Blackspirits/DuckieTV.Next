<?php

namespace Tests\Feature\Console;

use PDO;
use Tests\TestCase;

class FixDatabaseCommandTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databasePath = database_path('fix-database-command-test.sqlite');
        @unlink($this->databasePath);
        @unlink($this->databasePath.'-wal');
        @unlink($this->databasePath.'-shm');
        touch($this->databasePath);

        config([
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.nativephp' => [
                'driver' => 'sqlite',
                'database' => $this->databasePath,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'queue.connections.database.retry_after' => 90,
        ]);

        $pdo = new PDO('sqlite:'.$this->databasePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                queue VARCHAR NOT NULL,
                payload TEXT NOT NULL,
                attempts INTEGER NOT NULL,
                reserved_at INTEGER NULL,
                available_at INTEGER NOT NULL,
                created_at INTEGER NOT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE failed_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid VARCHAR NOT NULL UNIQUE,
                connection TEXT NOT NULL,
                queue TEXT NOT NULL,
                payload TEXT NOT NULL,
                exception TEXT NOT NULL,
                failed_at TEXT NOT NULL
            )'
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->databasePath);
        @unlink($this->databasePath.'-wal');
        @unlink($this->databasePath.'-shm');

        parent::tearDown();
    }

    public function test_it_releases_only_expired_reservations_and_preserves_attempts(): void
    {
        $pdo = new PDO('sqlite:'.$this->databasePath);
        $now = time();

        $insert = $pdo->prepare(
            'INSERT INTO jobs (queue, payload, attempts, reserved_at, available_at, created_at)
             VALUES (:queue, :payload, :attempts, :reserved_at, :available_at, :created_at)'
        );

        $insert->execute([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 2,
            'reserved_at' => $now - 5,
            'available_at' => $now - 5,
            'created_at' => $now - 60,
        ]);
        $freshId = (int) $pdo->lastInsertId();

        $insert->execute([
            'queue' => 'autodownload',
            'payload' => '{}',
            'attempts' => 1,
            'reserved_at' => $now - 120,
            'available_at' => $now - 120,
            'created_at' => $now - 180,
        ]);
        $staleId = (int) $pdo->lastInsertId();

        $insert->execute([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $now,
            'created_at' => $now,
        ]);
        $pendingId = (int) $pdo->lastInsertId();

        $this->artisan('duckietv:fix-database')->assertExitCode(0);

        $rows = $pdo->query('SELECT id, attempts, reserved_at FROM jobs ORDER BY id')
            ->fetchAll(PDO::FETCH_ASSOC);

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }

        $this->assertNotNull($byId[$freshId]['reserved_at']);
        $this->assertSame(2, (int) $byId[$freshId]['attempts']);

        $this->assertNull($byId[$staleId]['reserved_at']);
        $this->assertSame(1, (int) $byId[$staleId]['attempts']);

        $this->assertNull($byId[$pendingId]['reserved_at']);
        $this->assertSame(0, (int) $byId[$pendingId]['attempts']);
    }
}
