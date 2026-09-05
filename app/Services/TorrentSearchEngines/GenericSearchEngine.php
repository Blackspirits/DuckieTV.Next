<?php

namespace App\Services\TorrentSearchEngines;

use Exception;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * 'Generic' torrent search engine scraper for DuckieTV.Next.
 *
 * This class provides a flexible way to scrape torrent websites by using
 * CSS selectors and configurable endpoints. It maps the mirrors, selectors,
 * and orderby configurations from the original Angular implementation
 * to a PHP-based crawler using Symfony DomCrawler.
 *
 * Usage:
 * - Instantiate with a config array containing mirrors, selectors, etc.
 * - Call search() to get structured results from a search page.
 * - Call getDetails() if additional parsing of a details page is required.
 *
 * @see GenericTorrentSearchEngine.js in DuckieTV-angular for original implementation.
 */
class GenericSearchEngine implements SearchEngineInterface
{
    private const int MAX_DETAILS_REDIRECTS = 3;

    /** @var array The search engine configuration */
    protected array $config;

    /** @var string The display name of the engine */
    protected string $name;

    /**
     * @param  array  $config  Configuration including mirror, endpoints, selectors, etc.
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->name = $config['name'] ?? 'Generic';
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->config['name'] = $name;
    }

    /**
     * Get the unique name for this search engine.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Execute a torrent search, parse the results, and return them.
     *
     * @param  string  $query  The search query
     * @param  string|null  $sortBy  Sorting parameter (e.g. 'seeders.d')
     * @return array Array of results with releasename, size, seeders, leechers, magnetUrl, etc.
     *
     * @throws Exception if the HTTP request fails
     */
    public function search(string $query, ?string $sortBy = null): array
    {
        $url = $this->buildSearchUrl($query, $sortBy);

        /** @var \Illuminate\Http\Client\Response $response */
        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.9',
        ])->get($url);

        if (! $response->successful()) {
            throw new Exception("Search request failed for {$this->name} at {$url} (Status: {$response->status()})");
        }

        return $this->parseSearch($response->body());
    }

    /**
     * Fetch and parse a details page to retrieve magnet or torrent links.
     *
     * @param  string  $url  The details page URL
     * @param  string  $releaseName  Used as a fallback for building itorrents.org URLs
     * @return array Array containing magnetUrl and/or torrentUrl
     *
     * @throws Exception if the HTTP request fails
     */
    public function getDetails(string $url, string $releaseName): array
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

            if (! $response instanceof Response) {
                throw new Exception("Unexpected asynchronous response while fetching details for {$this->name}");
            }

            if (! in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                return $response;
            }

            if ($redirects === self::MAX_DETAILS_REDIRECTS) {
                throw new Exception("Too many redirects while fetching details for {$this->name}");
            }

            $location = $response->header('Location');
            if ($location === '') {
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

        $targetScheme = strtolower($target['scheme']);
        $mirrorScheme = strtolower($mirror['scheme']);
        $targetHost = $this->normalizeHost($target['host']);
        $mirrorHost = $this->normalizeHost($mirror['host']);

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

    /**
     * Get the current engine configuration.
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Construct the full search URL based on query and sorting preference.
     */
    protected function buildSearchUrl(string $query, ?string $sortBy): string
    {
        $mirror = rtrim($this->config['mirror'], '/');
        $endpoint = $this->config['endpoints']['search'];

        $url = $mirror.$endpoint;

        // Handle sorting placeholder %o
        $order = '';
        if ($sortBy && isset($this->config['orderby'])) {
            $parts = explode('.', $sortBy);
            if (count($parts) === 2 && isset($this->config['orderby'][$parts[0]][$parts[1]])) {
                $order = $this->config['orderby'][$parts[0]][$parts[1]];
            }
        } elseif (isset($this->config['orderby']['seeders']['d'])) {
            // Default to seeders descending if available
            $order = $this->config['orderby']['seeders']['d'];
        }

        $url = str_replace('%o', $order, $url);

        return str_replace('%s', urlencode($query), $url);
    }

    /**
     * Parse the HTML search results using configured selectors.
     */
    protected function parseSearch(string $html): array
    {
        $crawler = new \Symfony\Component\DomCrawler\Crawler($html);
        $selectors = $this->config['selectors'];
        $output = [];

        $results = $crawler->filter($selectors['resultContainer']);

        $results->each(function (\Symfony\Component\DomCrawler\Crawler $node) use ($selectors, &$output) {
            $releasename = $this->getPropertyForSelector($node, $selectors['releasename']);
            if (! $releasename) {
                return;
            }

            $seeders = $this->getPropertyForSelector($node, $selectors['seeders']);
            $leechers = $this->getPropertyForSelector($node, $selectors['leechers']);

            $seeders = (int) preg_replace('/[^0-9]/', '', $seeders ?? '0');
            $leechers = (int) preg_replace('/[^0-9]/', '', $leechers ?? '0');

            $out = [
                'releasename' => trim($releasename),
                'size' => $this->sizeToMB($this->getPropertyForSelector($node, $selectors['size'])),
                'seeders' => $seeders,
                'leechers' => $leechers,
                'detailUrl' => (($this->config['includeBaseURL'] ?? false) ? $this->config['mirror'] : '').$this->getPropertyForSelector($node, $selectors['detailUrl']),
                'noMagnet' => true,
                'noTorrent' => true,
            ];

            $magnet = $this->getPropertyForSelector($node, $selectors['magnetUrl'] ?? null);
            $torrent = $this->getPropertyForSelector($node, $selectors['torrentUrl'] ?? null);

            if ($magnet) {
                $out['magnetUrl'] = $magnet;
                $out['noMagnet'] = false;
            }

            if ($torrent) {
                $out['torrentUrl'] = str_starts_with($torrent, 'http') ? $torrent : $this->config['mirror'].$torrent;
                $out['noTorrent'] = false;
            } elseif (isset($out['magnetUrl']) && preg_match('/([0-9ABCDEFabcdef]{40})/', $out['magnetUrl'], $matches)) {
                $out['torrentUrl'] = 'http://itorrents.org/torrent/'.strtoupper($matches[1]).'.torrent?title='.urlencode(trim($out['releasename']));
                $out['noTorrent'] = false;
            }

            if (isset($this->config['detailsSelectors'])) {
                if (isset($this->config['detailsSelectors']['magnetUrl'])) {
                    $out['noMagnet'] = false;
                }
                if (isset($this->config['detailsSelectors']['torrentUrl'])) {
                    $out['noTorrent'] = false;
                }
            }

            $output[] = $out;
        });

        return $output;
    }

    /**
     * Parse the HTML details page using configured selectors.
     */
    protected function parseDetails(string $html, string $releaseName): array
    {
        $crawler = new \Symfony\Component\DomCrawler\Crawler($html);
        $selectors = $this->config['detailsSelectors'];
        $container = $crawler->filter($selectors['detailsContainer']);

        if ($container->count() === 0) {
            return [];
        }

        $output = [];
        $magnet = $this->getPropertyForSelector($container, $selectors['magnetUrl'] ?? null);

        if ($magnet) {
            $output['magnetUrl'] = $magnet;
        }

        $torrent = $this->getPropertyForSelector($container, $selectors['torrentUrl'] ?? null);
        if ($torrent) {
            $output['torrentUrl'] = str_starts_with($torrent, 'http') ? $torrent : $this->config['mirror'].$torrent;
        } elseif (isset($output['magnetUrl']) && preg_match('/([0-9ABCDEFabcdef]{40})/', $output['magnetUrl'], $matches)) {
            $output['torrentUrl'] = 'http://itorrents.org/torrent/'.strtoupper($matches[1]).'.torrent?title='.urlencode(trim($releaseName));
        }

        return $output;
    }

    /**
     * Extract a property string from a node using a selector-attribute pair.
     *
     * @param  Crawler  $node  The parent node to search within
     * @param  array|null  $propertyConfig  [selector, attribute]
     * @return string|null The extracted value or null if not found
     */
    protected function getPropertyForSelector(\Symfony\Component\DomCrawler\Crawler $node, ?array $propertyConfig): ?string
    {
        if (! $propertyConfig || count($propertyConfig) < 2) {
            return null;
        }

        $selector = $propertyConfig[0];
        $attribute = $propertyConfig[1];

        try {
            $targetNode = $selector === '' ? $node : $node->filter($selector);
            if ($targetNode->count() === 0) {
                return null;
            }

            if ($attribute === 'innerText' || $attribute === 'innerHTML') {
                $value = $attribute === 'innerText' ? $targetNode->text() : $targetNode->html();
            } else {
                $value = $targetNode->attr($attribute);
            }

            return $value;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Convert various size strings (GB, MB, KiB, etc.) to a standardized MB string.
     *
     * @return string Converted size (e.g., "123.45 MB")
     */
    protected function sizeToMB(?string $size): string
    {
        if (! $size) {
            return '0 MB';
        }

        if (preg_match('/([0-9.]+)\s*([KTMG]B|[KTMG]iB|Bytes|B)/i', $size, $matches)) {
            $value = (float) $matches[1];
            $unit = strtoupper($matches[2]);

            switch ($unit) {
                case 'B':
                case 'BYTES':
                    return number_format($value / 1000 / 1000, 2).' MB';
                case 'KB':
                    return number_format($value / 1000, 2).' MB';
                case 'MB':
                    return number_format($value, 2).' MB';
                case 'GB':
                    return number_format($value * 1000, 2).' MB';
                case 'TB':
                    return number_format($value * 1000 * 1000, 2).' MB';
                case 'KIB':
                    return number_format(($value * 1024) / 1000 / 1000, 2).' MB';
                case 'MIB':
                    return number_format(($value * 1024 * 1024) / 1000 / 1000, 2).' MB';
                case 'GIB':
                    return number_format(($value * 1024 * 1024 * 1024) / 1000 / 1000, 2).' MB';
                case 'TIB':
                    return number_format(($value * 1024 * 1024 * 1024 * 1024) / 1000 / 1000, 2).' MB';
            }
        }

        return $size;
    }
}
