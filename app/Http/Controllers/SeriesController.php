<?php

namespace App\Http\Controllers;

use App\Exceptions\RateLimitException;
use App\Models\Episode;
use App\Models\Season;
use App\Services\DatabaseMaintenanceLock;
use App\Services\FavoritesService;
use App\Services\SceneNameResolverService;
use App\Services\SeriesRefreshService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SeriesController extends Controller
{
    protected FavoritesService $favorites;

    protected SceneNameResolverService $sceneNameResolver;

    protected SeriesRefreshService $seriesRefresh;

    public function __construct(
        FavoritesService $favorites,
        SceneNameResolverService $sceneNameResolver,
        SeriesRefreshService $seriesRefresh
    ) {
        $this->favorites = $favorites;
        $this->sceneNameResolver = $sceneNameResolver;
        $this->seriesRefresh = $seriesRefresh;
    }

    /**
     * List all favorite series.
     * Returns a dataset that can be rendered on both web and TUI.
     */
    public function index(Request $request)
    {
        $series = $this->favorites->getFilteredSeries($request->all());
        $genres = $this->favorites->getUniqueGenres();
        $statuses = $this->favorites->getUniqueStatuses();

        if ($request->ajax()) {
            return view('series.partial', compact('series', 'genres', 'statuses'));
        }

        return view('series.index', compact('series', 'genres', 'statuses'));
    }

    /**
     * Show details for a specific serie.
     * Includes seasons and episodes.
     */
    public function show(Request $request, int $id)
    {
        $serie = $this->favorites->getById($id);

        if (! $serie) {
            return abort(404, 'Show not found');
        }

        // Ensure seasons and episodes are loaded for TUI/Web
        $serie->load(['seasons.episodes']);

        if ($request->ajax()) {
            return view('series._details', compact('serie'));
        }

        return view('series.show', compact('serie'));
    }

    /**
     * Show full details for a specific serie.
     * Ported from serie-details.html logic.
     */
    public function details(int $id)
    {
        $serie = $this->favorites->getById($id);

        if (! $serie) {
            return abort(404, 'Show not found');
        }

        return view('series._details_full', compact('serie'));
    }

    /**
     * Show the seasons grid for a specific serie.
     * Ported from seasons.html logic — shows clickable season posters.
     * Displayed in the sidepanel right panel via data-sidepanel-expand.
     *
     * @see templates/sidepanel/seasons.html in DuckieTV-angular
     */
    public function seasons(int $id)
    {
        $serie = $this->favorites->getById($id);

        if (! $serie) {
            return abort(404, 'Show not found');
        }

        $serie->load(['seasons' => fn ($q) => $q->orderBy('seasonnumber')]);

        return view('series._seasons', compact('serie'));
    }

    /**
     * Show episodes list for a specific serie, optionally filtered to a single season.
     * Ported from episodes.html logic — shows one season at a time with navigation.
     *
     * When season_id is provided (e.g., from seasons grid click), shows that season.
     * Otherwise, follows the historical series.not-watched-eps-btn preference:
     * first unwatched season when enabled, active aired season when disabled.
     *
     * @see templates/sidepanel/episodes.html in DuckieTV-angular
     */
    public function episodes(int $id, ?int $season_id = null)
    {
        $serie = $this->favorites->getById($id);

        if (! $serie) {
            return abort(404, 'Show not found');
        }

        $serie->load(['seasons.episodes']);

        // Determine which season to display
        $seasons = $serie->seasons->sortBy('seasonnumber');
        $activeSeason = null;

        if ($season_id) {
            foreach ($seasons as $season) {
                if ($season->id === $season_id) {
                    $activeSeason = $season;
                    break;
                }
            }
        }

        if ($activeSeason === null) {
            $activeSeason = (bool) settings()->get('series.not-watched-eps-btn', false)
                ? $serie->getNotWatchedSeason()
                : $serie->getActiveSeason();
        }

        if ($activeSeason === null) {
            abort(404, 'Season not found');
        }

        // Pre-calculate search queries for episodes
        foreach ($activeSeason->episodes as $episode) {
            $episode->setAttribute(
                'search_query',
                $this->sceneNameResolver->getSearchStringForEpisode($serie, $episode)
            );
        }

        // Calculate search query for the whole season
        $seasonSearchQuery = ($serie->customSearchString ?: $serie->name).' season '.$activeSeason->seasonnumber;

        // Calculate ratings data for the chart
        $ratingEpisodes = [];
        foreach ($activeSeason->episodes as $episode) {
            $ratingEpisodes[] = $episode;
        }

        usort(
            $ratingEpisodes,
            static fn (Episode $left, Episode $right): int => ($left->episodenumber ?? 0) <=> ($right->episodenumber ?? 0)
        );

        $ratingPoints = [];
        foreach ($ratingEpisodes as $episode) {
            $ratingPoints[] = [
                'y' => $episode->rating ?? 0,
                'label' => $episode->formatted_episode.' : '.($episode->rating ?? 0).'% ('.($episode->ratingcount ?? 0).' '.__('votes').')',
            ];
        }

        return view('series._episodes', [
            'serie' => $serie,
            'seasons' => $seasons,
            'activeSeason' => $activeSeason,
            'seasonSearchQuery' => $seasonSearchQuery,
            'ratingPoints' => $ratingPoints,
        ]);
    }

    /**
     * Update series state (mark watched, toggle auto-download, toggle calendar).
     *
     * Handles multiple actions via the 'action' input parameter:
     * - mark_watched: Marks all aired episodes as watched
     * - toggle_autodownload: Toggles the autoDownload flag
     * - toggle_calendar: Toggles the displaycalendar flag (show/hide from calendar)
     *
     * @see serieSidepanelCtrl in DuckieTV-angular for original action handlers
     */
    public function update(Request $request, int $id)
    {
        $serie = $this->favorites->getById($id);

        if (! $serie) {
            return abort(404, 'Show not found');
        }

        $action = $request->input('action');
        $watchedDownloadedPaired = (bool) settings()->get('episode.watched-downloaded.pairing', true);

        if ($action === 'mark_watched') {
            $serie->markSerieAsWatched($watchedDownloadedPaired);
        } elseif ($action === 'mark_downloaded') {
            $serie->markSerieAsDownloaded();
        } elseif ($action === 'toggle_autodownload') {
            $serie->toggleAutoDownload();
        } elseif ($action === 'toggle_calendar') {
            $serie->toggleCalendarDisplay();
        } elseif ($action === 'mark_season_watched') {
            $seasonId = $request->input('season_id');
            $season = $serie->seasons()->find($seasonId);
            if ($season instanceof Season) {
                foreach ($season->episodes as $episode) {
                    if ($episode->hasAired()) {
                        $episode->markWatched($watchedDownloadedPaired);
                    }
                }
            }
        } elseif ($action === 'mark_season_downloaded') {
            $seasonId = $request->input('season_id');
            $season = $serie->seasons()->find($seasonId);
            if ($season instanceof Season) {
                foreach ($season->episodes as $episode) {
                    if ($episode->hasAired()) {
                        $episode->markDownloaded();
                    }
                }
            }
        }

        if ($request->ajax()) {
            return response()->json(['status' => 'ok']);
        }

        return redirect()->back()->with('status', "Updated {$serie->name}.");
    }

    /**
     * Refresh a favorite from Trakt and persist the full current series data.
     *
     * Mirrors the historical FavoritesManager.refresh flow while preserving
     * local-only series settings through FavoritesService's existing update path.
     */
    public function refresh(int $id, DatabaseMaintenanceLock $maintenanceLock)
    {
        $lockOwner = $maintenanceLock->acquire();

        if ($lockOwner === null) {
            return redirect()->back()->with(
                'error',
                'Another database maintenance operation is already running.'
            );
        }

        try {
            $serie = $this->favorites->getById($id);

            if (! $serie) {
                return abort(404, 'Show not found');
            }

            if (! $serie->trakt_id) {
                return redirect()->back()->with('error', "Cannot refresh {$serie->name}: missing Trakt ID.");
            }

            try {
                $updated = $this->seriesRefresh->refresh($serie);

                return redirect()->back()->with('status', "Refreshed {$updated->name}.");
            } catch (RateLimitException $e) {
                Log::info('Series refresh deferred by Trakt rate limit.', [
                    'serie_id' => $serie->id,
                    'trakt_id' => $serie->trakt_id,
                    'retry_after' => $e->retryAfter,
                ]);

                return redirect()->back()->with(
                    'error',
                    "Trakt is temporarily unavailable. Try again in {$e->retryAfter} seconds."
                );
            } catch (\Throwable $e) {
                Log::error('Series refresh failed.', [
                    'serie_id' => $serie->id,
                    'trakt_id' => $serie->trakt_id,
                    'exception' => $e::class,
                ]);

                return redirect()->back()->with('error', "Failed to refresh {$serie->name}.");
            }
        } finally {
            $maintenanceLock->release($lockOwner);
        }
    }

    /**
     * Remove a serie from favorites.
     */
    public function remove(int $id)
    {
        $serie = $this->favorites->getById($id);

        if ($serie) {
            $this->favorites->remove($serie);

            return redirect()->route('series.index')->with('status', "Removed {$serie->name} from favorites.");
        }

        return redirect()->route('series.index')->with('error', 'Show not found.');
    }
}
