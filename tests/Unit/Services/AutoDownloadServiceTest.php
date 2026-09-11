<?php

namespace Tests\Unit\Services;

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

class AutoDownloadServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AutoDownloadService $service;

    protected $settingsMock;

    protected $favoritesMock;

    protected $searchMock;

    protected $sceneNameMock;

    protected $torrentClientMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settingsMock = Mockery::mock(SettingsService::class);
        $this->favoritesMock = Mockery::mock(FavoritesService::class);
        $this->searchMock = Mockery::mock(TorrentSearchService::class);
        $this->sceneNameMock = Mockery::mock(SceneNameResolverService::class);
        $this->torrentClientMock = Mockery::mock(TorrentClientService::class);

        $this->service = new AutoDownloadService(
            $this->settingsMock,
            $this->favoritesMock,
            $this->searchMock,
            $this->sceneNameMock,
            $this->torrentClientMock
        );
    }

    /**
     * Test the word-by-word scoring logic (filterByScore).
     * Parity: All words in query must exist in release name.
     */
    public function test_filter_by_score()
    {
        $query = 'Big Bang Theory s01e01 1080p';

        $cases = [
            ['name' => 'The.Big.Bang.Theory.S01E01.1080p.Bluray', 'expected' => true],
            ['name' => 'Big.Bang.Theory.S01E01.720p', 'expected' => false], // missing 1080p
            ['name' => 'The Big Bang Theory S01E01 1080p x265', 'expected' => true],
            ['name' => 'Theory.S01E01.1080p', 'expected' => false], // missing Big, Bang
        ];

        foreach ($cases as $case) {
            $result = $this->invokePrivateMethod($this->service, 'filterByScore', [$case['name'], $query]);
            $this->assertEquals($case['expected'], $result, 'Failed for: '.$case['name']);
        }
    }

    public function test_filter_by_size_uses_typed_bytes_and_decimal_mb_thresholds(): void
    {
        $serie = new Serie(['customSearchSizeMin' => 100, 'customSearchSizeMax' => 500]);

        $cases = [
            ['sizeBytes' => 100_000_000, 'parseError' => false, 'expected' => true],
            ['sizeBytes' => 500_000_000, 'parseError' => false, 'expected' => true],
            ['sizeBytes' => 99_999_999, 'parseError' => false, 'expected' => false],
            ['sizeBytes' => 500_000_001, 'parseError' => false, 'expected' => false],
            ['sizeBytes' => null, 'parseError' => false, 'expected' => true],
            ['sizeBytes' => null, 'parseError' => true, 'expected' => false],
        ];

        foreach ($cases as $case) {
            $result = $this->invokePrivateMethod(
                $this->service,
                'filterBySize',
                [$case['sizeBytes'], $case['parseError'], $serie, null, null]
            );
            $this->assertSame($case['expected'], $result);
        }
    }

    public function test_filter_by_size_treats_null_global_thresholds_as_unbounded(): void
    {
        $serie = new Serie(['customSearchSizeMin' => null, 'customSearchSizeMax' => null]);

        $result = $this->invokePrivateMethod(
            $this->service,
            'filterBySize',
            [5_000_000_000, false, $serie, null, null]
        );

        $this->assertTrue($result);
    }

    /**
     * Test Keyword filtering (Require/Ignore) with Exclusion override prevention.
     */
    public function test_filter_keywords_with_exclude_override_prevention()
    {
        $q = 'The Show S01E01 x264';

        $cases = [
            // Require keywords (OR mode)
            ['name' => 'The.Show.S01E01.x264', 'require' => 'x264 x265', 'ignore' => '', 'expected' => true],
            ['name' => 'The.Show.S01E01.hvc1', 'require' => 'x264 x265', 'ignore' => '', 'expected' => false],

            // Ignore keywords
            ['name' => 'The.Show.S01E01.PROPER', 'require' => '', 'ignore' => 'PROPER', 'expected' => false],
            ['name' => 'The.Show.S01E01.HDTV',   'require' => '', 'ignore' => 'PROPER', 'expected' => true],

            // Parity Check: prevent exclude list from overriding primary search string
            // 'x264' is in q, so it should be filtered OUT of the ignore list.
            ['name' => 'The.Show.S01E01.x264', 'require' => '', 'ignore' => 'x264', 'expected' => true],
        ];

        foreach ($cases as $case) {
            $result = $this->invokePrivateMethod($this->service, 'filterKeywords', [$case['name'], $case['require'], $case['ignore'], true, $q]);
            $this->assertEquals($case['expected'], $result, 'Failed for: '.$case['name']);
        }
    }

    public function test_persisted_serie_casts_match_auto_download_contract(): void
    {
        $serie = Serie::create([
            'name' => 'Contract Casts',
            'tvdb_id' => '1234',
            'displaycalendar' => 1,
            'autoDownload' => 0,
            'ignoreHideSpecials' => 1,
            'ignoreGlobalQuality' => 0,
            'ignoreGlobalIncludes' => 1,
            'ignoreGlobalExcludes' => 0,
            'runtime' => '45',
            'customSearchSizeMin' => '100',
            'customSearchSizeMax' => '500',
            'customDelay' => '30',
            'customSeeders' => '12',
            'customIncludes' => 'HEVC',
            'customExcludes' => 'CAM',
            'searchProvider' => '1337x',
        ])->fresh();

        $this->assertTrue($serie->displaycalendar);
        $this->assertFalse($serie->autoDownload);
        $this->assertTrue($serie->ignoreHideSpecials);
        $this->assertFalse($serie->ignoreGlobalQuality);
        $this->assertTrue($serie->ignoreGlobalIncludes);
        $this->assertFalse($serie->ignoreGlobalExcludes);
        $this->assertSame(1234, $serie->tvdb_id);
        $this->assertSame(45, $serie->runtime);
        $this->assertSame(100, $serie->customSearchSizeMin);
        $this->assertSame(500, $serie->customSearchSizeMax);
        $this->assertSame(30, $serie->customDelay);
        $this->assertSame(12, $serie->customSeeders);
        $this->assertSame('HEVC', $serie->customIncludes);
        $this->assertSame('CAM', $serie->customExcludes);
        $this->assertSame('1337x', $serie->searchProvider);

        $nullable = Serie::create([
            'name' => 'Nullable Contract',
            'customSearchSizeMin' => null,
            'customSearchSizeMax' => null,
            'customDelay' => null,
            'customSeeders' => null,
            'runtime' => null,
        ])->fresh();

        $this->assertNull($nullable->customSearchSizeMin);
        $this->assertNull($nullable->customSearchSizeMax);
        $this->assertNull($nullable->customDelay);
        $this->assertNull($nullable->customSeeders);
        $this->assertNull($nullable->runtime);
    }

    public function test_persisted_visible_enabled_serie_reaches_search_with_canonical_custom_fields(): void
    {
        $episode = $this->createPersistedEpisode([
            'displaycalendar' => true,
            'autoDownload' => true,
            'customSeeders' => 12,
            'customIncludes' => 'HEVC',
            'customExcludes' => 'CAM',
            'customSearchSizeMin' => 100,
            'customSearchSizeMax' => 500,
            'searchProvider' => '1337x',
        ]);

        $this->sceneNameMock
            ->shouldReceive('getSearchStringForEpisode')
            ->once()
            ->andReturn('Contract Show s01e01');

        $settings = [
            'autodownload.delay' => 15,
            'torrenting.min_seeders' => 50,
            'torrenting.searchquality' => '',
            'torrenting.ignore_keywords' => '',
            'torrenting.require_keywords' => '',
            'torrenting.global_size_min' => null,
            'torrenting.global_size_max' => null,
            'torrenting.require_keywords_mode_or' => true,
        ];
        $this->settingsMock
            ->shouldReceive('get')
            ->zeroOrMoreTimes()
            ->andReturnUsing(fn (string $key, mixed $default = null) => array_key_exists($key, $settings) ? $settings[$key] : $default);

        $this->searchMock
            ->shouldReceive('search')
            ->once()
            ->with('Contract Show s01e01', '1337x')
            ->andReturn([]);

        $this->invokePrivateMethod($this->service, 'processEpisode', [$episode]);

        $activity = AutoDownloadActivity::query()->latest('id')->firstOrFail();
        $this->assertSame(AutoDownloadService::STATUS_NOTHING_FOUND, (int) $activity->status);
        $this->assertSame(' (1337x)', $activity->search_provider);
        $this->assertSame(' (100/500) [12] {HEVC} <CAM>', $activity->search_extra);
    }

    public function test_size_parser_failure_is_filtered_and_recorded_diagnostically(): void
    {
        $episode = $this->createPersistedEpisode();

        $this->sceneNameMock
            ->shouldReceive('getSearchStringForEpisode')
            ->once()
            ->andReturn('Contract Show s01e01');

        $settings = [
            'autodownload.delay' => 15,
            'torrenting.min_seeders' => 50,
            'torrenting.searchquality' => '',
            'torrenting.ignore_keywords' => '',
            'torrenting.require_keywords' => '',
            'torrenting.global_size_min' => null,
            'torrenting.global_size_max' => null,
            'torrenting.require_keywords_mode_or' => true,
        ];
        $this->settingsMock
            ->shouldReceive('get')
            ->zeroOrMoreTimes()
            ->andReturnUsing(fn (string $key, mixed $default = null) => array_key_exists($key, $settings) ? $settings[$key] : $default);

        $this->searchMock
            ->shouldReceive('search')
            ->once()
            ->andReturn([[
                'releasename' => 'Contract.Show.s01e01',
                'sizeBytes' => null,
                'sizeParseError' => true,
                'seeders' => 100,
            ]]);

        $this->invokePrivateMethod($this->service, 'processEpisode', [$episode]);

        $activity = AutoDownloadActivity::query()
            ->where('status', AutoDownloadService::STATUS_FILTERED_OUT)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(' S (size parse error)', $activity->extra);
    }

    public function test_remote_torrent_map_uses_only_canonical_btih_keys(): void
    {
        $validHash = '0123456789ABCDEF0123456789ABCDEF01234567';

        $validTorrent = new \App\DTOs\TorrentData\TransmissionData(['infoHash' => $validHash]);
        $transportOnlyTorrent = new \App\DTOs\TorrentData\TransmissionData(['infoHash' => 'aria2-gid-123']);

        $client = Mockery::mock(TorrentClientInterface::class);
        $client->shouldReceive('isConnected')->once()->andReturn(true);
        $client->shouldReceive('getTorrents')->once()->andReturn([$validTorrent, $transportOnlyTorrent]);

        $this->torrentClientMock->shouldReceive('getActiveClient')->once()->andReturn($client);

        $settings = [
            'torrenting.autodownload' => true,
            'autodownload.lastrun' => null,
            'autodownload.period' => 1,
        ];
        $this->settingsMock
            ->shouldReceive('get')
            ->andReturnUsing(fn (string $key, mixed $default = null) => array_key_exists($key, $settings) ? $settings[$key] : $default);
        $this->settingsMock->shouldReceive('set')->once()->with('autodownload.lastrun', Mockery::type('int'));

        $this->service->check();

        $reflection = new \ReflectionProperty($this->service, 'remoteTorrents');
        $remoteTorrents = $reflection->getValue($this->service);

        $this->assertSame([strtolower($validHash)], array_keys($remoteTorrents));
        $this->assertSame($validTorrent, $remoteTorrents[strtolower($validHash)]);
    }

    public function test_download_persists_canonical_magnet_identity_only_after_client_success(): void
    {
        $episode = $this->createPersistedEpisode();
        $hash = '0123456789ABCDEF0123456789ABCDEF01234567';
        $magnet = 'magnet:?xt=urn:btih:'.$hash;

        $client = Mockery::mock(TorrentClientInterface::class);
        $client->shouldReceive('isConnected')->once()->andReturn(true);
        $client->shouldReceive('addMagnet')->once()->with($magnet, null, 'DuckieTV')->andReturn(true);

        $this->torrentClientMock->shouldReceive('getActiveClient')->once()->andReturn($client);
        $this->settingsMock->shouldReceive('get')->once()->with('torrenting.label')->andReturn(false);

        $this->invokePrivateMethod($this->service, 'download', [
            $episode->serie,
            $episode,
            ['magnetUrl' => $magnet, 'releasename' => 'Contract.Show.s01e01'],
            'Contract Show s01e01',
        ]);

        $this->assertSame(strtolower($hash), $episode->fresh()->magnetHash);
        $activity = AutoDownloadActivity::query()->latest('id')->firstOrFail();
        $this->assertSame(AutoDownloadService::STATUS_TORRENT_LAUNCHED, (int) $activity->status);
    }

    public function test_download_does_not_launch_tracked_torrent_without_canonical_identity(): void
    {
        $episode = $this->createPersistedEpisode();

        $this->torrentClientMock->shouldNotReceive('getActiveClient');

        $this->invokePrivateMethod($this->service, 'download', [
            $episode->serie,
            $episode,
            ['torrentUrl' => 'https://example.com/file.torrent', 'releasename' => 'Contract.Show.s01e01'],
            'Contract Show s01e01',
        ]);

        $this->assertNull($episode->fresh()->magnetHash);
        $activity = AutoDownloadActivity::query()->latest('id')->firstOrFail();
        $this->assertSame(AutoDownloadService::STATUS_NOTHING_FOUND, (int) $activity->status);
        $this->assertSame(' (Missing torrent identity)', $activity->extra);
    }

    public function test_persisted_hidden_serie_is_excluded_before_search(): void
    {
        $episode = $this->createPersistedEpisode(['displaycalendar' => false]);

        $this->sceneNameMock
            ->shouldReceive('getSearchStringForEpisode')
            ->once()
            ->andReturn('Hidden Show s01e01');
        $this->searchMock->shouldNotReceive('search');

        $this->invokePrivateMethod($this->service, 'processEpisode', [$episode]);

        $activity = AutoDownloadActivity::query()->latest('id')->firstOrFail();
        $this->assertSame(AutoDownloadService::STATUS_AUTODL_DISABLED, (int) $activity->status);
        $this->assertSame(' HC', $activity->extra);
    }

    public function test_persisted_auto_download_disabled_serie_is_excluded_before_search(): void
    {
        $episode = $this->createPersistedEpisode(['autoDownload' => false]);

        $this->sceneNameMock
            ->shouldReceive('getSearchStringForEpisode')
            ->once()
            ->andReturn('Disabled Show s01e01');
        $this->searchMock->shouldNotReceive('search');

        $this->invokePrivateMethod($this->service, 'processEpisode', [$episode]);

        $activity = AutoDownloadActivity::query()->latest('id')->firstOrFail();
        $this->assertSame(AutoDownloadService::STATUS_AUTODL_DISABLED, (int) $activity->status);
        $this->assertSame('', $activity->extra);
    }

    public function test_ignore_hide_specials_uses_persisted_boolean_semantics(): void
    {
        $episode = $this->createPersistedEpisode(
            ['ignoreHideSpecials' => false],
            ['seasonnumber' => 0]
        );

        $this->sceneNameMock
            ->shouldReceive('getSearchStringForEpisode')
            ->once()
            ->andReturn('Special Show s00e01');
        $this->settingsMock
            ->shouldReceive('get')
            ->once()
            ->with('calendar.show-specials')
            ->andReturn(false);
        $this->searchMock->shouldNotReceive('search');

        $this->invokePrivateMethod($this->service, 'processEpisode', [$episode]);

        $activity = AutoDownloadActivity::query()->latest('id')->firstOrFail();
        $this->assertSame(AutoDownloadService::STATUS_AUTODL_DISABLED, (int) $activity->status);
        $this->assertSame(' HS', $activity->extra);
    }

    protected function createPersistedEpisode(array $serieAttributes = [], array $episodeAttributes = []): Episode
    {
        $serie = Serie::create(array_merge([
            'name' => 'Contract Show',
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
        ], $episodeAttributes))->fresh();
    }

    /**
     * Helper to invoke private methods for testing intricacies.
     */
    protected function invokePrivateMethod($object, $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }
}
