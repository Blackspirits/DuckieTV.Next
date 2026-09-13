<?php

namespace App\Services;

use App\Models\Episode;
use App\Models\Jackett;
use App\Models\Serie;
use Illuminate\Support\Facades\Log;

/**
 * BackupService - Handles backup creation and restoration.
 *
 * Ported/Adapted from DuckieTV Angular BackupService.js.
 */
class BackupService
{
    private SettingsService $settings;

    private FavoritesService $favorites;

    private TraktService $trakt;

    public function __construct(SettingsService $settings, FavoritesService $favorites, TraktService $trakt)
    {
        $this->settings = $settings;
        $this->favorites = $favorites;
        $this->trakt = $trakt;
    }

    /**
     * Create a backup using the post-1.1.5 Trakt-ID format from the original
     * DuckieTV backup contract.
     *
     * @return array{settings: array<string, mixed>, series: array<string, array<int, array<string, mixed>>>}
     */
    public function createBackup(): array
    {
        $settings = $this->settings->all();

        foreach (array_keys($settings) as $key) {
            if ($this->shouldExcludeSettingFromBackup($key)) {
                unset($settings[$key]);
            }
        }

        $jackettRows = [];
        foreach (Jackett::query()->orderBy('id')->get() as $jackett) {
            $jackettRows[] = [
                'name' => $jackett->name,
                'torznab' => $jackett->torznab,
                'enabled' => (int) $jackett->enabled,
                'torznabEnabled' => (int) $jackett->torznabEnabled,
                'apiKey' => $jackett->apiKey,
                'json' => $jackett->json,
            ];
        }

        $settings['useTrakt_id'] = true;
        $settings['jackett'] = json_encode(
            $jackettRows,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        );

        $series = [];

        foreach (Serie::query()->whereNotNull('trakt_id')->orderBy('id')->get() as $serie) {
            $entries = [[
                'displaycalendar' => (int) $serie->displaycalendar,
                'autoDownload' => (int) $serie->autoDownload,
                'customSearchString' => $serie->customSearchString,
                'ignoreGlobalQuality' => (int) $serie->ignoreGlobalQuality,
                'ignoreGlobalIncludes' => (int) $serie->ignoreGlobalIncludes,
                'ignoreGlobalExcludes' => (int) $serie->ignoreGlobalExcludes,
                'searchProvider' => $serie->searchProvider,
                'ignoreHideSpecials' => (int) $serie->ignoreHideSpecials,
                'customSearchSizeMin' => $serie->customSearchSizeMin,
                'customSearchSizeMax' => $serie->customSearchSizeMax,
                'dlPath' => $serie->dlPath,
                'customDelay' => $serie->customDelay,
                'alias' => $serie->alias,
                'customFormat' => $serie->customFormat,
                'customIncludes' => $serie->customIncludes,
                'customExcludes' => $serie->customExcludes,
                'customSeeders' => $serie->customSeeders,
            ]];

            foreach ($serie->episodes()->orderBy('id')->get() as $episode) {
                if ((int) $episode->downloaded !== 1 && $episode->watchedAt === null) {
                    continue;
                }

                $entry = [
                    'watchedAt' => $episode->watchedAt,
                    'downloaded' => (int) $episode->downloaded,
                ];

                if ($episode->trakt_id) {
                    $entry['TRAKT_ID'] = (int) $episode->trakt_id;
                } elseif ($episode->tvdb_id) {
                    $entry['TVDB_ID'] = (int) $episode->tvdb_id;
                } else {
                    continue;
                }

                $entries[] = $entry;
            }

            $series[(string) $serie->trakt_id] = $entries;
        }

        return [
            'settings' => $settings,
            'series' => $series,
        ];
    }

    private function shouldExcludeSettingFromBackup(string $key): bool
    {
        return str_contains($key, 'database.version')
            || str_contains($key, 'trakttv.trending.cache')
            || str_contains($key, 'trakttv.lastupdated.trending')
            || str_starts_with($key, 'snrt.');
    }

