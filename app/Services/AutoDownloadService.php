<?php

namespace App\Services;

use App\Models\AutoDownloadActivity;
use App\Models\Episode;
use App\Models\Serie;
use App\Services\TorrentClients\TorrentClientInterface;
use App\Support\AutoDownloadDeadline;
use App\Support\MagnetUri;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Service responsible for automatically searching and downloading episode torrents.
 *
 * This service handles:
 * - Periodic checks for aired episodes within the configured window.
 * - Manual download triggers for specific episodes.
 * - Business logic for delays (On-Air delay), quality filters, and keyword filtering.
 * - Integration with the active Torrent Client to add magnet/torrent links.
 * - Activity logging for the Auto-Download dashboard via AutoDownloadActivity model.
 *
 * @see AutoDownloadService.js in DuckieTV-angular for original implementation source.
 */
class AutoDownloadService
{
    protected SettingsService $settings;

    protected FavoritesService $favorites;

    protected TorrentSearchService $searchService;

    protected SceneNameResolverService $sceneNameResolver;

    protected TorrentClientService $torrentClientService;

    protected ?TorrentClientInterface $activeClient = null;

    protected bool $periodicAbortRequested = false;

    /** @var array<string, \App\DTOs\TorrentData\TorrentDataInterface> Internal cache of remote torrents indexed by infoHash */
    protected array $remoteTorrents = [];

    // Status codes matching original DuckieTV AutoDownloadService.js
    /** Episode already marked as downloaded in DB */
    public const STATUS_DOWNLOADED = 0;

    /** Episode already marked as watched in DB */
    public const STATUS_WATCHED = 1;

    /** Torrent infoHash already presents in the active Torrent Client */
    public const STATUS_HAS_MAGNET = 2;

    /** Auto-download skipped because feature is disabled for this series or globally */
    public const STATUS_AUTODL_DISABLED = 3;

    /** Search returned no results matching filters */
    public const STATUS_NOTHING_FOUND = 4;

    /** Search results found but all were filtered out by quality, size, or keywords */
    public const STATUS_FILTERED_OUT = 5;

    /** A suitable torrent was found and successfully added to the client */
    public const STATUS_TORRENT_LAUNCHED = 6;

    /** No results had enough seeders based on global or series-specific settings */
    public const STATUS_NOT_ENOUGH_SEEDERS = 7;

    /** Episode has aired but is still within the 'safe' delay period (to allow for scene release) */
    public const STATUS_ON_AIR_DELAY = 8;

    /** Series metadata is incomplete (missing TVDB ID) preventing reliable search */
    public const STATUS_TVDB_ID_MISSING = 9;

    /** Search or torrent-client infrastructure failed */
    public const STATUS_INFRASTRUCTURE_FAILURE = 10;

    public function __construct(
        SettingsService $settings,
        FavoritesService $favorites,
        TorrentSearchService $searchService,
        SceneNameResolverService $sceneNameResolver,
        TorrentClientService $torrentClientService
    ) {
        $this->settings = $settings;
        $this->favorites = $favorites;
        $this->searchService = $searchService;
        $this->sceneNameResolver = $sceneNameResolver;
        $this->torrentClientService = $torrentClientService;
    }

    /**
     * Get the recent activity list from the database.
     *
     * In the original DuckieTV-angular, the AutoDownloadService maintained an in-memory
     * object/array of the 'last check' results to populate the Activity Log UI.
     * In DuckieTV.Next, we use the autodl_activities table to persist this data.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, AutoDownloadActivity> List of activity log entries.
     */
    public function getActivityList()
    {
        return AutoDownloadActivity::orderBy('timestamp', 'desc')->limit(100)->get();
    }

    public function isEnabled(): bool
    {
        return $this->settings->get('torrenting.autodownload', false);
    }

    public function getLastRun(): ?Carbon
    {
        $lastRun = $this->settings->get('autodownload.lastrun');

        return $lastRun ? Carbon::createFromTimestampMs($lastRun) : null;
    }

