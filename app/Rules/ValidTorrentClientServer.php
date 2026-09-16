<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ValidTorrentClientServer implements ValidationRule
{
    #[\Override]
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '' || $value !== trim($value)) {
            $fail('The torrent client server must be an HTTP(S) origin without a port or path.');

            return;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            $fail('The torrent client server must be an HTTP(S) origin without a port or path.');

            return;
        }

        $parts = parse_url($value);
        if (! is_array($parts)) {
            $fail('The torrent client server must be an HTTP(S) origin without a port or path.');

            return;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        $path = (string) ($parts['path'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || array_key_exists('port', $parts)
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || ! in_array($path, ['', '/'], true)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)) {
            $fail('The torrent client server must be an HTTP(S) origin without a port or path.');
        }
    }
}