    /**
     * Restore from a backup data array.
     *
     * @param  array  $data  Parsed JSON backup data
     * @param  callable|null  $onProgress  function($percent, $message|array)
     * @return array Result stats ['series_restored' => int]
     */
    public function restore(array $data, ?callable $onProgress = null): array
    {
        $stats = ['series_restored' => 0];

        if ($onProgress) {
            $onProgress(1, 'Initializing restore...');
        }

        // 1. Restore Settings
        if (isset($data['settings']) && is_array($data['settings'])) {
            if ($onProgress) {
                $onProgress(5, 'Restoring application settings...');
            }

            $this->restoreJackettSettings($data['settings']);
            $this->settings->restoreSettings($data['settings']);

            if ($onProgress) {
                $onProgress(10, 'Settings restored.');
            }
        }

        // 2. Restore Series
        if (isset($data['series']) && is_array($data['series'])) {
            if ($onProgress) {
                $onProgress(10, 'Starting series restoration...');
            }

            $useTraktId = (bool) ($data['settings']['useTrakt_id'] ?? false);
            $stats['series_restored'] = $this->restoreSeries($data['series'], $onProgress, $useTraktId);
        }

        if ($onProgress) {
            $onProgress(100, 'Restore complete!');
        }

        return $stats;
    }

    /**
     * Restore a single show from backup data.
     *
     * IMPORTANT: The Trakt API call is performed OUTSIDE any database transaction
     * to avoid holding SQLite write locks during network I/O. With SQLite's
     * single-writer model, a long-held transaction blocks all other writers
     * (including the queue worker trying to reserve/complete jobs).
     *
     * The favorite graph itself is persisted atomically by FavoritesService.
     * Network resolution/fetching remains outside that SQLite write transaction.
     *
     * @param  string  $id  Series ID from the backup key
     * @param  array  $backupData  The array of watched episodes + custom settings
     * @param  bool  $useTraktId  True for post-1.1.5 backups; false for legacy TVDB-keyed backups
     * @return bool Success
     */
    public function restoreShow(
        string $id,
        array $backupData,
        ?callable $onProgress = null,
        bool $useTraktId = false
    ): bool
    {
        try {
            if ($onProgress) {
                $onProgress(0, "Fetching data for series ID: {$id}...");
            }

            // 1. Resolve legacy TVDB-keyed backups when needed and fetch Trakt
            //    data OUTSIDE any database transaction.
            $traktData = $this->trakt->withThrottling(function () use ($id, $useTraktId): array {
                $traktId = $id;

                if (! $useTraktId) {
                    $resolved = $this->trakt->resolveID($id, false);
                    $resolvedTraktId = $resolved['trakt_id'] ?? null;

                    if (! is_numeric($resolvedTraktId)) {
                        throw new \RuntimeException("Unable to resolve TVDB series ID {$id} to Trakt.");
                    }

                    $traktId = (string) $resolvedTraktId;
                }

                return $this->trakt->serie($traktId);
            });
            $name = $traktData['title'] ?? "Series #{$id}";

            if ($onProgress) {
                $onProgress(10, "Restoring: {$name}...");
            }

            $customSettings = $backupData[0] ?? [];
            $watchedData = $backupData;

            // 2. Persist the favorite graph atomically. FavoritesService keeps
            //    this local transaction separate from the network work above.
            $serie = $this->favorites->addFavorite($traktData, $watchedData, false, function ($processed, $totalEpisodes, $season) use ($onProgress, $name) {
                if ($onProgress) {
                    $percent = $totalEpisodes > 0 ? round(($processed / $totalEpisodes) * 90) : 0;
                    $onProgress($percent, [
                        'type' => 'show_progress',
                        'show' => $name,
                        'processed' => $processed,
                        'total' => $totalEpisodes,
                        'season' => $season,
                        'message' => "Restoring {$name} - Season {$season}: {$processed}/{$totalEpisodes}",
                    ]);
                }
            });

            // 3. Apply Custom Settings (another quick save)
            if (! empty($customSettings) && ! isset($customSettings['TVDB_ID'])) {
                $this->applyCustomSettings($serie, $customSettings);
            }

            if ($onProgress) {
                $onProgress(100, [
                    'type' => 'show_completed',
                    'show' => $name,
                    'poster' => $serie->poster,
                    'message' => "Restored: {$name}",
                ]);
            }

            return true;

        } catch (\Exception $e) {
            Log::error("BackupService: Failed to restore series [{$id}]: ".$e->getMessage());
            if ($onProgress) {
                $onProgress(0, "ERROR: Failed to restore series ID {$id}: ".$e->getMessage());
            }
            throw $e; // Re-throw to fail the job
        }
    }

