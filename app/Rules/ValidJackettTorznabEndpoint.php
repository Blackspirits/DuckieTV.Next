<?php

namespace App\Rules;

use App\Support\JackettTorznabEndpoint;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

class ValidJackettTorznabEndpoint implements ValidationRule
{
    #[\Override]
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The Jackett Torznab endpoint is invalid.');

            return;
        }

        try {
            JackettTorznabEndpoint::sanitize($value);
        } catch (InvalidArgumentException) {
            $fail('The Jackett Torznab endpoint is invalid.');
        }
    }
}
