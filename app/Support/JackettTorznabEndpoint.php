<?php

namespace App\Support;

use GuzzleHttp\Psr7\Uri;
use InvalidArgumentException;

final class JackettTorznabEndpoint
{
    public static function sanitize(string $endpoint): string
    {
        $endpoint = trim($endpoint);

        if ($endpoint === '' || preg_match('/[\x00-\x1F\x7F]/', $endpoint) === 1) {
            throw new InvalidArgumentException('Invalid Jackett Torznab endpoint.');
        }

        try {
            $uri = new Uri($endpoint);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid Jackett Torznab endpoint.');
        }

        if (! in_array(strtolower($uri->getScheme()), ['http', 'https'], true)
            || $uri->getHost() === ''
            || $uri->getUserInfo() !== '') {
            throw new InvalidArgumentException('Invalid Jackett Torznab endpoint.');
        }

        if (rtrim($uri->getPath(), '/') === '') {
            throw new InvalidArgumentException('Invalid Jackett Torznab endpoint.');
        }

        return (string) $uri
            ->withQuery('')
            ->withFragment('');
    }

    public static function displayValue(string $endpoint): ?string
    {
        try {
            return self::sanitize($endpoint);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function searchUrl(string $endpoint): string
    {
        $uri = new Uri(self::sanitize($endpoint));
        $path = rtrim($uri->getPath(), '/');

        // Historical Jackett v1 stored a Torznab root and appended /api at
        // request time. Modern Jackett/Prowlarr endpoints already include an
        // API path and must be used as-is.
        if (! str_contains(strtolower($path), '/api/v')
            && ! str_ends_with(strtolower($path), '/api')) {
            $path .= '/api';
        }

        return (string) $uri->withPath($path);
    }
}