    protected function logActivity(Serie $serie, Episode $episode, string $search, int $status, string $extra = ''): void
    {
        $searchExtra = '';
        if ($serie->customSearchSizeMin !== null || $serie->customSearchSizeMax !== null) {
            $min = $serie->customSearchSizeMin ?? '-';
            $max = $serie->customSearchSizeMax ?? '-';
            $searchExtra = " ($min/$max)";
        }

        if ($serie->customSeeders !== null) {
            $searchExtra .= " [{$serie->customSeeders}]";
        }
        if ($serie->customIncludes !== null) {
            $searchExtra .= " {{$serie->customIncludes}}";
        }
        if ($serie->customExcludes !== null) {
            $searchExtra .= " <{$serie->customExcludes}>";
        }

        AutoDownloadActivity::create([
            'serie_id' => $serie->id,
            'episode_id' => $episode->id,
            'search' => $search,
            'search_provider' => $serie->searchProvider !== null && $serie->searchProvider !== '' ? " ({$serie->searchProvider})" : '',
            'search_extra' => $searchExtra,
            'status' => $status,
            'extra' => $extra,
            'serie_name' => $serie->name,
            'episode_formatted' => $episode->getFormattedEpisode(),
            'timestamp' => now()->timestamp,
        ]);
    }

    /**
     * Main periodic check loop.
     */
    public function check(?AutoDownloadDeadline $deadline = null): void
    {
        $this->periodicAbortRequested = false;

        $torrentingEnabled = (bool) $this->settings->get('torrenting.enabled', true);
        if ($torrentingEnabled === false || $this->isEnabled() === false) {
            return;
        }

        if ($this->deadlineReached($deadline)) {
            return;
        }

        $this->remoteTorrents = [];
        $client = $this->establishUsableClient();

        if ($client === null || $this->deadlineReached($deadline)) {
            return;
        }

        if ($this->loadRemoteTorrents($client) === false || $this->deadlineReached($deadline)) {
            return;
        }

        if ($client->isConnected() === false) {
            Log::warning('AutoDownload: Torrent client connection was lost before candidate scan.');

            return;
        }

        $scanTo = now();
        $periodDays = $this->periodDays();
        $anchor = $this->getLastRun() ?? $scanTo;
        $from = $anchor->copy()->subDays($periodDays)->startOfDay();

        $episodes = Episode::whereBetween('firstaired', [$from->getTimestampMs(), $scanTo->getTimestampMs()])
            ->with('serie')
            ->get();

        foreach ($episodes as $episode) {
            if ($this->deadlineReached($deadline)) {
                return;
            }

            if ($client->isConnected() === false) {
                Log::warning('AutoDownload: Torrent client connection was lost during periodic scan.');

                return;
            }

            $this->processEpisode($episode, $deadline);

            if ($this->periodicAbortRequested) {
                return;
            }

            if ($client->isConnected() === false) {
                Log::warning('AutoDownload: Torrent client connection was lost during periodic scan.');

                return;
            }
        }

        if ($this->deadlineReached($deadline)) {
            return;
        }

        if ($client->isConnected() === false) {
            Log::warning('AutoDownload: Torrent client connection was lost before checkpoint update.');

            return;
        }

        $this->settings->set('autodownload.lastrun', $scanTo->getTimestampMs());
    }

    /**
     * Public wrapper for UI-triggered download.
     */
    public function manualDownload(Episode $episode): bool
    {
        $serie = $episode->serie;
        if (! $serie) {
            return false;
        }

        $searchString = $this->sceneNameResolver->getSearchStringForEpisode($serie, $episode);

        $torrentingEnabled = (bool) $this->settings->get('torrenting.enabled', true);
        if ($torrentingEnabled === false) {
            $this->logActivity($serie, $episode, $searchString, self::STATUS_AUTODL_DISABLED, ' (Torrenting disabled)');

            return false;
        }

        if ($episode->hasAired() === false && $episode->isLeaked() === false) {
            $this->logActivity($serie, $episode, $searchString, self::STATUS_ON_AIR_DELAY, ' (Episode not aired or leaked)');

            return false;
        }

        if (! $serie->tvdb_id) {
            $this->logActivity($serie, $episode, $searchString, self::STATUS_TVDB_ID_MISSING);

            return false;
        }

        if ($this->establishUsableClient() === null) {
            $this->logActivity($serie, $episode, $searchString, self::STATUS_INFRASTRUCTURE_FAILURE, ' (Torrent client unavailable)');

            return false;
        }

        return $this->performSearchAndDownload($serie, $episode, $searchString);
    }

