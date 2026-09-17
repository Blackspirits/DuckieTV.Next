<?php

namespace App\Services\TorrentSearchEngines;

use App\Models\Jackett;
use App\Support\MagnetUri;
use App\Support\TorrentSize;
use Exception;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use JsonException;

final class JackettAdminEngine implements SearchEngineInterface
{
    private const int CONNECT_TIMEOUT_SECONDS = 3;

    private const int REQUEST_TIMEOUT_SECONDS = 8;

    private string $name;

    private int $apiVersion;

    private string $endpoint;

    private string $tracker;

    private ?string $apiKey;

    public function __construct(Jackett $jackett)
    {
        $name = trim((string) $jackett->name);
        $config = $jackett->json;

        if ($name === '' || ! is_array($config)) {
            throw new InvalidArgumentException('Invalid Jackett Admin API configuration.');
        }

        $apiVersion = $config['apiVersion'] ?? 1;
        if (is_int($apiVersion)) {
            $resolvedApiVersion = $apiVersion;
        } elseif (is_string($apiVersion) && in_array($apiVersion, ['1', '2'], true)) {
            $resolvedApiVersion = (int) $apiVersion;
        } else {
            throw new InvalidArgumentException('Invalid Jackett Admin API configuration.');
        }

        if (! in_array($resolvedApiVersion, [1, 2], true)) {
            throw new InvalidArgumentException('Invalid Jackett Admin API configuration.');
        }

        $mirror = $config['mirror'] ?? null;
        $tracker = $config['tracker'] ?? null;

        if (! is_string($mirror) || ! is_string($tracker) || trim($tracker) === '') {
            throw new InvalidArgumentException('Invalid Jackett Admin API configuration.');
        }

        $apiKey = $jackett->apiKey;
        if ((! is_string($apiKey) || trim($apiKey) === '') && isset($config['apiKey']) && is_string($config['apiKey'])) {
            $apiKey = $config['apiKey'];
        }

        if ($resolvedApiVersion === 2 && (! is_string($apiKey) || trim($apiKey) === '')) {
            throw new InvalidArgumentException('Invalid Jackett Admin API configuration.');
        }

        if (is_string($apiKey)) {
            $apiKey = trim($apiKey);
            if (strlen($apiKey) > 200 || preg_match('/[\x00-\x1F\x7F]/', $apiKey) === 1) {
                throw new InvalidArgumentException('Invalid Jackett Admin API configuration.');
            }
        }

        $this->name = $name;
        $this->apiVersion = $resolvedApiVersion;
        $this->endpoint = $this->normalizeEndpoint($mirror);
        $tracker = trim($tracker);
        if (strlen($tracker) > 200 || preg_match('/[\x00-\x1F\x7F]/', $tracker) === 1) {
            throw new InvalidArgumentException('Invalid Jackett Admin API configuration.');
        }

        $this->tracker = $tracker;
        $this->apiKey = is_string($apiKey) && trim($apiKey) !== '' ? trim($apiKey) : null;
    }

    #[\Override]
    public function search(string $query, ?string $sortBy = null): array
    {
        try {
            $response = $this->apiVersion === 1
                ? $this->searchApiV1($query)
                : $this->searchApiV2($query);
        } catch (\Throwable) {
            throw new Exception("Jackett Admin API search failed for {$this->name}.");
        }

        if (! $response->successful()) {
            throw new Exception(
                "Jackett Admin API search failed for {$this->name} (Status: {$response->status()})."
            );
        }

        $results = $this->parseResults($response->body());

        return $this->sortResults($results, $sortBy);
    }

    #[\Override]
    public function getDetails(string $url, string $releaseName): array
    {
        return [];
    }

