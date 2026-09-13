<?php

use App\Models\Episode;
use App\Models\Serie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

function refreshedSeriesFixture(): array
{
    return [
        'title' => 'Breaking Bad',
        'updated_at' => '2026-09-13T10:00:00.000Z',
        'ids' => [
            'trakt' => 1388,
            'tvdb' => 81189,
            'tmdb' => 1396,
            'imdb' => 'tt0903747',
        ],
        'certification' => 'TV-MA',
        'airs' => [
            'day' => 'Sunday',
            'time' => '21:00',
            'timezone' => 'America/New_York',
        ],
        'first_aired' => '2008-01-20T00:00:00.000Z',
        'genres' => ['drama'],
        'runtime' => 60,
        'status' => 'ended',
        'overview' => 'Fresh Trakt overview.',
        'network' => 'AMC',
        'country' => 'us',
        'language' => 'en',
        'rating' => 9.3,
        'votes' => 60000,
    ];
}

it('refreshes a favorite from trakt and preserves local series settings', function () {
    $serie = Serie::create([
        'name' => 'Breaking Bad',
        'trakt_id' => 1388,
        'tvdb_id' => 81189,
        'tmdb_id' => 1396,
        'overview' => 'Stale overview.',
        'fanart' => 'https://example.test/existing.jpg',
        'lastupdated' => '2020-01-01T00:00:00.000Z',
        'autoDownload' => false,
        'displaycalendar' => false,
        'customSearchString' => 'local-only search',
    ]);

    Http::fake([
        'api.trakt.tv/shows/1388?extended=full*' => Http::response(refreshedSeriesFixture()),
        'api.trakt.tv/shows/1388/people*' => Http::response([
            'cast' => [
                ['person' => ['name' => 'Bryan Cranston'], 'character' => 'Walter White'],
            ],
        ]),
        'api.trakt.tv/shows/1388/seasons?*' => Http::response([
            [
                'number' => 1,
                'ids' => ['trakt' => 3950, 'tvdb' => 30272, 'tmdb' => 3572],
                'overview' => 'Season 1',
                'rating' => 8.5,
                'votes' => 1000,
            ],
        ]),
        'api.trakt.tv/shows/1388/seasons/1/episodes*' => Http::response([
            [
                'number' => 1,
                'title' => 'Pilot',
                'ids' => ['trakt' => 62085, 'tvdb' => 349232, 'tmdb' => 62085],
                'first_aired' => '2008-01-20T02:00:00.000Z',
                'rating' => 8.8,
                'votes' => 5000,
            ],
        ]),
    ]);

    $response = $this
        ->from(route('series.show', $serie->id))
        ->put(route('series.refresh', $serie->id));

    $response
        ->assertRedirect(route('series.show', $serie->id))
        ->assertSessionHas('status', 'Refreshed Breaking Bad.');

    $refreshed = $serie->fresh();

    expect($refreshed->overview)->toBe('Fresh Trakt overview.')
        ->and($refreshed->lastupdated)->toBe('2026-09-13T10:00:00.000Z')
        ->and($refreshed->actors)->toContain('Bryan Cranston')
        ->and($refreshed->autoDownload)->toBeFalse()
        ->and($refreshed->displaycalendar)->toBeFalse()
        ->and($refreshed->customSearchString)->toBe('local-only search')
        ->and(Episode::where('serie_id', $serie->id)->count())->toBe(1);
});

it('does not claim success or mutate the favorite when trakt rate limits refresh', function () {
    $serie = Serie::create([
        'name' => 'Breaking Bad',
        'trakt_id' => 1388,
        'tvdb_id' => 81189,
        'overview' => 'Keep me.',
        'lastupdated' => '2020-01-01T00:00:00.000Z',
    ]);

    $originalUpdatedAt = $serie->updated_at;

    Http::fake([
        'api.trakt.tv/shows/1388?extended=full*' => Http::response([], 429, [
            'Retry-After' => '17',
        ]),
    ]);

    $response = $this
        ->from(route('series.show', $serie->id))
        ->put(route('series.refresh', $serie->id));

    $response
        ->assertRedirect(route('series.show', $serie->id))
        ->assertSessionMissing('status')
        ->assertSessionHas(
            'error',
            'Trakt is temporarily unavailable. Try again in 17 seconds.'
        );

    $unchanged = $serie->fresh();
    expect($unchanged->overview)->toBe('Keep me.')
        ->and($unchanged->lastupdated)->toBe('2020-01-01T00:00:00.000Z')
        ->and($unchanged->updated_at->equalTo($originalUpdatedAt))->toBeTrue();

    Http::assertSentCount(1);
});

it('does not expose upstream error bodies as a successful refresh', function () {
    $serie = Serie::create([
        'name' => 'Breaking Bad',
        'trakt_id' => 1388,
        'tvdb_id' => 81189,
        'overview' => 'Keep me.',
        'lastupdated' => '2020-01-01T00:00:00.000Z',
    ]);

    Http::fake([
        'api.trakt.tv/shows/1388?extended=full*' => Http::response(
            'upstream internal details',
            500
        ),
    ]);

    $response = $this
        ->from(route('series.show', $serie->id))
        ->put(route('series.refresh', $serie->id));

    $response
        ->assertRedirect(route('series.show', $serie->id))
        ->assertSessionMissing('status')
        ->assertSessionHas('error', 'Failed to refresh Breaking Bad.');

    expect($serie->fresh()->overview)->toBe('Keep me.');
});

it('fails closed when a local favorite has no trakt id', function () {
    $serie = Serie::create([
        'name' => 'Legacy Local Show',
        'tvdb_id' => 12345,
        'overview' => 'Local data',
    ]);

    Http::fake();

    $response = $this
        ->from(route('series.show', $serie->id))
        ->put(route('series.refresh', $serie->id));

    $response
        ->assertRedirect(route('series.show', $serie->id))
        ->assertSessionMissing('status')
        ->assertSessionHas(
            'error',
            'Cannot refresh Legacy Local Show: missing Trakt ID.'
        );

    Http::assertNothingSent();
});