    protected function processEpisode(Episode $episode, ?AutoDownloadDeadline $deadline = null): void
    {
        $serie = $episode->serie;
        if (! $serie) {
            return;
        }

        $searchString = $this->sceneNameResolver->getSearchStringForEpisode($serie, $episode);

        if ($episode->seasonnumber === 0 && ! $this->settings->get('calendar.show-specials') && ! $serie->ignoreHideSpecials) {
            $this->logActivity($serie, $episode, $searchString, self::STATUS_AUTODL_DISABLED, ' HS');

            return;
        }

        if (! $serie->displaycalendar) {
            $this->logActivity($serie, $episode, $searchString, self::STATUS_AUTODL_DISABLED, ' HC');

            return;
        }

        if ($episode->isDownloaded()) {
            $this->logActivity($serie, $episode, $searchString, self::STATUS_DOWNLOADED);

            return;
        }

        if ($episode->watchedAt !== null) {
            $this->logActivity($serie, $episode, $searchString, self::STATUS_WATCHED);

            return;
        }

        $storedHash = is_string($episode->magnetHash) ? MagnetUri::normalizeInfoHash($episode->magnetHash) : null;
        if ($storedHash !== null && array_key_exists($storedHash, $this->remoteTorrents)) {
            $this->logActivity($serie, $episode, $searchString, self::STATUS_HAS_MAGNET);

            return;
        }

        if (! $serie->autoDownload) {
            $this->logActivity($serie, $episode, $searchString, self::STATUS_AUTODL_DISABLED);

            return;
        }

        if (! $serie->tvdb_id) {
            $this->logActivity($serie, $episode, $searchString, self::STATUS_TVDB_ID_MISSING);

            return;
        }

        $settingsDelay = (int) $this->settings->get('autodownload.delay', 15);
        $delay = max(0, (int) ($serie->customDelay ?? $settingsDelay));
        $delay = min($delay, $this->periodDays() * 24 * 60);
        $runtime = $serie->runtime ?? 60;

        $airedAt = Carbon::createFromTimestampMs($episode->firstaired);
        $safeToDownload = $airedAt->copy()->addMinutes($runtime + $delay);

        if ($safeToDownload->isFuture()) {
            $diff = $safeToDownload->diffForHumans(['parts' => 2]);
            $this->logActivity($serie, $episode, $searchString, self::STATUS_ON_AIR_DELAY, " $diff");

            return;
        }

        if ($this->deadlineReached($deadline, $serie, $episode, $searchString)) {
            return;
        }

        $this->performSearchAndDownload($serie, $episode, $searchString, $deadline);
    }

