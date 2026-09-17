<?php

namespace App\Support;

final class MagnetUri
{
    private const string BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Extract and canonicalize a BTIH from a magnet URI or an exact raw hash.
     */
    public static function extractInfoHash(string $value): ?string
    {
        $direct = self::normalizeInfoHash($value);
        if ($direct !== null) {
            return $direct;
        }

        $queryPosition = strpos($value, '?');
        if ($queryPosition === false) {
            return null;
        }

        foreach (explode('&', substr($value, $queryPosition + 1)) as $parameter) {
            [$key, $encodedValue] = array_pad(explode('=', $parameter, 2), 2, '');

            if (strcasecmp(rawurldecode($key), 'xt') !== 0) {
                continue;
            }

            $exactTopic = rawurldecode($encodedValue);
            if (! str_starts_with(strtolower($exactTopic), 'urn:btih:')) {
                continue;
            }

            $hash = self::normalizeInfoHash(substr($exactTopic, strlen('urn:btih:')));
            if ($hash !== null) {
                return $hash;
            }
        }

        return null;
    }

    /**
     * Normalize an exact BTIH value to lowercase 40-character hexadecimal.
     */
    public static function normalizeInfoHash(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if (str_starts_with(strtolower($value), 'urn:btih:')) {
            $value = substr($value, strlen('urn:btih:'));
        }

        if (preg_match('/^[0-9a-f]{40}$/i', $value) === 1) {
            return strtolower($value);
        }

        if (preg_match('/^[a-z2-7]{32}$/i', $value) !== 1) {
            return null;
        }

        return self::decodeBase32($value);
    }

    private static function decodeBase32(string $value): ?string
    {
        $buffer = 0;
        $bits = 0;
        $decoded = '';

        foreach (str_split(strtoupper($value)) as $character) {
            $index = strpos(self::BASE32_ALPHABET, $character);
            if ($index === false) {
                return null;
            }

            $buffer = ($buffer << 5) | $index;
            $bits += 5;

            while ($bits >= 8) {
                $bits -= 8;
                $decoded .= chr(($buffer >> $bits) & 0xFF);
                $buffer = $bits === 0 ? 0 : $buffer & ((1 << $bits) - 1);
            }
        }

        if ($bits !== 0 || strlen($decoded) !== 20) {
            return null;
        }

        return strtolower(str_pad(bin2hex($decoded), 40, '0', STR_PAD_LEFT));
    }
}
