<?php

use App\Services\TMDBService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

it('builds image URLs from TMDB paths', function () {
    $tmdb = new TMDBService;

    expect($tmdb->getImageUrl('/abc123.jpg', 'w500'))
        ->toBe('https://image.tmdb.org/t/p/w500/abc123.jpg')
        ->and($tmdb->getImageUrl('/xyz.jpg', 'original'))
        ->toBe('https://image.tmdb.org/t/p/original/xyz.jpg')
        ->and($tmdb->getImageUrl(null))
        ->toBeNull();
});

it('fetches show images from TMDB API', function () {
    Http::fake([
        'api.themoviedb.org/3/tv/1396*' => Http::response([
            'poster_path' => '/ggFHVNu6YYI5L9pCfOacjizRGt.jpg',
            'backdrop_path' => '/tsRy63Mu5cu8etL1X7ZLyf7UP1M.jpg',
            'name' => 'Breaking Bad',
        ]),
    ]);

    $tmdb = new TMDBService;
    $images = $tmdb->getShowImages(1396);

    expect($images['poster'])->toBe('https://image.tmdb.org/t/p/w500/ggFHVNu6YYI5L9pCfOacjizRGt.jpg')
        ->and($images['fanart'])->toBe('https://image.tmdb.org/t/p/original/tsRy63Mu5cu8etL1X7ZLyf7UP1M.jpg');
});

it('returns nulls when TMDB API fails', function () {
    Http::fake([
        'api.themoviedb.org/*' => Http::response([], 404),
    ]);

    $tmdb = new TMDBService;
    $images = $tmdb->getShowImages(99999);

    expect($images['poster'])->toBeNull()
        ->and($images['fanart'])->toBeNull();
});

it('does not log TMDB transport exception details', function () {
    $sensitive = 'tmdb-api-key-secret';
    Log::spy();

    Http::fake(fn () => throw new ConnectionException(
        "cURL error for https://api.themoviedb.org/3/tv/1396?api_key={$sensitive}"
    ));

    $tmdb = new TMDBService;
    $images = $tmdb->getShowImages(1396);

    expect($images)->toBe(['poster' => null, 'fanart' => null]);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(function (string $message, array $context) use ($sensitive) {
            return $message === 'TMDB: Transport failure while fetching show images.'
                && $context === [
                    'tmdb_id' => 1396,
                    'exception' => ConnectionException::class,
                ]
                && ! str_contains($message, $sensitive)
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), $sensitive);
        });
});

it('handles missing image paths gracefully', function () {
    Http::fake([
        'api.themoviedb.org/3/tv/100*' => Http::response([
            'poster_path' => null,
            'backdrop_path' => null,
            'name' => 'No Images Show',
        ]),
    ]);

    $tmdb = new TMDBService;
    $images = $tmdb->getShowImages(100);

    expect($images['poster'])->toBeNull()
        ->and($images['fanart'])->toBeNull();
});
