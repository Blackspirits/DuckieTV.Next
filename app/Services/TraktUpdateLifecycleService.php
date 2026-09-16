<?php

namespace App\Services;

use App\Jobs\TraktUpdateJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** @psalm-api Container-resolved periodic Trakt coordinator. */
class TraktUpdateLifecycleService
{
    public const CONNECTION = 'database';

    public const QUEUE = 'trakt-update';

    public const DISPATCH_LOCK = 'trakt-update:lifecycle-dispatch';

    public const STARTUP_DELAY_SECONDS = 5;

    private const DISPATCH_LOCK_SECONDS = 10;

    private const TRENDING_PERIOD_MILLISECONDS = 24 * 60 * 60 * 1000;

    public function __construct(
        private readonly SettingsService $settings
    ) {}

    /**
     * Dispatch only when the configured show-update period or daily trending
     * refresh is actually due. The job repeats the same gate defensively.
     */
    public function dispatchIfDue(int $delaySeconds = 0): bool
    {
        $this->settings->restore();

        if (! $this->isDue()) {
            return false;
        }

        return $this->dispatchIfIdle($delaySeconds);
    }

    /**
     * Dispatch one durable periodic refresh when no prior Trakt refresh row exists.
     *
     * The short shared cache lock closes the race between startup and scheduler
     * triggers. The dedicated database-queue row is the durable gate afterwards,
     * including while the job is delayed, reserved, or released for Retry-After.
     */
    public function dispatchIfIdle(int $delaySeconds = 0): bool
    {
        $result = Cache::lock(self::DISPATCH_LOCK, self::DISPATCH_LOCK_SECONDS)
            ->get(function () use ($delaySeconds): bool {
                if (DB::table('jobs')->where('queue', self::QUEUE)->exists()) {
                    return false;
                }

                $job = (new TraktUpdateJob)
                    ->onConnection(self::CONNECTION)
                    ->onQueue(self::QUEUE);

                if ($delaySeconds > 0) {
                    $job->delay(now()->addSeconds($delaySeconds));
                }

                Bus::dispatch($job);

                return true;
            });

        return $result === true;
    }

    /**
     * Match the original Angular startup contract: first check after five seconds.
     */
    public function dispatchStartup(): bool
    {
        return $this->dispatchIfDue(self::STARTUP_DELAY_SECONDS);
    }

    private function isDue(): bool
    {
        $nowMs = now()->getTimestampMs();
        $periodHours = max(1, min(24, (int) $this->settings->get('trakt-update.period', 12)));
        $lastUpdated = (int) $this->settings->get('trakttv.lastupdated', 0);
        $lastTrendingUpdate = (int) $this->settings->get('trakttv.lastupdated.trending', 0);

        $showUpdateDue = $lastUpdated <= 0
            || ($lastUpdated + ($periodHours * 60 * 60 * 1000)) <= $nowMs;

        $trendingUpdateDue = $lastTrendingUpdate <= 0
            || ($lastTrendingUpdate + self::TRENDING_PERIOD_MILLISECONDS) < $nowMs;

        return $showUpdateDue || $trendingUpdateDue;
    }
}
