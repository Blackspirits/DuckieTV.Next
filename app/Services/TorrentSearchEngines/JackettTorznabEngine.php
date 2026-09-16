<?php

namespace App\Services\TorrentSearchEngines;

use App\Models\Jackett;
use App\Support\JackettTorznabEndpoint;
use App\Support\MagnetUri;
use App\Support\TorrentSize;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Exception;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Support\Facades\Http;

final class JackettTorznabEngine implements SearchEngineInterface
{
    private const int CONNECT_TIMEOUT_SECONDS = 3;

    private const int REQUEST_TIMEOUT_SECONDS = 8;

    private string $name;

    private string $endpoint;

    private string $apiKey;

    public function __construct(Jackett $jackett)
    {
        $name = trim((string) $jackett->name);
        $apiKey = trim((string) $jackett->apiKey);

        if ($name === '') {
            throw new \InvalidArgumentException('Jackett search engine requires a name.');
        }

        if ($apiKey === '') {
            throw new \InvalidArgumentException('Jackett search engine requires an API key.');
        }

        $this->name = $name;
        $this->apiKey = $apiKey;
        $this->endpoint = JackettTorznabEndpoint::searchUrl((string) $jackett->torznab);
    }

    #[\Override]
    public function search(string $query, ?string $sortBy = null): array
    {
        try {
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->withHeaders([
                    'Accept' => 'application/rss+xml, application/xml, text/xml',
                ])
                ->get($this->endpoint, [
                    't' => 'search',
                    'cat' => '',
                    'apikey' => $this->apiKey,
                    'q' => trim($query),
                ]);
        } catch (\Throwable) {
            // HTTP client exceptions can contain the full request URI, including
            // the Torznab API key. Replace them with a stable safe message.
            throw new Exception("Jackett Torznab search failed for {$this->name}.");
        }

        if (! $response->successful()) {
            throw new Exception("Jackett Torznab search failed for {$this->name} (Status: {$response->status()}).");
        }

        $results = $this->parseTorznab($response->body());

        return $this->sortResults($results, $sortBy);
    }

    #[\Override]
    public function getDetails(string $url, string $releaseName): array
    {
        // Torznab provides its actionable magnet/torrent URL in the search feed.
        // Never fetch tracker-controlled detail URLs server-side.
        return [];
    }

    #[\Override]
    public function getConfig(): array
    {
        // Deliberately exclude the API key from the public engine configuration.
        return [
            'name' => $this->name,
            'torznab' => $this->endpoint,
            'isJackett' => true,
            'useTorznab' => true,
        ];
    }

