from pathlib import Path


def replace_exact(text: str, old: str, new: str, expected: int = 1) -> str:
    count = text.count(old)
    if count != expected:
        raise SystemExit(f"expected {expected} occurrence(s), found {count}: {old!r}")
    return text.replace(old, new)


# Serie model: make the auto-download value contract explicit without changing schema.
serie_path = Path('app/Models/Serie.php')
serie = serie_path.read_text()
serie = replace_exact(
    serie,
    '@property int|null $customDelay Custom auto-download delay in hours',
    '@property int|null $customDelay Custom auto-download delay in minutes',
)
serie = replace_exact(
    serie,
    "        'ignoreHideSpecials' => 'boolean',\n",
    "        'ignoreHideSpecials' => 'boolean',\n"
    "        'tvdb_id' => 'integer',\n"
    "        'runtime' => 'integer',\n"
    "        'customSearchSizeMin' => 'integer',\n"
    "        'customSearchSizeMax' => 'integer',\n"
    "        'customDelay' => 'integer',\n"
    "        'customSeeders' => 'integer',\n",
)
serie_path.write_text(serie)


# Canonical AutoDownloadService reads must use the persisted Serie contract.
service_path = Path('app/Services/AutoDownloadService.php')
service = service_path.read_text()
renames = {
    '->custom_search_size_min': '->customSearchSizeMin',
    '->custom_search_size_max': '->customSearchSizeMax',
    '->custom_seeders': '->customSeeders',
    '->custom_includes': '->customIncludes',
    '->custom_excludes': '->customExcludes',
    '->search_provider': '->searchProvider',
    '->ignore_hide_specials': '->ignoreHideSpecials',
    '->auto_download': '->autoDownload',
    '->custom_delay': '->customDelay',
    '->ignore_global_quality': '->ignoreGlobalQuality',
    '->ignore_global_excludes': '->ignoreGlobalExcludes',
    '->ignore_global_includes': '->ignoreGlobalIncludes',
}
for old, new in renames.items():
    if old not in service:
        raise SystemExit(f'missing expected legacy Serie read: {old}')
    service = service.replace(old, new)

service = replace_exact(service, '$serie->ignoreHideSpecials !== 1', '! $serie->ignoreHideSpecials')
service = replace_exact(service, '$serie->displaycalendar !== 1', '! $serie->displaycalendar')
service = replace_exact(service, '$serie->autoDownload === 0', '! $serie->autoDownload')
service = replace_exact(
    service,
    'if (! $serie->tvdb_id && ! $serie->TVDB_ID) {',
    'if (! $serie->tvdb_id) {',
)
service_path.write_text(service)


# Existing unit fixture used non-persisted snake_case attributes and masked the real model contract.
test_path = Path('tests/Unit/Services/AutoDownloadServiceTest.php')
test = test_path.read_text()
test = replace_exact(
    test,
    'use App\\Models\\Serie;\n',
    'use App\\Models\\AutoDownloadActivity;\nuse App\\Models\\Episode;\nuse App\\Models\\Serie;\n',
)
test = replace_exact(
    test,
    "$serie = new Serie(['custom_search_size_min' => 100, 'custom_search_size_max' => 500]);",
    "$serie = new Serie(['customSearchSizeMin' => 100, 'customSearchSizeMax' => 500]);",
)

marker = '''    /**\n     * Helper to invoke private methods for testing intricacies.\n     */\n'''
if test.count(marker) != 1:
    raise SystemExit('unable to locate test helper insertion point')

new_tests = r'''    public function test_persisted_serie_casts_match_auto_download_contract(): void
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

'''

test = test.replace(marker, new_tests + marker)
test_path.write_text(test)
