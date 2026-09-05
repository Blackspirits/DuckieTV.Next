<?php

use App\Services\TorrentSearchEngines\GenericSearchEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function makeDetailsEngine(string $mirror = 'https://mirror.example'): GenericSearchEngine
{
    return new GenericSearchEngine([
        'name' => 'TestEngine',
        'mirror' => $mirror,
        'detailsSelectors' => [
            'detailsContainer' => 'div.details',
            'magnetUrl' => ['a.magnet', 'href'],
        ],
        'selectors' => [],
        'endpoints' => ['search' => '/search/%s'],
    ]);
}

it('rejects a loopback URL before any server-side HTTP request', function () {
    Http::fake();

    $response = $this->postJson('/torrents/details', [
        'engine' => '1337x',
        'url' => 'http://127.0.0.1/internal/status',
        'releasename' => 'blocked-loopback',
    ]);

    $response->assertStatus(422);
    Http::assertNothingSent();
});

it('rejects non-http URL schemes at request validation', function () {
    $response = $this->postJson('/torrents/details', [
        'engine' => '1337x',
        'url' => 'file:///etc/passwd',
        'releasename' => 'blocked-scheme',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('url');
});

it('allows the configured mirror origin', function () {
    Http::fake([
        'https://mirror.example/details/1' => Http::response(
            '<div class="details"><a class="magnet" href="magnet:?xt=urn:btih:0123456789ABCDEF0123456789ABCDEF01234567">M</a></div>',
            200
        ),
    ]);

    $details = makeDetailsEngine()->getDetails('https://mirror.example/details/1', 'release');

    expect($details['magnetUrl'])->toStartWith('magnet:?xt=urn:btih:');
    Http::assertSentCount(1);
});

it('rejects a different public origin', function () {
    Http::fake();

    expect(fn () => makeDetailsEngine()->getDetails('https://other.example/details/1', 'release'))
        ->toThrow(\InvalidArgumentException::class);

    Http::assertNothingSent();
});

it('rejects an alternate port on the configured host', function () {
    Http::fake();

    expect(fn () => makeDetailsEngine()->getDetails('https://mirror.example:8443/details/1', 'release'))
        ->toThrow(\InvalidArgumentException::class);

    Http::assertNothingSent();
});

it('rejects configured private IPv4 and IPv6 literal mirrors', function (string $mirror, string $url) {
    Http::fake();

    expect(fn () => makeDetailsEngine($mirror)->getDetails($url, 'release'))
        ->toThrow(\InvalidArgumentException::class);

    Http::assertNothingSent();
})->with([
    'loopback-v4' => ['http://127.0.0.1', 'http://127.0.0.1/details/1'],
    'private-v4' => ['http://10.0.0.1', 'http://10.0.0.1/details/1'],
    'loopback-v6' => ['http://[::1]', 'http://[::1]/details/1'],
    'private-v6' => ['http://[fd00::1]', 'http://[fd00::1]/details/1'],
]);

it('follows a relative redirect only while it remains on the mirror origin', function () {
    Http::fake(function ($request) {
        return match ($request->url()) {
            'https://mirror.example/details/start' => Http::response('', 302, ['Location' => '/details/final']),
            'https://mirror.example/details/final' => Http::response(
                '<div class="details"><a class="magnet" href="magnet:?xt=urn:btih:0123456789ABCDEF0123456789ABCDEF01234567">M</a></div>',
                200
            ),
            default => Http::response('', 500),
        };
    });

    $details = makeDetailsEngine()->getDetails('https://mirror.example/details/start', 'release');

    expect($details['magnetUrl'])->toStartWith('magnet:?xt=urn:btih:');
    Http::assertSentCount(2);
});

it('rejects a redirect to another origin before following it', function () {
    Http::fake([
        'https://mirror.example/details/start' => Http::response('', 302, ['Location' => 'http://127.0.0.1/internal']),
    ]);

    expect(fn () => makeDetailsEngine()->getDetails('https://mirror.example/details/start', 'release'))
        ->toThrow(\InvalidArgumentException::class);

    Http::assertSentCount(1);
});

it('caps same-origin redirect chains', function () {
    Http::fake(fn () => Http::response('', 302, ['Location' => '/details/again']));

    expect(fn () => makeDetailsEngine()->getDetails('https://mirror.example/details/start', 'release'))
        ->toThrow(Exception::class, 'Too many redirects');

    Http::assertSentCount(4);
});