    protected function performSearchAndDownload(
        Serie $serie,
        Episode $episode,
        string $searchString,
        ?AutoDownloadDeadline $deadline = null
    ): bool
    {
        $hasCustomSeeders = $serie->customSeeders !== null;
        $hasCustomIncludes = $serie->customIncludes !== null;
        $hasCustomExcludes = $serie->customExcludes !== null;

        $minSeeders = $hasCustomSeeders ? $serie->customSeeders : (int) $this->settings->get('torrenting.min_seeders', 50);
        $preferredQuality = $serie->ignoreGlobalQuality ? '' : $this->settings->get('torrenting.searchquality', '');

        $globalExcludes = $this->settings->get('torrenting.ignore_keywords', '');
        $ignoreKeywords = $hasCustomExcludes ? $serie->customExcludes.' '.$globalExcludes : $globalExcludes;
        if ($serie->ignoreGlobalExcludes) {
            $ignoreKeywords = $hasCustomExcludes ? $serie->customExcludes : '';
        }

        $globalIncludes = $this->settings->get('torrenting.require_keywords', '');
        $requireKeywords = $hasCustomIncludes ? $serie->customIncludes.' '.$globalIncludes : $globalIncludes;
        if ($serie->ignoreGlobalIncludes) {
            $requireKeywords = $hasCustomIncludes ? $serie->customIncludes : '';
        }

        $globalSizeMin = $this->settings->get('torrenting.global_size_min', 0);
        $globalSizeMax = $this->settings->get('torrenting.global_size_max', 10000);

        $requireKeywordsModeOR = $this->settings->get('torrenting.require_keywords_mode_or', true);
        $requireKeywordsString = $requireKeywordsModeOR ? '' : $requireKeywords;

        $q = trim("{$searchString} {$preferredQuality} {$requireKeywordsString}");

        if ($this->deadlineReached($deadline, $serie, $episode, $q)) {
            return false;
        }

        try {
            $results = $this->searchService->search($q, $serie->searchProvider);
        } catch (\Throwable) {
            Log::error('AutoDownload: Torrent search failed.');
            $this->logActivity($serie, $episode, $q, self::STATUS_INFRASTRUCTURE_FAILURE, ' (Search failed)');

            return false;
        }

        if ($this->deadlineReached($deadline, $serie, $episode, $q)) {
            return false;
        }

        if (empty($results)) {
            $this->logActivity($serie, $episode, $q, self::STATUS_NOTHING_FOUND);

            return false;
        }

        $results = array_values(array_filter($results, function (array $item) use ($q): bool {
            $name = $item['releasename'] ?? ($item['title'] ?? '');

            return $this->filterByScore($name, $q);
        }));

        if (empty($results)) {
            $this->logActivity($serie, $episode, $q, self::STATUS_NOTHING_FOUND);

            return false;
        }

        if ($requireKeywords !== '') {
            $results = array_values(array_filter($results, function (array $item) use ($requireKeywords, $requireKeywordsModeOR, $q): bool {
                $name = $item['releasename'] ?? ($item['title'] ?? '');

                return $this->filterKeywords($name, $requireKeywords, '', $requireKeywordsModeOR, $q);
            }));

            if (empty($results)) {
                $this->logActivity($serie, $episode, $q, self::STATUS_FILTERED_OUT, ' RK');

                return false;
            }
        }

        if ($ignoreKeywords !== '') {
            $results = array_values(array_filter($results, function (array $item) use ($ignoreKeywords, $requireKeywordsModeOR, $q): bool {
                $name = $item['releasename'] ?? ($item['title'] ?? '');

                return $this->filterKeywords($name, '', $ignoreKeywords, $requireKeywordsModeOR, $q);
            }));

            if (empty($results)) {
                $this->logActivity($serie, $episode, $q, self::STATUS_FILTERED_OUT, ' IK');

                return false;
            }
        }

        $sizeParseError = false;
        $results = array_values(array_filter($results, function (array $item) use ($serie, $globalSizeMin, $globalSizeMax, &$sizeParseError): bool {
            $itemSizeParseError = (bool) ($item['sizeParseError'] ?? false);
            $sizeParseError = $sizeParseError || $itemSizeParseError;
            $sizeBytes = isset($item['sizeBytes']) && is_int($item['sizeBytes']) ? $item['sizeBytes'] : null;

            return $this->filterBySize($sizeBytes, $itemSizeParseError, $serie, $globalSizeMin, $globalSizeMax);
        }));

        if (empty($results)) {
            $extra = $sizeParseError ? ' MS (size parse error)' : ' MS';
            $this->logActivity($serie, $episode, $q, self::STATUS_FILTERED_OUT, $extra);

            return false;
        }

        usort($results, fn (array $a, array $b): int => ($b['seeders'] ?? 0) <=> ($a['seeders'] ?? 0));
        $item = $results[0];
        $seeders = (int) ($item['seeders'] ?? 0);

        if ($seeders < $minSeeders) {
            $this->logActivity($serie, $episode, $q, self::STATUS_NOT_ENOUGH_SEEDERS, " $seeders < $minSeeders");

            return false;
        }

        if (empty($item['magnetUrl']) && empty($item['infoHash']) && ! empty($item['detailUrl'])) {
            if ($this->deadlineReached($deadline, $serie, $episode, $q)) {
                return false;
            }

            try {
                $engine = $serie->searchProvider
                    ? $this->searchService->getSearchEngine($serie->searchProvider)
                    : $this->searchService->getDefaultEngine();

                $details = $engine->getDetails(
                    (string) $item['detailUrl'],
                    (string) ($item['releasename'] ?? '')
                );
                $item = array_merge($item, $details);
            } catch (\Throwable) {
                Log::error('AutoDownload: Torrent details lookup failed.');
                $this->logActivity($serie, $episode, $q, self::STATUS_INFRASTRUCTURE_FAILURE, ' (Details lookup failed)');

                return false;
            }

            if ($this->deadlineReached($deadline, $serie, $episode, $q)) {
                return false;
            }
        }

        return $this->download($serie, $episode, $item, $q, $deadline);
    }

