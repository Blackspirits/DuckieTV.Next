<?php

use App\Jobs\TraktUpdateJob;
use App\Models\Serie;
use App\Services\FavoritesService;
use App\Services\SettingsService;
use App\Services\TraktService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('can be dispatched to a queue', function () {
    Queue::fake();

    TraktUpdateJob::dispatch();

    Queue::assertPushed(TraktUpdateJob::class);
});

it('serializes a release-safe retry policy', function () {
    config([
        'queue.default' => 'database',
        'queue.connections.database.connection' => config('database.default'),
    ]);

    TraktUpdateJob::dispatch();

    $payload = json_decode(
        (string) DB::table('jobs')->value('payload'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    expect($payload['maxTries'])->toBe(0)
        ->and($payload['maxExceptions'])->toBe(1)
        ->and($payload['retryUntil'])->toBeNull();
});

it('releases on series rate limit without marking the update complete', function () {
    Cache::forget('trakt_blocked_until');

    $settings = app(SettingsService::class);
    $settings->set('trakt-update.period', 1);
    $previousShowUpdate = now()->subHours(2)->getTimestampMs();
    $settings->set('trakttv.lastupdated', $previousShowUpdate);
    $settings->set('trakttv.lastupdated.trending', now()->getTimestampMs());

    Serie::create([
        'name' => 'Breaking Bad',
        'trakt_id' => 1388,
        'tvdb_id' => 81189,
        'lastupdated' => '2020-01-01T00:00:00.000Z',
    ]);

    Http::fake([
        'api.trakt.tv/shows/1388?extended=full*' => Http::response([], 429, [
            'Retry-After' => '17',
        ]),
    ]);

    $job = (new TraktUpdateJob)->withFakeQueueInteractions();
    $job->handle(
        app(TraktService::class),
        app(FavoritesService::class),
        $settings,
    );

    $job->assertReleased(17);
    expect((int) $settings->get('trakttv.lastupdated'))->toBe($previousShowUpdate);
    Http::assertSentCount(1);

    Cache::forget('trakt_blocked_until');
});

it('releases on trending rate limit without advancing the trending timestamp', function () {
    Cache::forget('trakt_blocked_until');

    $settings = app(SettingsService::class);
    $recentShowUpdate = now()->getTimestampMs();
    $settings->set('trakttv.lastupdated', $recentShowUpdate);
    $settings->set('trakttv.lastupdated.trending', 0);

    Http::fake([
        'api.trakt.tv/shows/trending?extended=full*' => Http::response([], 429, [
            'Retry-After' => '23',
        ]),
    ]);

    $job = (new TraktUpdateJob)->withFakeQueueInteractions();
    $job->handle(
        app(TraktService::class),
        app(FavoritesService::class),
        $settings,
    );

    $job->assertReleased(23);
    expect((int) $settings->get('trakttv.lastupdated'))->toBe($recentShowUpdate)
        ->and((int) $settings->get('trakttv.lastupdated.trending'))->toBe(0);
    Http::assertSentCount(1);

    Cache::forget('trakt_blocked_until');
});

it('matches the historical first run by initializing lastupdated without refreshing favorites', function () {
    $settings = app(SettingsService::class);
    $settings->set('trakttv.lastupdated', 0);
    $settings->set('trakttv.lastupdated.trending', now()->getTimestampMs());

    Serie::create([
        'name' => 'First Run Show',
        'trakt_id' => 4242,
        'tvdb_id' => 4242,
        'lastupdated' => '2020-01-01T00:00:00.000Z',
    ]);

    Http::fake();

    $before = now()->getTimestampMs();

    $job = new TraktUpdateJob;
    $job->handle(
        app(TraktService::class),
        app(FavoritesService::class),
        $settings,
    );

    $after = now()->getTimestampMs();

    expect((int) $settings->get('trakttv.lastupdated'))
        ->toBeGreaterThanOrEqual($before)
        ->toBeLessThanOrEqual($after);

    Http::assertNothingSent();
});

it('skips update when recently run', function () {
    $settings = app(SettingsService::class);
    $settings->set('trakttv.lastupdated', now()->getTimestampMs());
    $settings->set('trakttv.lastupdated.trending', now()->getTimestampMs());

    Http::fake();

    $job = new TraktUpdateJob;
    $job->handle(
        app(TraktService::class),
        app(FavoritesService::class),
        $settings,
    );

    // No HTTP calls should have been made
    Http::assertNothingSent();
});

it('updates a favorite show when trakt has newer data', function () {
    $settings = app(SettingsService::class);
    $settings->set('trakt-update.period', 1);
    // Set last update to a long time ago
    $settings->set('trakttv.lastupdated', now()->subHours(2)->getTimestampMs());
    // Set trending update to now (skip trending check)
    $settings->set('trakttv.lastupdated.trending', now()->getTimestampMs());

    // Create a local serie with old lastupdated
    $serie = Serie::create([
        'name' => 'Breaking Bad',
        'trakt_id' => 1388,
        'tvdb_id' => 81189,
        'lastupdated' => '2020-01-01T00:00:00.000Z',
    ]);

    Http::fake([
        // Summary check returns newer updated_at
        'api.trakt.tv/shows/1388?extended=full*' => Http::response([
            'title' => 'Breaking Bad',
            'updated_at' => '2025-01-01T00:00:00.000Z',
            'ids' => ['trakt' => 1388, 'tvdb' => 81189, 'tmdb' => 1396, 'imdb' => 'tt0903747'],
            'certification' => 'TV-MA',
            'airs' => ['day' => 'Sunday', 'time' => '21:00', 'timezone' => 'America/New_York'],
            'first_aired' => '2008-01-20T00:00:00.000Z',
            'genres' => ['drama'],
            'runtime' => 60,
            'status' => 'ended',
            'overview' => 'Updated overview.',
            'network' => 'AMC',
            'rating' => 9.3,
            'votes' => 60000,
        ]),
        // People
        'api.trakt.tv/shows/1388/people*' => Http::response([
            'cast' => [['person' => ['name' => 'Bryan Cranston'], 'character' => 'Walter White']],
        ]),
        // Seasons
        'api.trakt.tv/shows/1388/seasons?*' => Http::response([
            ['number' => 1, 'ids' => ['trakt' => 3950, 'tvdb' => 30272, 'tmdb' => 3572]],
        ]),
        // Episodes
        'api.trakt.tv/shows/1388/seasons/1/episodes*' => Http::response([
            ['number' => 1, 'title' => 'Pilot', 'ids' => ['trakt' => 62085], 'first_aired' => '2008-01-20T02:00:00.000Z', 'rating' => 8.8, 'votes' => 5000],
        ]),
    ]);

    $job = new TraktUpdateJob;
    $job->handle(
        app(TraktService::class),
        app(FavoritesService::class),
        $settings,
    );

    // The serie should have been updated
    $updated = Serie::find($serie->id);
    expect($updated->overview)->toBe('Updated overview.')
        ->and($updated->actors)->toContain('Bryan Cranston');

    // trakttv.lastupdated should be refreshed
    expect((int) $settings->get('trakttv.lastupdated'))->toBeGreaterThan(0);
});

it('skips shows that havent been updated on trakt', function () {
    $settings = app(SettingsService::class);
    $settings->set('trakt-update.period', 1);
    $settings->set('trakttv.lastupdated', now()->subHours(2)->getTimestampMs());
    $settings->set('trakttv.lastupdated.trending', now()->getTimestampMs());

    Serie::create([
        'name' => 'Old Show',
        'trakt_id' => 999,
        'tvdb_id' => 999,
        'lastupdated' => '2025-12-01T00:00:00.000Z',
    ]);

    Http::fake([
        'api.trakt.tv/shows/999?extended=full*' => Http::response([
            'title' => 'Old Show',
            'updated_at' => '2025-01-01T00:00:00.000Z', // older than local
            'ids' => ['trakt' => 999, 'tvdb' => 999],
        ]),
    ]);

    $job = new TraktUpdateJob;
    $job->handle(
        app(TraktService::class),
        app(FavoritesService::class),
        $settings,
    );

    // Only 1 API call (the summary check), no full fetch
    Http::assertSentCount(1);
});