    /**
     * Iterate through series in backup and restore them.
     * DEPRECATED: Use restoreShow via Jobs instead for large backups.
     * Keeping for synchronous fallback if needed.
     */
    private function restoreSeries(
        array $seriesMap,
        ?callable $onProgress = null,
        bool $useTraktId = false
    ): int
    {
        $count = 0;
        $total = count($seriesMap);
        $current = 0;

        foreach ($seriesMap as $id => $backupData) {
            $current++;
            $progress = 10 + (int) (($current / $total) * 85);
            try {
                $this->restoreShow(
                    (string) $id,
                    $backupData,
                    function ($p, $msg) use ($onProgress, $progress) {
                        // Adapt single-show progress to global progress check if needed
                        // For now we just pass the main message up
                        if ($onProgress && is_string($msg)) {
                            $onProgress($progress, $msg);
                        }
                    },
                    $useTraktId
                );
                $count++;
            } catch (\Exception $e) {
                // Continue with next
            }
        }

        return $count;
    }

    private function restoreJackettSettings(array $settings): void
    {
        if (! array_key_exists('jackett', $settings)) {
            return;
        }

        $rows = $settings['jackett'];
        if (is_string($rows)) {
            $rows = json_decode($rows, true);
        }

        if (! is_array($rows)) {
            Log::warning('BackupService: Ignoring invalid Jackett backup payload.');

            return;
        }

        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['name'])) {
                continue;
            }

            $json = $row['json'] ?? null;
            if (is_string($json)) {
                $decoded = json_decode($json, true);
                $json = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
            }

            Jackett::updateOrCreate(
                ['name' => (string) $row['name']],
                [
                    'torznab' => $row['torznab'] ?? null,
                    'enabled' => (int) ($row['enabled'] ?? 0),
                    'torznabEnabled' => (int) ($row['torznabEnabled'] ?? 0),
                    'apiKey' => $row['apiKey'] ?? null,
                    'json' => is_array($json) ? $json : null,
                ]
            );
        }
    }

    private function applyCustomSettings(Serie $serie, array $settings): void
    {
        // Post-1.1.4 backup keys map directly to the persisted series columns.
        // Use array_key_exists so a backup can deliberately restore nullable fields to null.
        $map = [
            'displaycalendar' => 'displaycalendar',
            'autoDownload' => 'autoDownload',
            'customSearchString' => 'customSearchString',
            'ignoreGlobalQuality' => 'ignoreGlobalQuality',
            'ignoreGlobalIncludes' => 'ignoreGlobalIncludes',
            'ignoreGlobalExcludes' => 'ignoreGlobalExcludes',
            'searchProvider' => 'searchProvider',
            'ignoreHideSpecials' => 'ignoreHideSpecials',
            'customSearchSizeMin' => 'customSearchSizeMin',
            'customSearchSizeMax' => 'customSearchSizeMax',
            'dlPath' => 'dlPath',
            'customDelay' => 'customDelay',
            'alias' => 'alias',
            'customFormat' => 'customFormat',
            'customIncludes' => 'customIncludes',
            'customExcludes' => 'customExcludes',
            'customSeeders' => 'customSeeders',
        ];

        $dirty = false;
        foreach ($map as $backupKey => $modelKey) {
            if (array_key_exists($backupKey, $settings)) {
                $serie->$modelKey = $settings[$backupKey];
                $dirty = true;
            }
        }

        if ($dirty) {
            $serie->save();
        }
    }
}