    #[\Override]
    public function getConfig(): array
    {
        return [
            'name' => $this->name,
            'apiVersion' => $this->apiVersion,
            'mirror' => $this->endpoint,
            'tracker' => $this->tracker,
            'isJackett' => true,
            'useTorznab' => false,
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

    private function searchApiV1(string $query): Response
    {
        /** @var Response $response */
        $response = Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->withoutRedirecting()
            ->acceptJson()
            ->asForm()
            ->post($this->endpoint, [
                'Query' => trim($query),
                'Category' => '',
                'Tracker' => $this->tracker,
            ]);

        return $response;
    }

    private function searchApiV2(string $query): Response
    {
        $parameters = [
            'apikey' => (string) $this->apiKey,
            'Query' => trim($query),
        ];

        if (strtolower($this->tracker) !== 'all') {
            $parameters['Tracker[]'] = $this->tracker;
        }

        /** @var Response $response */
        $response = Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->withoutRedirecting()
            ->acceptJson()
            ->get($this->endpoint, $parameters);

        return $response;
    }

    private function normalizeEndpoint(string $endpoint): string
    {
        $endpoint = trim($endpoint);

        if ($endpoint === '' || preg_match('/[\x00-\x1F\x7F]/', $endpoint) === 1) {
            throw new InvalidArgumentException('Invalid Jackett Admin API endpoint.');
        }

        try {
            $uri = new Uri($endpoint);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid Jackett Admin API endpoint.');
        }

        if (! in_array(strtolower($uri->getScheme()), ['http', 'https'], true)
            || $uri->getHost() === ''
            || $uri->getUserInfo() !== ''
            || rtrim($uri->getPath(), '/') === '') {
            throw new InvalidArgumentException('Invalid Jackett Admin API endpoint.');
        }

        return (string) $uri
            ->withQuery('')
            ->withFragment('');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseResults(string $body): array
    {
        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new Exception("Jackett Admin API returned invalid JSON for {$this->name}.");
        }

        if (! is_array($payload) || ! isset($payload['Results']) || ! is_array($payload['Results'])) {
            throw new Exception("Jackett Admin API returned invalid JSON for {$this->name}.");
        }

        $results = [];

        foreach ($payload['Results'] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = isset($item['Title']) && is_string($item['Title'])
                ? trim($item['Title'])
                : '';

            if ($title === '') {
                continue;
            }

            $magnetUrl = isset($item['MagnetUri']) && is_string($item['MagnetUri'])
                ? $this->normalizeMagnet($item['MagnetUri'])
                : null;
            $torrentUrl = isset($item['Link']) && is_string($item['Link'])
                ? $this->normalizeHttpUrl($item['Link'])
                : null;
            $detailUrl = isset($item['Details']) && is_string($item['Details'])
                ? $this->normalizeHttpUrl($item['Details'])
                : null;

            $providedHash = isset($item['InfoHash']) && is_string($item['InfoHash'])
                ? $item['InfoHash']
                : null;
            $infoHash = $magnetUrl !== null
                ? MagnetUri::extractInfoHash($magnetUrl)
                : MagnetUri::normalizeInfoHash($providedHash);

            $size = $this->parseByteCount($item['Size'] ?? null);

            $result = [
                'engine' => $this->name,
                'releasename' => $title,
                'sizeBytes' => $size['sizeBytes'],
                'sizeParseError' => $size['sizeParseError'],
                'size' => TorrentSize::format($size['sizeBytes']),
                'seeders' => $this->nonNegativeInt($item['Seeders'] ?? null, 1),
                'leechers' => $this->nonNegativeInt($item['Peers'] ?? null, 0),
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

    /**
     * @return array{sizeBytes: int|null, sizeParseError: bool}
     */
    private function parseByteCount(mixed $value): array
    {
        if ($value === null || $value === '') {
            return ['sizeBytes' => null, 'sizeParseError' => false];
        }

        if (is_int($value) && $value >= 0) {
            return ['sizeBytes' => $value, 'sizeParseError' => false];
        }

        if (is_string($value) && ctype_digit($value)) {
            $max = (string) PHP_INT_MAX;
            if (strlen($value) < strlen($max)
                || (strlen($value) === strlen($max) && strcmp($value, $max) <= 0)) {
                return ['sizeBytes' => (int) $value, 'sizeParseError' => false];
            }
        }

        if (is_float($value)
            && is_finite($value)
            && $value >= 0
            && floor($value) === $value
            && $value <= PHP_INT_MAX) {
            return ['sizeBytes' => (int) $value, 'sizeParseError' => false];
        }

        return ['sizeBytes' => null, 'sizeParseError' => true];
    }

    private function nonNegativeInt(mixed $value, int $fallback): int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : $fallback;
        }

        if (! is_string($value) || preg_match('/^\d+$/', $value) !== 1) {
            return $fallback;
        }

        $number = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX],
        ]);

        return $number === false ? $fallback : $number;
    }

    private function normalizeMagnet(string $url): ?string
    {
        $url = trim($url);

        if (! str_starts_with(strtolower($url), 'magnet:?')) {
            return null;
        }

        return MagnetUri::extractInfoHash($url) !== null ? $url : null;
    }

    private function normalizeHttpUrl(string $url): ?string
    {
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
