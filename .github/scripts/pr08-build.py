from pathlib import Path

request = Path('app/Http/Requests/TorrentDetailsRequest.php')
text = request.read_text()
old = "            'url' => ['required', 'string', 'max:2048'],\n"
new = "            'url' => ['required', 'url:http,https', 'max:2048'],\n"
if text.count(old) != 1:
    raise SystemExit(f'TorrentDetailsRequest.php: expected URL rule once, found {text.count(old)}')
request.write_text(text.replace(old, new, 1))

engine = Path('app/Services/TorrentSearchEngines/GenericSearchEngine.php')
text = engine.read_text()
old_imports = "use Exception;\nuse Illuminate\\Support\\Facades\\Http;\nuse Symfony\\Component\\DomCrawler\\Crawler;\n"
new_imports = "use Exception;\nuse GuzzleHttp\\Psr7\\Uri;\nuse GuzzleHttp\\Psr7\\UriResolver;\nuse Illuminate\\Http\\Client\\Response;\nuse Illuminate\\Support\\Facades\\Http;\nuse InvalidArgumentException;\nuse Symfony\\Component\\DomCrawler\\Crawler;\n"
if text.count(old_imports) != 1:
    raise SystemExit('GenericSearchEngine.php: import block mismatch')
text = text.replace(old_imports, new_imports, 1)

old_class = "class GenericSearchEngine implements SearchEngineInterface\n{\n"
new_class = "class GenericSearchEngine implements SearchEngineInterface\n{\n    private const MAX_DETAILS_REDIRECTS = 3;\n\n"
if text.count(old_class) != 1:
    raise SystemExit('GenericSearchEngine.php: class marker mismatch')
text = text.replace(old_class, new_class, 1)

old_method = '''    public function getDetails(string $url, string $releaseName): array
    {
        if (! isset($this->config['detailsSelectors'])) {
            return [];
        }

        /** @var \\Illuminate\\Http\\Client\\Response $response */
        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
        ])->get($url);

        if (! $response->successful()) {
            throw new Exception("Details request failed for {$this->name} at {$url} (Status: {$response->status()})");
        }

        return $this->parseDetails($response->body(), $releaseName);
    }
'''
new_method = '''    public function getDetails(string $url, string $releaseName): array
    {
        if (! isset($this->config['detailsSelectors'])) {
            return [];
        }

        $response = $this->fetchTrustedDetailsPage($url);

        if (! $response->successful()) {
            throw new Exception("Details request failed for {$this->name} (Status: {$response->status()})");
        }

        return $this->parseDetails($response->body(), $releaseName);
    }

    /**
     * Fetch a details page while keeping every hop on the configured mirror origin.
     */
    protected function fetchTrustedDetailsPage(string $url): Response
    {
        for ($redirects = 0; $redirects <= self::MAX_DETAILS_REDIRECTS; $redirects++) {
            $this->assertTrustedDetailsUrl($url);

            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            ])->withoutRedirecting()->get($url);

            if (! in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                return $response;
            }

            if ($redirects === self::MAX_DETAILS_REDIRECTS) {
                throw new Exception("Too many redirects while fetching details for {$this->name}");
            }

            $location = $response->header('Location');
            if (! is_string($location) || $location === '') {
                throw new Exception("Invalid redirect while fetching details for {$this->name}");
            }

            $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
        }

        throw new Exception("Unable to fetch details for {$this->name}");
    }

    /**
     * Ensure a details URL stays on the exact configured mirror origin and cannot target local/private IP literals.
     */
    protected function assertTrustedDetailsUrl(string $url): void
    {
        $target = parse_url($url);
        $mirror = parse_url((string) ($this->config['mirror'] ?? ''));

        if (! is_array($target) || ! is_array($mirror)
            || ! isset($target['scheme'], $target['host'], $mirror['scheme'], $mirror['host'])) {
            throw new InvalidArgumentException('Invalid torrent details URL.');
        }

        $targetScheme = strtolower((string) $target['scheme']);
        $mirrorScheme = strtolower((string) $mirror['scheme']);
        $targetHost = $this->normalizeHost((string) $target['host']);
        $mirrorHost = $this->normalizeHost((string) $mirror['host']);

        if (! in_array($targetScheme, ['http', 'https'], true)
            || $targetScheme !== $mirrorScheme
            || $targetHost !== $mirrorHost
            || $this->effectivePort($target) !== $this->effectivePort($mirror)
            || isset($target['user'])
            || isset($target['pass'])) {
            throw new InvalidArgumentException('Torrent details URL is outside the configured search-engine origin.');
        }

        if ($this->isBlockedHost($targetHost)) {
            throw new InvalidArgumentException('Torrent details URL targets a local or private address.');
        }
    }

    protected function normalizeHost(string $host): string
    {
        return strtolower(rtrim(trim($host, '[]'), '.'));
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    protected function effectivePort(array $parts): int
    {
        if (isset($parts['port'])) {
            return (int) $parts['port'];
        }

        return strtolower((string) ($parts['scheme'] ?? '')) === 'https' ? 443 : 80;
    }

    protected function isBlockedHost(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }

        if (! filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        return filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
'''
if text.count(old_method) != 1:
    raise SystemExit(f'GenericSearchEngine.php: getDetails block found {text.count(old_method)} times')
engine.write_text(text.replace(old_method, new_method, 1))

test = Path('tests/Feature/TorrentDetailsTrustBoundaryTest.php')
test.write_text(r'''<?php

use App\Services\TorrentSearchEngines\GenericSearchEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

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
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

it('rejects an alternate port on the configured host', function () {
    Http::fake();

    expect(fn () => makeDetailsEngine()->getDetails('https://mirror.example:8443/details/1', 'release'))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

it('rejects configured private IPv4 and IPv6 literal mirrors', function (string $mirror, string $url) {
    Http::fake();

    expect(fn () => makeDetailsEngine($mirror)->getDetails($url, 'release'))
        ->toThrow(InvalidArgumentException::class);

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
        ->toThrow(InvalidArgumentException::class);

    Http::assertSentCount(1);
});

it('caps same-origin redirect chains', function () {
    Http::fake(fn () => Http::response('', 302, ['Location' => '/details/again']));

    expect(fn () => makeDetailsEngine()->getDetails('https://mirror.example/details/start', 'release'))
        ->toThrow(Exception::class, 'Too many redirects');

    Http::assertSentCount(4);
});
''')