    #[\Override]
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    #[\Override]
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseTorznab(string $xml): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML(
                $xml,
                LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOBLANKS | LIBXML_NOERROR | LIBXML_NOWARNING
            );
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            throw new Exception("Jackett Torznab returned invalid XML for {$this->name}.");
        }

        $xpath = new DOMXPath($document);
        $items = $xpath->query('/*[local-name()="rss"]/*[local-name()="channel"]/*[local-name()="item"]');

        if ($items === false) {
            throw new Exception("Jackett Torznab returned invalid XML for {$this->name}.");
        }

        $results = [];

        foreach ($items as $item) {
            $title = trim($this->nodeText($xpath, $item, './*[local-name()="title"]'));
            if ($title === '') {
                continue;
            }

            $attributes = [];
            $attributeNodes = $xpath->query('.//*[local-name()="attr"]', $item);
            if ($attributeNodes !== false) {
                foreach ($attributeNodes as $attributeNode) {
                    if (! $attributeNode instanceof DOMElement) {
                        continue;
                    }

                    $attributeName = strtolower(trim($attributeNode->getAttribute('name')));
                    if ($attributeName !== '') {
                        $attributes[$attributeName] = trim($attributeNode->getAttribute('value'));
                    }
                }
            }

            $link = trim($this->nodeText($xpath, $item, './*[local-name()="link"]'));
            $magnetUrl = $this->normalizeMagnet($link)
                ?? $this->normalizeMagnet($attributes['magneturl'] ?? null);
            $torrentUrl = $this->normalizeHttpUrl($link);
            $detailUrl = $this->normalizeHttpUrl(
                $this->nodeText($xpath, $item, './*[local-name()="comments"]')
            );

            $infoHash = $magnetUrl !== null
                ? MagnetUri::extractInfoHash($magnetUrl)
                : MagnetUri::normalizeInfoHash($attributes['infohash'] ?? null);

            $size = $this->parseByteCount(
                $this->nodeText($xpath, $item, './*[local-name()="size"]')
            );

            $result = [
                'engine' => $this->name,
                'releasename' => $title,
                'sizeBytes' => $size['sizeBytes'],
                'sizeParseError' => $size['sizeParseError'],
                'size' => TorrentSize::format($size['sizeBytes']),
                'seeders' => $this->nonNegativeInt($attributes['seeders'] ?? null, 1),
                'leechers' => $this->nonNegativeInt($attributes['peers'] ?? null, 0),
                'detailUrl' => $detailUrl,
                'magnetUrl' => $magnetUrl,
                'torrentUrl' => $torrentUrl,
                'noMagnet' => $magnetUrl === null,
                'noTorrent' => $torrentUrl === null,
            ];

            if ($infoHash !== null) {
                $result['infoHash'] = $infoHash;
            }

            $results[] = $result;
        }

        return $results;
    }

    private function nodeText(DOMXPath $xpath, DOMNode $context, string $query): string
    {
        $nodes = $xpath->query($query, $context);

        return $nodes !== false && $nodes->length > 0
            ? (string) $nodes->item(0)?->textContent
            : '';
    }

    /**
     * @return array{sizeBytes: int|null, sizeParseError: bool}
     */
    private function parseByteCount(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return ['sizeBytes' => null, 'sizeParseError' => false];
        }

        if (! ctype_digit($value)) {
            return ['sizeBytes' => null, 'sizeParseError' => true];
        }

        $max = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($max)
            || (strlen($value) === strlen($max) && strcmp($value, $max) > 0)) {
            return ['sizeBytes' => null, 'sizeParseError' => true];
        }

        return ['sizeBytes' => (int) $value, 'sizeParseError' => false];
    }

    private function nonNegativeInt(?string $value, int $fallback): int
    {
        if ($value === null || preg_match('/^\d+$/', $value) !== 1) {
            return $fallback;
        }

        $number = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX],
        ]);

        return $number === false ? $fallback : $number;
    }

    private function normalizeMagnet(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $url = trim($url);
        if (! str_starts_with(strtolower($url), 'magnet:?')) {
            return null;
        }

        return MagnetUri::extractInfoHash($url) !== null ? $url : null;
    }

    private function normalizeHttpUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return null;
        }

        try {
            $uri = new Uri($url);
        } catch (\Throwable) {
            return null;
        }

        return in_array(strtolower($uri->getScheme()), ['http', 'https'], true)
            && $uri->getHost() !== ''
            && $uri->getUserInfo() === ''
            ? (string) $uri
            : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array<int, array<string, mixed>>
     */
    private function sortResults(array $results, ?string $sortBy): array
    {
        if ($sortBy === null || ! str_contains($sortBy, '.')) {
            return $results;
        }

        [$field, $direction] = explode('.', $sortBy, 2);
        $key = match ($field) {
            'size' => 'sizeBytes',
            'seeders', 'leechers', 'releasename' => $field,
            default => null,
        };

        if ($key === null || ! in_array($direction, ['a', 'd'], true)) {
            return $results;
        }

        usort($results, static function (array $left, array $right) use ($key, $direction): int {
            $a = $left[$key] ?? null;
            $b = $right[$key] ?? null;

            if (is_string($a) && is_string($b)) {
                $comparison = strcasecmp($a, $b);
            } else {
                $comparison = ($a ?? -1) <=> ($b ?? -1);
            }

            return $direction === 'd' ? -$comparison : $comparison;
        });

        return $results;
    }
}
