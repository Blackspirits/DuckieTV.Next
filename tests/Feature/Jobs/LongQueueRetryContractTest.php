<?php

use App\Jobs\PruneAutoDLActivitiesJob;
use App\Jobs\RestoreBackupJob;
use App\Jobs\RestoreShowJob;
use App\Jobs\TraktUpdateJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('keeps short autodownload and long default reservation windows independent', function () {
    config([
        'queue.connections.database.retry_after' => 90,
        'queue.connections.database_long.retry_after' => 3660,
    ]);

    $now = time();

    DB::table('jobs')->insert([
        [
            'queue' => 'autodownload',
            'payload' => '{}',
            'attempts' => 1,
            'reserved_at' => $now - 120,
            'available_at' => $now - 120,
            'created_at' => $now - 180,
        ],
        [
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 1,
            'reserved_at' => $now - 120,
            'available_at' => $now - 120,
            'created_at' => $now - 180,
        ],
    ]);

    expect(Queue::connection('database')->pop('autodownload'))->not->toBeNull()
        ->and(Queue::connection('database_long')->pop('default'))->toBeNull();

    DB::table('jobs')->where('queue', 'default')->update([
        'reserved_at' => $now - 3700,
    ]);

    expect(Queue::connection('database_long')->pop('default'))->not->toBeNull();
});

it('routes every default queue job to the long queue connection', function () {
    $restoreBackup = new RestoreBackupJob([]);
    $restoreShow = new RestoreShowJob('123', []);
    $trakt = new TraktUpdateJob;
    $prune = new PruneAutoDLActivitiesJob;

    expect($restoreBackup->connection)->toBe('database_long')
        ->and($restoreShow->connection)->toBe('database_long')
        ->and($trakt->connection)->toBe('database_long')
        ->and($prune->connection)->toBe('database_long')
        ->and(config('queue.connections.database.retry_after'))->toBe(90)
        ->and(config('queue.connections.database_long.retry_after'))->toBeGreaterThan($restoreBackup->timeout)
        ->and(config('queue.connections.database_long.retry_after'))->toBeGreaterThan($restoreShow->timeout)
        ->and(config('queue.connections.database_long.retry_after'))->toBeGreaterThan($trakt->timeout);
});
