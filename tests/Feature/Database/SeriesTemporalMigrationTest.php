<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeriesTemporalMigrationTest extends TestCase
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

    public function test_it_recovers_temporal_values_written_by_the_previous_date_casts(): void
    {
        $this->databasePath = tempnam(sys_get_temp_dir(), 'duckietv-series-temporal-');
        $this->assertNotFalse($this->databasePath);

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->databasePath,
        ]);

        DB::purge('sqlite');

        $this->assertSame(0, Artisan::call('migrate:fresh', [
            '--database' => 'sqlite',
            '--force' => true,
        ]));

        $path = 'database/migrations/2026_09_13_000001_repair_series_temporal_contract.php';

        $this->assertSame(0, Artisan::call('migrate:rollback', [
            '--database' => 'sqlite',
            '--path' => $path,
            '--force' => true,
        ]));

        DB::table('series')->insert([
            [
                'name' => 'Astronomical Future',
                'trakt_id' => 1001,
                'firstaired' => '55840-11-08 22:13:20',
                'added' => '52971-09-25 00:00:00',
                'lastupdated' => '2024-01-01T00:00:00.000Z',
            ],
            [
                'name' => 'Astronomical Past',
                'trakt_id' => 1002,
                'firstaired' => '-28032-01-07 00:00:00',
                'added' => 1700000000000,
                'lastupdated' => '2025-02-03T04:05:06.000Z',
            ],
            [
                'name' => 'Normal SQL Date',
                'trakt_id' => 1003,
                'firstaired' => '2008-01-20 00:00:00',
                'added' => '2024-01-01 00:00:00',
                'lastupdated' => null,
            ],
        ]);

        $this->assertSame(0, Artisan::call('migrate', [
            '--database' => 'sqlite',
            '--path' => $path,
            '--force' => true,
        ]));

        $future = DB::table('series')->where('trakt_id', 1001)->first();
        $past = DB::table('series')->where('trakt_id', 1002)->first();
        $normal = DB::table('series')->where('trakt_id', 1003)->first();

        $this->assertSame(1700000000000, (int) $future->firstaired);
        $this->assertSame(1609459200000, (int) $future->added);
        $this->assertSame('2024-01-01T00:00:00.000Z', $future->lastupdated);

        $this->assertSame(-946771200000, (int) $past->firstaired);
        $this->assertSame(1700000000000, (int) $past->added);
        $this->assertSame('2025-02-03T04:05:06.000Z', $past->lastupdated);

        $this->assertSame(1200787200000, (int) $normal->firstaired);
        $this->assertSame(1704067200000, (int) $normal->added);
    }
}
