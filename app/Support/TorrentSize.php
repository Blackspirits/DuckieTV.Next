<?php

namespace App\Support;

final class TorrentSize
{
    /**
     * Parse a source size into the canonical machine contract.
     *
     * @return array{sizeBytes: int|null, sizeParseError: bool}
     */
    public static function parse(?string $source): array
    {
        if ($source === null) {
            return ['sizeBytes' => null, 'sizeParseError' => false];
        }

        $normalized = html_entity_decode($source, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = str_replace(["\u{00A0}", "\u{202F}"], ' ', $normalized);
        $normalized = trim($normalized);

        if ($normalized === '' || strcasecmp($normalized, 'n/a') === 0) {
            return ['sizeBytes' => null, 'sizeParseError' => false];
        }

        if (! preg_match(
            '/(?<![A-Za-z0-9])([0-9]+(?:[.,][0-9]+)?)\s*(TiB|GiB|MiB|KiB|TB|GB|MB|KB|Bytes|B)(?![A-Za-z0-9])/i',
            $normalized,
            $matches
        )) {
            return ['sizeBytes' => null, 'sizeParseError' => true];
        }

        $value = (float) str_replace(',', '.', $matches[1]);
        $multiplier = match (strtoupper($matches[2])) {
            'B', 'BYTES' => 1,
            'KB' => 1_000,
            'MB' => 1_000_000,
            'GB' => 1_000_000_000,
            'TB' => 1_000_000_000_000,
            'KIB' => 1_024,
            'MIB' => 1_048_576,
            'GIB' => 1_073_741_824,
            'TIB' => 1_099_511_627_776,
        };

        $bytes = $value * $multiplier;
        if (! is_finite($bytes) || $bytes < 0 || $bytes > PHP_INT_MAX) {
            return ['sizeBytes' => null, 'sizeParseError' => true];
        }

        return ['sizeBytes' => (int) round($bytes), 'sizeParseError' => false];
    }

    public static function format(?int $bytes): string
    {
        if ($bytes === null) {
            return 'n/a';
        }

        $units = [
            ['TB', 1_000_000_000_000],
            ['GB', 1_000_000_000],
            ['MB', 1_000_000],
            ['KB', 1_000],
        ];

        foreach ($units as [$unit, $factor]) {
            if ($bytes >= $factor) {
                return self::formatNumber($bytes / $factor).' '.$unit;
            }
        }

        return $bytes.' B';
    }

    private static function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
