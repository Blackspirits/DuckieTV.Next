<?php

namespace Tests\Feature\Services;

use App\Models\Episode;
use App\Models\Jackett;
use App\Models\Serie;
use App\Services\BackupService;
use App\Services\FavoritesService;
use App\Services\SettingsService;
use App\Services\TraktService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class BackupRoundTripTest extends TestCase
{
    use RefreshDatabase;

    public function test_created_backup_round_trips_custom_series_and_jackett_state(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('torrenting.client', 'Transmission');
        $settings->set('trakttv.lastupdated.trending', 123456789);

        $serie = Serie::create([
            'name' => 'Round Trip',
            'trakt_id' => 9001,
            'tvdb_id' => 8001,
            'displaycalendar' => false,
            'autoDownload' => false,
            'customSearchString' => 'PROPER 1080p',
            'ignoreGlobalQuality' => true,
            'ignoreGlobalIncludes' => true,
            'ignoreGlobalExcludes' => true,
            'searchProvider' => 'Nyaa',
            'ignoreHideSpecials' => true,
            'customSearchSizeMin' => 250,
            'customSearchSizeMax' => 2500,
            'dlPath' => '/shows/round-trip',
            'customDelay' => 45,
            'alias' => 'Round.Trip',
            'customFormat' => 's{season}e{episode}',
            'customIncludes' => 'proper',
            'customExcludes' => null,
            'customSeeders' => 12,
        ]);

        Episode::create([
            'serie_id' => $serie->id,
            'seasonnumber' => 1,
            'episodenumber' => 1,
            'trakt_id' => 9101,
            'tvdb_id' => 8101,
            'downloaded' => 1,
            'watchedAt' => null,
        ]);

        Episode::create([
            'serie_id' => $serie->id,
            'seasonnumber' => 1,
            'episodenumber' => 2,
            'trakt_id' => 9102,
            'tvdb_id' => 8102,
            'downloaded' => 0,
            'watchedAt' => 1700000000000,
        ]);

        Episode::create([
            'serie_id' => $serie->id,
            'seasonnumber' => 1,
            'episodenumber' => 3,
            'trakt_id' => 9103,
            'tvdb_id' => 8103,
            'downloaded' => 0,
            'watchedAt' => null,
        ]);

        Jackett::create([
            'name' => 'Indexer A',
            'torznab' => 'https://example.test/api',
            'enabled' => 1,
            'torznabEnabled' => 1,
            'apiKey' => 'secret-key',
            'json' => ['caps' => ['tv' => true]],
        ]);

        $favorites = Mockery::mock(FavoritesService::class);
        $trakt = Mockery::mock(TraktService::class);
        $service = new BackupService($settings, $favorites, $trakt);

        $backup = $service->createBackup();

        $this->assertTrue($backup['settings']['useTrakt_id']);
        $this->assertArrayNotHasKey('trakttv.lastupdated.trending', $backup['settings']);
        $this->assertSame('Transmission', $backup['settings']['torrenting.client']);
        $this->assertCount(3, $backup['series']['9001']);
        $this->assertSame(9101, $backup['series']['9001'][1]['TRAKT_ID']);
        $this->assertSame(9102, $backup['series']['9001'][2]['TRAKT_ID']);

        $jackettBackup = json_decode($backup['settings']['jackett'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Indexer A', $jackettBackup[0]['name']);

        // Prove Jackett can be restored from the exact exported settings payload.
        Jackett::query()->delete();
        $service->restore(['settings' => $backup['settings']]);
        $this->assertDatabaseHas('jackett', [
            'name' => 'Indexer A',
            'torznab' => 'https://example.test/api',
            'apiKey' => 'secret-key',
        ]);

        // Mutate every custom field, then restore the exported series payload.
        $serie->fill([
            'displaycalendar' => true,
            'autoDownload' => true,
            'customSearchString' => 'changed',
            'ignoreGlobalQuality' => false,
            'ignoreGlobalIncludes' => false,
            'ignoreGlobalExcludes' => false,
            'searchProvider' => 'ThePirateBay',
            'ignoreHideSpecials' => false,
            'customSearchSizeMin' => 1,
            'customSearchSizeMax' => 2,
            'dlPath' => '/changed',
            'customDelay' => 1,
            'alias' => 'Changed',
            'customFormat' => 'changed',
            'customIncludes' => 'changed',
            'customExcludes' => 'changed',
            'customSeeders' => 1,
        ])->save();

        $trakt->shouldReceive('withThrottling')
            ->once()
            ->with(Mockery::type('callable'))
            ->andReturnUsing(fn (callable $callback) => $callback());
        $trakt->shouldNotReceive('resolveID');
        $trakt->shouldReceive('serie')
            ->once()
            ->with('9001')
            ->andReturn(['title' => 'Round Trip', 'trakt_id' => 9001]);

        $favorites->shouldReceive('addFavorite')
            ->once()
            ->andReturnUsing(fn () => $serie->fresh());

        $service->restoreShow('9001', $backup['series']['9001'], null, true);

        $serie->refresh();

        $this->assertFalse($serie->displaycalendar);
        $this->assertFalse($serie->autoDownload);
        $this->assertSame('PROPER 1080p', $serie->customSearchString);
        $this->assertTrue($serie->ignoreGlobalQuality);
        $this->assertTrue($serie->ignoreGlobalIncludes);
        $this->assertTrue($serie->ignoreGlobalExcludes);
        $this->assertSame('Nyaa', $serie->searchProvider);
        $this->assertTrue($serie->ignoreHideSpecials);
        $this->assertSame(250, $serie->customSearchSizeMin);
        $this->assertSame(2500, $serie->customSearchSizeMax);
        $this->assertSame('/shows/round-trip', $serie->dlPath);
        $this->assertSame(45, $serie->customDelay);
        $this->assertSame('Round.Trip', $serie->alias);
        $this->assertSame('s{season}e{episode}', $serie->customFormat);
        $this->assertSame('proper', $serie->customIncludes);
        $this->assertNull($serie->customExcludes);
        $this->assertSame(12, $serie->customSeeders);
    }

    public function test_legacy_tvdb_key_is_resolved_before_fetching_series(): void
    {
        $settings = app(SettingsService::class);
        $favorites = Mockery::mock(FavoritesService::class);
        $trakt = Mockery::mock(TraktService::class);
        $service = new BackupService($settings, $favorites, $trakt);

        $serie = Serie::create([
            'name' => 'Legacy',
            'trakt_id' => 9001,
            'tvdb_id' => 8001,
        ]);

        $trakt->shouldReceive('withThrottling')
            ->once()
            ->with(Mockery::type('callable'))
            ->andReturnUsing(fn (callable $callback) => $callback());
        $trakt->shouldReceive('resolveID')
            ->once()
            ->with('8001', false)
            ->andReturn(['trakt_id' => 9001]);
        $trakt->shouldReceive('serie')
            ->once()
            ->with('9001')
            ->andReturn([
                'title' => 'Legacy',
                'trakt_id' => 9001,
                'tvdb_id' => 8001,
            ]);

        $favorites->shouldReceive('addFavorite')
            ->once()
            ->andReturnUsing(fn () => $serie->fresh());

        $this->assertTrue($service->restoreShow('8001', [[]], null, false));
    }
}
