<?php

namespace App\Support;

use RuntimeException;

final class AutoDownloadRuntimePolicy
{
    public const CONNECT_TIMEOUT_SECONDS = 3;

    public const REQUEST_TIMEOUT_SECONDS = 8;

    /**
     * Trusted details fetching permits the initial request plus three redirects.
     * This is the longest outbound chain that can run between cooperative
     * deadline checks.
     */
    public const MAX_REQUESTS_BETWEEN_DEADLINE_CHECKS = 4;

    public const CADENCE_MINUTES = 15;

    public static function retryAfterSeconds(): int
    {
        $connection = (string) config('queue.default', 'database');

        return (int) config("queue.connections.{$connection}.retry_after", 90);
    }

    public static function outboundSafetyMarginSeconds(): int
    {
        return self::REQUEST_TIMEOUT_SECONDS * self::MAX_REQUESTS_BETWEEN_DEADLINE_CHECKS;
    }

    /**
     * Derive the cooperative budget from the queue reservation window instead
     * of choosing an independent timeout. The final second preserves the RFC's
     * strict "budget + safety margin < retry_after" invariant.
     */
    public static function scanBudgetSeconds(): int
    {
        $retryAfter = self::retryAfterSeconds();
        $safetyMargin = self::outboundSafetyMarginSeconds();

        if ($retryAfter <= $safetyMargin + 1) {
            throw new RuntimeException('Auto-download queue retry_after is too small for the bounded outbound I/O policy.');
        }

        return $retryAfter - $safetyMargin - 1;
    }

    public static function lockLifetimeSeconds(): int
    {
        return self::retryAfterSeconds();
    }

    public static function uniqueLifetimeSeconds(): int
    {
        return self::retryAfterSeconds();
    }
}
