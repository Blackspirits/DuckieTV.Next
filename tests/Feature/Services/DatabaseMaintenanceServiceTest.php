<?php

namespace Tests\Feature\Services;

use App\Models\AutoDownloadActivity;
use App\Models\Episode;
use App\Models\Jackett;
use App\Models\Season;
use App\Models\Serie;
use App\Services\DatabaseMaintenanceService;
use App\Services\FavoritesService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class DatabaseMaintenanceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_wipe_user_database_deletes_user_state_and_preserves_protected_settings_and_infrastructure(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('database.version', '42');
        $settings->set('database.version.schema', '17');
        $settings->set('utorrent.token', 'keep-token');
        $settings->set('utorrent.token.cached', 'keep-cached-token');
        $settings->set('torrenting.client', 'Transmission');

        $serie = Serie::create([
            'name' => 'Old Show',
            'trakt_id' => 1001,
        ]);

        $season = Season::create([
            'serie_id' => $serie->id,
            'seasonnumber' => 1,
            'trakt_id' => 2001,
        ]);

        $episode = Episode::create([
            'serie_id' => $serie->id,
            'season_id' => $season->id,
            'seasonnumber' => 1,
            'episodenumber' => 1,
            'trakt_id' => 3001,
        ]);

        AutoDownloadActivity::create([
            'serie_id' => $serie->id,
            'episode_id' => $episode->id,
            'search' => 'Old Show s01e01',
            'status' => 4,
            'serie_name' => 'Old Show',
            'episode_formatted' => 's01e01',
            'timestamp' => now()->timestamp,
        ]);

        Jackett::create([
            'name' => 'Old Indexer',
            'enabled' => 1,
        ]);

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => 1,
            'created_at' => 1,
        ]);

        DB::table('job_batches')->insert([
            'id' => 'keep-batch',
            'name' => 'Keep batch',
            'total_jobs' => 1,
            'pending_jobs' => 1,
            'failed_jobs' => 0,
            'failed_job_ids' => '[]',
            'options' => null,
            'cancelled_at' => null,
            'created_at' => 1,
            'finished_at' => null,
        ]);

        DB::table('failed_jobs')->insert([
            'uuid' => 'keep-failed-job',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'test',
            'failed_at' => now(),
        ]);

        $migrationCount = DB::table('migrations')->count();

        // Prime singleton state so this proves the wipe refreshes all
        // settings-derived state used by later restore work.
        $this->assertSame('Transmission', $settings->get('torrenting.client'));

        $favorites = Mockery::mock(FavoritesService::class);
        $favorites->shouldReceive('resetCachedSettings')->once();
        $this->app->instance(FavoritesService::class, $favorites);

        app(DatabaseMaintenanceService::class)->wipeUserDatabase();

        $this->assertDatabaseCount('autodl_activities', 0);
        $this->assertDatabaseCount('episodes', 0);
        $this->assertDatabaseCount('seasons', 0);
        $this->assertDatabaseCount('series', 0);
        $this->assertDatabaseCount('jackett', 0);

        $this->assertDatabaseHas('settings', ['key' => 'database.version', 'value' => '42']);
        $this->assertDatabaseHas('settings', ['key' => 'database.version.schema', 'value' => '17']);
        $this->assertDatabaseHas('settings', ['key' => 'utorrent.token', 'value' => 'keep-token']);
        $this->assertDatabaseHas('settings', ['key' => 'utorrent.token.cached', 'value' => 'keep-cached-token']);
        $this->assertDatabaseMissing('settings', ['key' => 'torrenting.client']);

        $this->assertSame('uTorrent', $settings->get('torrenting.client'));

        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseHas('job_batches', ['id' => 'keep-batch']);
        $this->assertDatabaseHas('failed_jobs', ['uuid' => 'keep-failed-job']);
        $this->assertSame($migrationCount, DB::table('migrations')->count());
    }
}
