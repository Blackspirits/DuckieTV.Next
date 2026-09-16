<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ValidTorrentClientServer implements ValidationRule
{
    #[\Override]
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::displayValue($value) === '') {
            $fail('The torrent client server must be an HTTP(S) origin without a port or path.');
        }
    }

    public static function displayValue(mixed $value): string
    {
        return self::isValid($value) ? (string) $value : '';
    }

    private static function isValid(mixed $value): bool
    {
        if (! is_string($value) || $value === '' || $value !== trim($value)) {
            return false;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return false;
        }

        $parts = parse_url($value);
        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        $path = (string) ($parts['path'] ?? '');

        return in_array($scheme, ['http', 'https'], true)
            && $host !== ''
            && ! array_key_exists('port', $parts)
            && ! array_key_exists('user', $parts)
            && ! array_key_exists('pass', $parts)
            && in_array($path, ['', '/'], true)
            && ! array_key_exists('query', $parts)
            && ! array_key_exists('fragment', $parts);
    }
}
