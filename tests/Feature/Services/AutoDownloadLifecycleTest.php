<?php

namespace Tests\Feature\Services;

use App\DTOs\TorrentData\TransmissionData;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AutoDownloadLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected SettingsService $settings;

    protected FavoritesService $favorites;

    protected TorrentSearchService $search;

    protected SceneNameResolverService $sceneName;

    protected TorrentClientService $torrentClients;

    protected AutoDownloadService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = Mockery::mock(SettingsService::class);
        $this->favorites = Mockery::mock(FavoritesService::class);
        $this->search = Mockery::mock(TorrentSearchService::class);
        $this->sceneName = Mockery::mock(SceneNameResolverService::class);
        $this->torrentClients = Mockery::mock(TorrentClientService::class);

        $this->service = new AutoDownloadService(
            $this->settings,
            $this->favorites,
            $this->search,
            $this->sceneName,
            $this->torrentClients
        );
    }

    public function test_periodic_scan_establishes_current_process_client_before_candidate_work(): void
    {
        $this->createEpisode([], ['downloaded' => 1]);
        $this->configureSettings();

        $client = Mockery::mock(TorrentClientInterface::class);
        $client->shouldReceive('connect')->once()->ordered()->andReturn(true);
        $client->shouldReceive('getTorrents')->once()->ordered()->andReturn([]);
        $client->shouldReceive('isConnected')->times(4)->andReturn(true);

        $this->torrentClients->shouldReceive('getActiveClient')->once()->andReturn($client);
        $this->sceneName->shouldReceive('getSearchStringForEpisode')->once()->andReturn('Lifecycle Show s01e01');
        $this->search->shouldNotReceive('search');
        $this->settings->shouldReceive('set')
            ->once()
            ->with('autodownload.lastrun', Mockery::type('int'));

        $this->service->check();

        $this->assertDatabaseCount('autodl_activities', 1);
        $activity = AutoDownloadActivity::query()->firstOrFail();
        $this->assertSame(AutoDownloadService::STATUS_DOWNLOADED, (int) $activity->status);
    }

    public function test_failed_current_process_connection_does_not_scan_or_advance_checkpoint(): void
    {
        $this->createEpisode();
        $this->configureSettings();

        $client = Mockery::mock(TorrentClientInterface::class);
        $client->shouldReceive('connect')->once()->andReturn(false);
        $client->shouldReceive('getName')->once()->andReturn('Test Client');
        $client->shouldNotReceive('getTorrents');
        $client->shouldNotReceive('isConnected');

        $this->torrentClients->shouldReceive('getActiveClient')->once()->andReturn($client);
        $this->sceneName->shouldNotReceive('getSearchStringForEpisode');
        $this->search->shouldNotReceive('search');
        $this->settings->shouldNotReceive('set');

        $this->service->check();

        $this->assertDatabaseCount('autodl_activities', 0);
    }

    public function test_connection_loss_after_remote_read_does_not_advance_checkpoint_even_without_candidates(): void
    {
        $this->configureSettings();

        $client = Mockery::mock(TorrentClientInterface::class);
        $client->shouldReceive('connect')->once()->andReturn(true);
        $client->shouldReceive('getTorrents')->once()->andReturn([]);
        $client->shouldReceive('isConnected')->once()->andReturn(false);

        $this->torrentClients->shouldReceive('getActiveClient')->once()->andReturn($client);
        $this->sceneName->shouldNotReceive('getSearchStringForEpisode');
        $this->search->shouldNotReceive('search');
        $this->settings->shouldNotReceive('set');

        $this->service->check();

        $this->assertDatabaseCount('autodl_activities', 0);
    }

    public function test_periodic_scan_skips_when_legacy_stored_hash_is_present_remotely(): void
    {
        $hash = '0123456789ABCDEF0123456789ABCDEF01234567';
        $this->createEpisode([], ['magnetHash' => $hash]);
        $this->configureSettings();

        $remote = new TransmissionData(['infoHash' => strtolower($hash)]);
        $client = Mockery::mock(TorrentClientInterface::class);
        $client->shouldReceive('connect')->once()->andReturn(true);
        $client->shouldReceive('getTorrents')->once()->andReturn([$remote]);
        $client->shouldReceive('isConnected')->times(4)->andReturn(true);

        $this->torrentClients->shouldReceive('getActiveClient')->once()->andReturn($client);
        $this->sceneName->shouldReceive('getSearchStringForEpisode')->once()->andReturn('Lifecycle Show s01e01');
        $this->search->shouldNotReceive('search');
        $this->settings->shouldReceive('set')->once()->with('autodownload.lastrun', Mockery::type('int'));

        $this->service->check();

        $activity = AutoDownloadActivity::query()->firstOrFail();
        $this->assertSame(AutoDownloadService::STATUS_HAS_MAGNET, (int) $activity->status);
    }

    public function test_periodic_scan_retries_when_stored_hash_was_removed_from_remote_client(): void
    {
        $hash = '0123456789abcdef0123456789abcdef01234567';
        $this->createEpisode([], ['magnetHash' => $hash]);
        $this->configureSettings();

        $client = Mockery::mock(TorrentClientInterface::class);
        $client->shouldReceive('connect')->once()->andReturn(true);
        $client->shouldReceive('getTorrents')->once()->andReturn([]);
        $client->shouldReceive('isConnected')->times(4)->andReturn(true);

        $this->torrentClients->shouldReceive('getActiveClient')->once()->andReturn($client);
        $this->sceneName->shouldReceive('getSearchStringForEpisode')->once()->andReturn('Lifecycle Show s01e01');
        $this->search->shouldReceive('search')->once()->with('Lifecycle Show s01e01', null)->andReturn([]);
        $this->settings->shouldReceive('set')->once()->with('autodownload.lastrun', Mockery::type('int'));

        $this->service->check();

        $activity = AutoDownloadActivity::query()->firstOrFail();
        $this->assertSame(AutoDownloadService::STATUS_NOTHING_FOUND, (int) $activity->status);
    }

    public function test_client_loss_mid_scan_aborts_without_advancing_checkpoint(): void
    {
        $this->createEpisode([], ['downloaded' => 1]);
        $this->createEpisode([], ['episodenumber' => 2, 'downloaded' => 1]);
        $this->configureSettings();

        $client = Mockery::mock(TorrentClientInterface::class);
        $client->shouldReceive('connect')->once()->andReturn(true);
        $client->shouldReceive('getTorrents')->once()->andReturn([]);
        $client->shouldReceive('isConnected')->times(3)->andReturn(true, true, false);

        $this->torrentClients->shouldReceive('getActiveClient')->once()->andReturn($client);
        $this->sceneName->shouldReceive('getSearchStringForEpisode')->once()->andReturn('Lifecycle Show s01e01');
        $this->search->shouldNotReceive('search');
        $this->settings->shouldNotReceive('set');

        $this->service->check();

        $this->assertDatabaseCount('autodl_activities', 1);
    }

    public function test_periodic_delay_is_clamped_to_period_window(): void
    {
        $this->createEpisode(
            ['customDelay' => 10_000, 'runtime' => 45],
            ['firstaired' => now()->subHours(25)->getTimestampMs()]
        );
        $this->configureSettings(['autodownload.period' => 1]);

        $client = Mockery::mock(TorrentClientInterface::class);
        $client->shouldReceive('connect')->once()->andReturn(true);
        $client->shouldReceive('getTorrents')->once()->andReturn([]);
        $client->shouldReceive('isConnected')->times(4)->andReturn(true);

        $this->torrentClients->shouldReceive('getActiveClient')->once()->andReturn($client);
        $this->sceneName->shouldReceive('getSearchStringForEpisode')->once()->andReturn('Lifecycle Show s01e01');
        $this->search->shouldReceive('search')->once()->andReturn([]);
        $this->settings->shouldReceive('set')->once()->with('autodownload.lastrun', Mockery::type('int'));

        $this->service->check();

        $activity = AutoDownloadActivity::query()->firstOrFail();
        $this->assertSame(AutoDownloadService::STATUS_NOTHING_FOUND, (int) $activity->status);
    }

    public function test_manual_download_preserves_pd1_direct_action_semantics_and_returns_client_success(): void
    {
        $oldHash = str_repeat('a', 40);
        $newHash = str_repeat('b', 40);
        $episode = $this->createEpisode(
            ['displaycalendar' => false, 'autoDownload' => false],
            [
                'watched' => 1,
                'watchedAt' => now()->subHour()->getTimestampMs(),
                'downloaded' => 1,
                'magnetHash' => $oldHash,
            ]
        );
        $this->configureSettings(['torrenting.autodownload' => false]);

        $magnet = 'magnet:?xt=urn:btih:'.$newHash;
        $client = Mockery::mock(TorrentClientInterface::class);
        $client->shouldReceive('connect')->once()->andReturn(true);
        $client->shouldReceive('isConnected')->once()->andReturn(true);
        $client->shouldReceive('addMagnet')->once()->with($magnet, null, 'DuckieTV')->andReturn(true);

        $this->torrentClients->shouldReceive('getActiveClient')->once()->andReturn($client);
        $this->sceneName->shouldReceive('getSearchStringForEpisode')->once()->andReturn('Manual Show s01e01');
        $this->search->shouldReceive('search')->once()->with('Manual Show s01e01', null)->andReturn([[
            'releasename' => 'Manual.Show.s01e01',
            'sizeBytes' => 500_000_000,
            'sizeParseError' => false,
            'seeders' => 100,
            'magnetUrl' => $magnet,
        ]]);

        $result = $this->service->manualDownload($episode);

        $this->assertTrue($result);
        $this->assertSame($newHash, $episode->fresh()->magnetHash);
        $this->assertDatabaseCount('autodl_activities', 1);
        $activity = AutoDownloadActivity::query()->firstOrFail();
        $this->assertSame(AutoDownloadService::STATUS_TORRENT_LAUNCHED, (int) $activity->status);
    }

    public function test_manual_download_returns_false_when_client_rejects_launch_and_preserves_tracked_hash(): void
    {
        $oldHash = str_repeat('a', 40);
        $newHash = str_repeat('b', 40);
        $episode = $this->createEpisode([], ['magnetHash' => $oldHash]);
        $this->configureSettings();

        $magnet = 'magnet:?xt=urn:btih:'.$newHash;
        $client = Mockery::mock(TorrentClientInterface::class);
        $client->shouldReceive('connect')->once()->andReturn(true);
        $client->shouldReceive('isConnected')->once()->andReturn(true);
        $client->shouldReceive('addMagnet')->once()->andReturn(false);

        $this->torrentClients->shouldReceive('getActiveClient')->once()->andReturn($client);
        $this->sceneName->shouldReceive('getSearchStringForEpisode')->once()->andReturn('Manual Show s01e01');
        $this->search->shouldReceive('search')->once()->andReturn([[
            'releasename' => 'Manual.Show.s01e01',
            'sizeBytes' => 500_000_000,
            'sizeParseError' => false,
            'seeders' => 100,
            'magnetUrl' => $magnet,
        ]]);

        $result = $this->service->manualDownload($episode);

        $this->assertFalse($result);
        $this->assertSame($oldHash, $episode->fresh()->magnetHash);
        $this->assertDatabaseCount('autodl_activities', 1);
        $activity = AutoDownloadActivity::query()->firstOrFail();
        $this->assertSame(AutoDownloadService::STATUS_INFRASTRUCTURE_FAILURE, (int) $activity->status);
    }

    public function test_manual_download_requires_current_process_client_connection(): void
    {
        $episode = $this->createEpisode();
        $this->configureSettings();

        $client = Mockery::mock(TorrentClientInterface::class);
        $client->shouldReceive('connect')->once()->andReturn(false);
        $client->shouldReceive('getName')->once()->andReturn('Test Client');
        $client->shouldNotReceive('isConnected');
        $client->shouldNotReceive('addMagnet');

        $this->torrentClients->shouldReceive('getActiveClient')->once()->andReturn($client);
        $this->sceneName->shouldReceive('getSearchStringForEpisode')->once()->andReturn('Manual Show s01e01');
        $this->search->shouldNotReceive('search');

        $this->assertFalse($this->service->manualDownload($episode));

        $this->assertDatabaseCount('autodl_activities', 1);
        $activity = AutoDownloadActivity::query()->firstOrFail();
        $this->assertSame(AutoDownloadService::STATUS_INFRASTRUCTURE_FAILURE, (int) $activity->status);
    }

    public function test_manual_download_requires_torrenting_enabled(): void
    {
        $episode = $this->createEpisode();
        $this->configureSettings(['torrenting.enabled' => false]);

        $this->torrentClients->shouldNotReceive('getActiveClient');
        $this->sceneName->shouldReceive('getSearchStringForEpisode')->once()->andReturn('Manual Show s01e01');
        $this->search->shouldNotReceive('search');

        $this->assertFalse($this->service->manualDownload($episode));

        $activity = AutoDownloadActivity::query()->firstOrFail();
        $this->assertSame(AutoDownloadService::STATUS_AUTODL_DISABLED, (int) $activity->status);
    }

    public function test_manual_download_requires_aired_or_leaked_episode(): void
    {
        $episode = $this->createEpisode([], [
            'firstaired' => now()->addDay()->getTimestampMs(),
            'leaked' => 0,
        ]);
        $this->configureSettings();

        $this->torrentClients->shouldNotReceive('getActiveClient');
        $this->sceneName->shouldReceive('getSearchStringForEpisode')->once()->andReturn('Manual Show s01e01');
        $this->search->shouldNotReceive('search');

        $this->assertFalse($this->service->manualDownload($episode));

        $activity = AutoDownloadActivity::query()->firstOrFail();
        $this->assertSame(AutoDownloadService::STATUS_ON_AIR_DELAY, (int) $activity->status);
    }

    public function test_manual_download_requires_series_tvdb_id(): void
    {
        $episode = $this->createEpisode(['tvdb_id' => null]);
        $this->configureSettings();

        $this->torrentClients->shouldNotReceive('getActiveClient');
        $this->sceneName->shouldReceive('getSearchStringForEpisode')->once()->andReturn('Manual Show s01e01');
        $this->search->shouldNotReceive('search');

        $this->assertFalse($this->service->manualDownload($episode));

        $activity = AutoDownloadActivity::query()->firstOrFail();
        $this->assertSame(AutoDownloadService::STATUS_TVDB_ID_MISSING, (int) $activity->status);
    }

    public function test_size_parse_failure_creates_only_one_terminal_activity_row(): void
    {
        $episode = $this->createEpisode();
        $this->configureSettings();

        $this->sceneName->shouldReceive('getSearchStringForEpisode')->once()->andReturn('Lifecycle Show s01e01');
        $this->search->shouldReceive('search')->once()->andReturn([[
            'releasename' => 'Lifecycle.Show.s01e01',
            'sizeBytes' => null,
            'sizeParseError' => true,
            'seeders' => 100,
        ]]);

        $this->invokeProcessEpisode($episode);

        $this->assertDatabaseCount('autodl_activities', 1);
        $activity = AutoDownloadActivity::query()->firstOrFail();
        $this->assertSame(AutoDownloadService::STATUS_FILTERED_OUT, (int) $activity->status);
        $this->assertSame(' MS (size parse error)', $activity->extra);
    }

    public function test_terminal_activity_preserves_required_keyword_stage(): void
    {
        $episode = $this->createEpisode(['customIncludes' => 'HEVC']);
        $this->configureSettings();

        $this->sceneName->shouldReceive('getSearchStringForEpisode')->once()->andReturn('Lifecycle Show s01e01');
        $this->search->shouldReceive('search')->once()->andReturn([[
            'releasename' => 'Lifecycle.Show.s01e01.x264',
            'sizeBytes' => 500_000_000,
            'sizeParseError' => false,
            'seeders' => 100,
        ]]);

        $this->invokeProcessEpisode($episode);

        $this->assertDatabaseCount('autodl_activities', 1);
        $activity = AutoDownloadActivity::query()->firstOrFail();
        $this->assertSame(AutoDownloadService::STATUS_FILTERED_OUT, (int) $activity->status);
        $this->assertSame(' RK', $activity->extra);
    }

    public function test_terminal_activity_preserves_ignore_keyword_stage(): void
    {
        $episode = $this->createEpisode(['customExcludes' => 'CAM']);
        $this->configureSettings();

        $this->sceneName->shouldReceive('getSearchStringForEpisode')->once()->andReturn('Lifecycle Show s01e01');
        $this->search->shouldReceive('search')->once()->andReturn([[
            'releasename' => 'Lifecycle.Show.s01e01.CAM',
            'sizeBytes' => 500_000_000,
            'sizeParseError' => false,
            'seeders' => 100,
        ]]);

        $this->invokeProcessEpisode($episode);

        $this->assertDatabaseCount('autodl_activities', 1);
        $activity = AutoDownloadActivity::query()->firstOrFail();
        $this->assertSame(AutoDownloadService::STATUS_FILTERED_OUT, (int) $activity->status);
        $this->assertSame(' IK', $activity->extra);
    }

    public function test_terminal_activity_preserves_seeders_stage(): void
    {
        $episode = $this->createEpisode();
        $this->configureSettings(['torrenting.min_seeders' => 50]);

        $this->sceneName->shouldReceive('getSearchStringForEpisode')->once()->andReturn('Lifecycle Show s01e01');
        $this->search->shouldReceive('search')->once()->andReturn([[
            'releasename' => 'Lifecycle.Show.s01e01',
            'sizeBytes' => 500_000_000,
            'sizeParseError' => false,
            'seeders' => 5,
        ]]);

        $this->invokeProcessEpisode($episode);

        $this->assertDatabaseCount('autodl_activities', 1);
        $activity = AutoDownloadActivity::query()->firstOrFail();
        $this->assertSame(AutoDownloadService::STATUS_NOT_ENOUGH_SEEDERS, (int) $activity->status);
    }

    public function test_activity_view_uses_canonical_service_status_codes(): void
    {
        $labels = [
            AutoDownloadService::STATUS_DOWNLOADED => 'Already Downloaded',
            AutoDownloadService::STATUS_WATCHED => 'Already Watched',
            AutoDownloadService::STATUS_HAS_MAGNET => 'Has Magnet',
            AutoDownloadService::STATUS_AUTODL_DISABLED => 'AutoDL Disabled',
            AutoDownloadService::STATUS_NOTHING_FOUND => 'Nothing Found',
            AutoDownloadService::STATUS_FILTERED_OUT => 'Filtered Out',
            AutoDownloadService::STATUS_TORRENT_LAUNCHED => 'Torrent Launched',
            AutoDownloadService::STATUS_NOT_ENOUGH_SEEDERS => 'Not Enough Seeders',
            AutoDownloadService::STATUS_ON_AIR_DELAY => 'On Air Delay',
            AutoDownloadService::STATUS_TVDB_ID_MISSING => 'TVDB ID Missing',
            AutoDownloadService::STATUS_INFRASTRUCTURE_FAILURE => 'Infrastructure Failure',
        ];

        $activities = collect(array_map(
            fn (int $status): object => (object) [
                'timestamp' => now()->timestamp,
                'serie_name' => 'Lifecycle Show',
                'episode_formatted' => 's01e01',
                'search' => 'Lifecycle Show s01e01',
                'search_provider' => '',
                'search_extra' => '',
                'status' => $status,
                'extra' => '',
            ],
            array_keys($labels)
        ));

        $html = view('autodlstatus.index', [
            'activityList' => $activities,
            'status' => 'active',
            'lastRun' => null,
        ])->render();

        foreach ($labels as $label) {
            $this->assertStringContainsString($label, $html);
        }
    }

    protected function configureSettings(array $overrides = []): void
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

        $this->settings
            ->shouldReceive('get')
            ->zeroOrMoreTimes()
            ->andReturnUsing(
                fn (string $key, mixed $default = null): mixed => array_key_exists($key, $values)
                    ? $values[$key]
                    : $default
            );
    }

    protected function createEpisode(array $serieAttributes = [], array $episodeAttributes = []): Episode
    {
        $serie = Serie::create(array_merge([
            'name' => 'Lifecycle Show',
            'tvdb_id' => 1234,
            'displaycalendar' => true,
            'autoDownload' => true,
            'ignoreHideSpecials' => false,
            'ignoreGlobalQuality' => false,
            'ignoreGlobalIncludes' => false,
            'ignoreGlobalExcludes' => false,
            'runtime' => 45,
        ], $serieAttributes));

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

    protected function invokeProcessEpisode(Episode $episode): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('processEpisode');
        $method->setAccessible(true);
        $method->invoke($this->service, $episode);
    }
}
