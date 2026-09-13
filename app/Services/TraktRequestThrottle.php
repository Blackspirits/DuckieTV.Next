<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class TraktRequestThrottle
{
    private const LOCK_KEY = 'trakt:request-throttle:lock';

    private const NEXT_ALLOWED_AT_KEY = 'trakt:request-throttle:next-allowed-at';

    public function __construct(
        private readonly int $intervalMilliseconds = 1000
    ) {}

    public function wait(): void
    {
        if ($this->intervalMilliseconds <= 0) {
            return;
        }

        $lockSeconds = max(5, (int) ceil($this->intervalMilliseconds / 1000) + 5);

        Cache::lock(self::LOCK_KEY, $lockSeconds)->block($lockSeconds, function (): void {
            $nowMs = (int) floor(microtime(true) * 1000);
            $nextAllowedAt = (int) Cache::get(self::NEXT_ALLOWED_AT_KEY, 0);

            if ($nextAllowedAt > $nowMs) {
                usleep(($nextAllowedAt - $nowMs) * 1000);
            }

            $nextAllowedAt = (int) floor(microtime(true) * 1000) + $this->intervalMilliseconds;

            Cache::put(
                self::NEXT_ALLOWED_AT_KEY,
                $nextAllowedAt,
                max(5, (int) ceil($this->intervalMilliseconds / 1000) + 5)
            );
        });
    }
}
