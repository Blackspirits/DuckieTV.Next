<?php

use App\Services\PosterService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

it('enriches missing posters from TMDB', function () {
    Http::fake([
        'api.themoviedb.org/3/tv/1396*' => Http::response([
            'poster_path' => '/poster.jpg',
        ]),
    ]);

    $series = [[
        'name' => 'Breaking Bad',
        'tmdb_id' => 1396,
        'poster' => null,
    ]];

    $result = (new PosterService)->enrich($series);

    expect($result[0]['poster'])->toBe('https://image.tmdb.org/t/p/w500/poster.jpg');
});

it('keeps search results when pooled TMDB poster transport fails', function () {
    $sensitive = 'tmdb-api-key-secret';

    Http::fake(fn () => throw new ConnectionException(
        "cURL error for https://api.themoviedb.org/3/tv/1396?api_key={$sensitive}"
    ));

    $series = [[
        'name' => 'Breaking Bad',
        'tmdb_id' => 1396,
        'poster' => null,
    ]];

    $result = (new PosterService)->enrich($series);

    expect($result)->toBe($series);
});

it('keeps successful pooled posters when another TMDB request loses connectivity', function () {
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/tv/1396')) {
            return Http::response(['poster_path' => '/poster.jpg']);
        }

        throw new ConnectionException('remote-response-secret');
    });

    $series = [
        [
            'name' => 'Breaking Bad',
            'tmdb_id' => 1396,
            'poster' => null,
        ],
        [
            'name' => 'Offline Show',
            'tmdb_id' => 9999,
            'poster' => null,
        ],
    ];

    $result = (new PosterService)->enrich($series);

    expect($result[0]['poster'])->toBe('https://image.tmdb.org/t/p/w500/poster.jpg')
        ->and($result[1])->toBe($series[1]);
});