    protected function periodDays(): int
    {
        return max(1, min(21, (int) $this->settings->get('autodownload.period', 1)));
    }

    protected function establishUsableClient(): ?TorrentClientInterface
    {
        $client = $this->torrentClientService->getActiveClient();
        if ($client === null) {
            Log::warning('AutoDownload: No configured torrent client is available.');

            return null;
        }

        try {
            if ($client->connect() === false) {
                Log::warning('AutoDownload: Torrent client connection failed.', ['client' => $client->getName()]);

                return null;
            }
        } catch (\Throwable) {
            Log::error('AutoDownload: Torrent client connection failed.', ['client' => $client->getName()]);

            return null;
        }

        $this->activeClient = $client;

        return $client;
    }

    protected function loadRemoteTorrents(TorrentClientInterface $client): bool
    {
        try {
            $torrents = $client->getTorrents();
        } catch (\Throwable) {
            Log::error('AutoDownload: Unable to read torrents from the active client.', ['client' => $client->getName()]);

            return false;
        }

        foreach ($torrents as $torrent) {
            $rawHash = method_exists($torrent, 'getInfoHash')
                ? $torrent->getInfoHash()
                : ($torrent->infoHash ?? null);
            $infoHash = is_string($rawHash) ? MagnetUri::normalizeInfoHash($rawHash) : null;

            if ($infoHash !== null) {
                $this->remoteTorrents[$infoHash] = $torrent;
            }
        }

        return true;
    }

    protected function deadlineReached(
        ?AutoDownloadDeadline $deadline,
        ?Serie $serie = null,
        ?Episode $episode = null,
        string $search = ''
    ): bool {
        if ($deadline === null || $deadline->expired() === false) {
            return false;
        }

        $this->periodicAbortRequested = true;
        Log::warning('AutoDownload: Cooperative scan deadline reached; checkpoint will not advance.');

        if ($serie !== null && $episode !== null) {
            $this->logActivity(
                $serie,
                $episode,
                $search,
                self::STATUS_INFRASTRUCTURE_FAILURE,
                ' (Scan deadline reached)'
            );
        }

        return true;
    }

    protected function filterByScore(string $name, string $query): bool
    {
        $queryParts = explode(' ', strtolower($query));
        $lowerName = strtolower($name);

        foreach ($queryParts as $part) {
            if (empty(trim($part))) {
                continue;
            }
            if (str_contains($lowerName, $part)) {
                continue;
            }

            return false;
        }

        return true;
    }

