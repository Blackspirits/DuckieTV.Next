<?php

namespace Tests\Feature\Services;

use App\Models\AutoDownloadActivity;
use App\Models\Episode;
use App\Models\Serie;
use App\Services\AutoDownloadService;
use App\Services\FavoritesService;
use App\Services\SceneNameResolverService;
use App\Services\SettingsService;
use App\Services\TorrentClients\TorrentClientInterface;
use App\Services\TorrentClientService;
use App\Services\TorrentSearchService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AutoDownloadPeriodicLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    public function test_expired_cooperative_deadline_stops_before_client_work_and_checkpoint(): void
    {
        Carbon::setTestNow('2026-09-12 12:00:00');
        [$service, $settings, , , $clients] = $this->makeService();
        $this->configureSettings($settings);

        $clients->shouldNotReceive('getActiveClient');
        $settings->shouldNotReceive('set');

        $service->check(now()->subSecond());

        $this->assertDatabaseCount('autodl_activities', 0);
    }

    public function test_deadline_expiring_after_remote_read_aborts_without_candidate_work_or_checkpoint(): void
    {
        Carbon::setTestNow('2026-09-12 12:00:00');
        $this->createEpisode(['firstaired' => now()->subHours(2)->getTimestampMs()]);

        [$service, $settings, $search, $sceneName, $clients] = $this->makeService();
        $this->configureSettings($settings);

        $client = Mockery::mock(TorrentClientInterface::class);
        $client->shouldReceive('connect')->once()->andReturn(true);
        $client->shouldReceive('getTorrents')->once()->andReturnUsing(function (): array {
            Carbon::setTestNow(now()->addSeconds(6));

            return [];
        });
        $client->shouldReceive('isConnected')->once()->andReturn(true);

        $clients->shouldReceive('getActiveClient')->once()->andReturn($client);
        $sceneName->shouldNotReceive('getSearchStringForEpisode');
        $search->shouldNotReceive('search');
        $settings->shouldNotReceive('set');

        $service->check(Carbon::parse('2026-09-12 12:00:05'));

        $this->assertDatabaseCount('autodl_activities', 0);
    }

    public function test_offline_gap_is_recovered_from_persisted_checkpoint_plus_overlap(): void
    {
        Carbon::setTestNow('2026-09-12 12:00:00');
        $lastRun = now()->subDays(3);
        $episode = $this->createEpisode(['firstaired' => now()->subDays(2)->getTimestampMs()]);

        [$service, $settings, $search, $sceneName, $clients] = $this->makeService();
        $this->configureSettings($settings, [
            'autodownload.lastrun' => $lastRun->getTimestampMs(),
            'autodownload.period' => 1,
        ]);

        $client = Mockery::mock(TorrentClientInterface::class);
        $client->shouldReceive('connect')->once()->andReturn(true);
        $client->shouldReceive('getTorrents')->once()->andReturn([]);
        $client->shouldReceive('isConnected')->times(4)->andReturn(true);

        $clients->shouldReceive('getActiveClient')->once()->andReturn($client);
        $sceneName->shouldReceive('getSearchStringForEpisode')
            ->once()
            ->withArgs(fn (Serie $serie, Episode $candidate): bool => $candidate->is($episode))
            ->andReturn('Offline Show s01e01');
        $search->shouldReceive('search')->once()->andReturn([]);
        $settings->shouldReceive('set')
            ->once()
            ->with('autodownload.lastrun', now()->getTimestampMs());

        $service->check(now()->addMinute());

        $this->assertDatabaseCount('autodl_activities', 1);
        $this->assertSame(
            AutoDownloadService::STATUS_NOTHING_FOUND,
            (int) AutoDownloadActivity::query()->firstOrFail()->status
        );
    }

    public function test_scan_to_is_captured_before_candidate_processing_and_does_not_expand_mid_run(): void
    {
        Carbon::setTestNow('2026-09-12 12:00:00');
        $capturedScanTo = now()->copy();
        $this->createEpisode([
            'episodenumber' => 1,
            'firstaired' => now()->subHours(2)->getTimestampMs(),
        ]);
        $futureEpisode = $this->createEpisode([
            'episodenumber' => 2,
            'firstaired' => now()->addSeconds(30)->getTimestampMs(),
        ]);

        [$service, $settings, $search, $sceneName, $clients] = $this->makeService();
        $this->configureSettings($settings);

        $client = Mockery::mock(TorrentClientInterface::class);
        $client->shouldReceive('connect')->once()->andReturn(true);
        $client->shouldReceive('getTorrents')->once()->andReturn([]);
        $client->shouldReceive('isConnected')->times(4)->andReturn(true);

        $clients->shouldReceive('getActiveClient')->once()->andReturn($client);
        $sceneName->shouldReceive('getSearchStringForEpisode')->once()->andReturn('Captured Show s01e01');
        $search->shouldReceive('search')->once()->andReturnUsing(function (): array {
            Carbon::setTestNow(now()->addMinute());

            return [];
        });
        $settings->shouldReceive('set')
            ->once()
            ->with('autodownload.lastrun', $capturedScanTo->getTimestampMs());

        $service->check($capturedScanTo->copy()->addMinutes(2));

        $this->assertNull($futureEpisode->fresh()->magnetHash);
    }

    public function test_canonical_service_preserves_base32_identity_regression_after_job_deduplication(): void
    {
        Carbon::setTestNow('2026-09-12 12:00:00');
        $episode = $this->createEpisode();

        [$service, $settings, $search, $sceneName, $clients] = $this->makeService();
        $this->configureSettings($settings);

        $base32 = 'AAISEM2EKVTHPCEZVK54ZXPO74ABCIRT';
        $canonical = '00112233445566778899aabbccddeeff00112233';
        $magnet = 'magnet:?xt=urn:btih:'.$base32;

        $client = Mockery::mock(TorrentClientInterface::class);
        $client->shouldReceive('connect')->once()->andReturn(true);
        $client->shouldReceive('isConnected')->once()->andReturn(true);
        $client->shouldReceive('addMagnet')
            ->once()
            ->with($magnet, null, 'DuckieTV')
            ->andReturnUsing(function () use ($episode): bool {
                $this->assertNull($episode->fresh()->magnetHash);

                return true;
            });

        $clients->shouldReceive('getActiveClient')->once()->andReturn($client);
        $sceneName->shouldReceive('getSearchStringForEpisode')->once()->andReturn('Base32 Show s01e01');
        $search->shouldReceive('search')->once()->andReturn([[
            'releasename' => 'Base32.Show.s01e01',
            'sizeBytes' => 500_000_000,
            'sizeParseError' => false,
            'seeders' => 100,
            'magnetUrl' => $magnet,
        ]]);

        $this->assertTrue($service->manualDownload($episode));
        $this->assertSame($canonical, $episode->fresh()->magnetHash);
    }

    /**
     * @return array{AutoDownloadService, \Mockery\MockInterface, \Mockery\MockInterface, \Mockery\MockInterface, \Mockery\MockInterface}
     */
    protected function makeService(): array
    {
        $settings = Mockery::mock(SettingsService::class);
        $favorites = Mockery::mock(FavoritesService::class);
        $search = Mockery::mock(TorrentSearchService::class);
        $sceneName = Mockery::mock(SceneNameResolverService::class);
        $clients = Mockery::mock(TorrentClientService::class);

        return [
            new AutoDownloadService($settings, $favorites, $search, $sceneName, $clients),
            $settings,
            $search,
            $sceneName,
            $clients,
        ];
    }

    protected function configureSettings($settings, array $overrides = []): void
    {
        $values = array_merge([
            'torrenting.enabled' => true,
            'torrenting.autodownload' => true,
            'autodownload.lastrun' => null,
            'autodownload.period' => 1,
            'autodownload.delay' => 15,
            'calendar.show-specials' => true,
            'torrenting.min_seeders' => 50,
            'torrenting.searchquality' => '',
            'torrenting.ignore_keywords' => '',
            'torrenting.require_keywords' => '',
            'torrenting.global_size_min' => null,
            'torrenting.global_size_max' => null,
            'torrenting.require_keywords_mode_or' => true,
            'torrenting.label' => false,
        ], $overrides);

        $settings->shouldReceive('get')
            ->zeroOrMoreTimes()
            ->andReturnUsing(
                fn (string $key, mixed $default = null): mixed => array_key_exists($key, $values)
                    ? $values[$key]
                    : $default
            );
    }

    protected function createEpisode(array $episodeAttributes = []): Episode
    {
        $serie = Serie::create([
            'name' => 'Lifecycle Show',
            'tvdb_id' => 1234,
            'displaycalendar' => true,
            'autoDownload' => true,
            'ignoreHideSpecials' => false,
            'ignoreGlobalQuality' => false,
            'ignoreGlobalIncludes' => false,
            'ignoreGlobalExcludes' => false,
            'runtime' => 45,
        ]);

        return Episode::create(array_merge([
            'serie_id' => $serie->id,
            'episodename' => 'Pilot',
            'episodenumber' => 1,
            'seasonnumber' => 1,
            'firstaired' => now()->subHours(2)->getTimestampMs(),
            'watched' => 0,
            'watchedAt' => null,
            'downloaded' => 0,
            'magnetHash' => null,
            'leaked' => 0,
        ], $episodeAttributes))->fresh();
    }
}