    protected function filterKeywords(string $name, string $require, string $ignore, bool $orMode, string $q): bool
    {
        $lowerName = strtolower($name);
        $lowerQuery = strtolower($q);

        if ($require !== '') {
            $requiredParts = collect(explode(' ', strtolower($require)))->filter();
            $matches = $requiredParts->filter(fn ($part) => str_contains($lowerName, $part))->count();

            if ($orMode && $matches === 0) {
                return false;
            }
            if (! $orMode && $matches < $requiredParts->count()) {
                return false;
            }
        }

        if ($ignore !== '') {
            $hasIgnoredKeyword = collect(explode(' ', strtolower($ignore)))
                ->filter()
                ->reject(fn ($part) => str_contains($lowerQuery, $part)) // Prevent exclude list from overriding primary search string
                ->contains(fn ($part) => str_contains($lowerName, $part));

            if ($hasIgnoredKeyword) {
                return false;
            }
        }

        return true;
    }

    protected function filterBySize(?int $sizeBytes, bool $sizeParseError, Serie $serie, $globalMin, $globalMax): bool
    {
        if ($sizeParseError) {
            return false;
        }

        // A genuinely unknown source size remains eligible, matching historical behavior.
        if ($sizeBytes === null) {
            return true;
        }

        $minMb = $serie->customSearchSizeMin ?? ($globalMin === null ? null : (int) $globalMin);
        $maxMb = $serie->customSearchSizeMax ?? ($globalMax === null ? null : (int) $globalMax);

        $minBytes = ($minMb ?? 0) * 1_000_000;
        $maxBytes = $maxMb === null ? PHP_INT_MAX : $maxMb * 1_000_000;

        return $sizeBytes >= $minBytes && $sizeBytes <= $maxBytes;
    }

    protected function download(
        Serie $serie,
        Episode $episode,
        array $item,
        string $searchQuery,
        ?AutoDownloadDeadline $deadline = null
    ): bool
    {
        $magnetUrl = isset($item['magnetUrl']) && is_string($item['magnetUrl']) && $item['magnetUrl'] !== ''
            ? $item['magnetUrl']
            : null;
        $torrentUrl = isset($item['torrentUrl']) && is_string($item['torrentUrl']) && $item['torrentUrl'] !== ''
            ? $item['torrentUrl']
            : null;
        $providedHash = isset($item['infoHash']) && is_string($item['infoHash']) ? $item['infoHash'] : null;
        $infoHash = $magnetUrl !== null
            ? MagnetUri::extractInfoHash($magnetUrl)
            : MagnetUri::normalizeInfoHash($providedHash);

        if ($infoHash === null) {
            $this->logActivity($serie, $episode, $searchQuery, self::STATUS_NOTHING_FOUND, ' (Missing torrent identity)');

            return false;
        }

        if ($this->deadlineReached($deadline, $serie, $episode, $searchQuery)) {
            return false;
        }

        $label = $this->settings->get('torrenting.label') ? $serie->name : 'DuckieTV';
        $client = $this->activeClient ?? $this->torrentClientService->getActiveClient();
        if ($client === null || $client->isConnected() === false) {
            $this->logActivity($serie, $episode, $searchQuery, self::STATUS_INFRASTRUCTURE_FAILURE, ' (Torrent client unavailable)');

            return false;
        }

        try {
            if ($magnetUrl !== null) {
                $launched = $client->addMagnet($magnetUrl, $serie->dlPath, $label);
            } elseif ($torrentUrl !== null) {
                $releaseName = isset($item['releasename']) && is_string($item['releasename']) ? $item['releasename'] : '';
                $launched = $client->addTorrentByUrl($torrentUrl, $infoHash, $releaseName, $serie->dlPath, $label);
            } else {
                $launched = false;
            }
        } catch (\Throwable) {
            Log::error('AutoDownload: Torrent client launch failed.', ['client' => $client->getName()]);
            $this->logActivity($serie, $episode, $searchQuery, self::STATUS_INFRASTRUCTURE_FAILURE, ' (Torrent client error)');

            return false;
        }

        if ($launched === false) {
            $this->logActivity($serie, $episode, $searchQuery, self::STATUS_INFRASTRUCTURE_FAILURE, ' (Torrent client rejected launch)');

            return false;
        }

        $episode->magnetHash = $infoHash;
        $episode->save();
        $this->logActivity($serie, $episode, $searchQuery, self::STATUS_TORRENT_LAUNCHED);

        return true;
    }
}
