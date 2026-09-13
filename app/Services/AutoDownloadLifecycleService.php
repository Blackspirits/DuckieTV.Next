<?php

namespace App\Services;

use App\Jobs\AutoDownloadJob;
use App\Jobs\PruneAutoDLActivitiesJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** @psalm-api Container-resolved lifecycle coordinator. */
class AutoDownloadLifecycleService
{
    public const CONNECTION = 'database';

    public const QUEUE = 'autodownload';

    /**
     * AutoDL has a dedicated recovery SLA independent of the shared database
     * connection's retry_after, which must accommodate much longer jobs.
     */
    public const RECOVERY_AFTER_SECONDS = 90;

    private const CLIENT_STATE_CACHE_KEY = 'auto-download:client-connectivity';

    public function __construct(protected SettingsService $settings) {}

    public function dispatchIfEligible(): bool
    {
        // Lifecycle triggers can be invoked after another SettingsService instance
        // has persisted changes in the same long-lived process. Always refresh the
        // local cache before evaluating dispatch eligibility.
        $this->settings->restore();

        if ((bool) $this->settings->get('torrenting.enabled', true) === false) {
            return false;
        }

        if ((bool) $this->settings->get('torrenting.autodownload', false) === false) {
            return false;
        }

        $this->recoverExpiredReservation();

        // A dedicated queue makes pending and reserved periodic work observable
        // across desktop restarts. Keep the existing row as the recovery unit even
        // after the finite dispatch-uniqueness lease expires; ShouldBeUnique still
        // closes the race between simultaneous triggers that both observe no row.
        if (DB::table('jobs')->where('queue', self::QUEUE)->exists()) {
            return false;
        }

        AutoDownloadJob::dispatch()
            ->onConnection(self::CONNECTION)
            ->onQueue(self::QUEUE);

        return true;
    }

    /**
     * Release only an expired AutoDL reservation while preserving its attempt
     * count. The existing row remains the recovery unit and still gates any
     * competing lifecycle dispatch.
     */
    public function recoverExpiredReservation(): int
    {
        return DB::table('jobs')
            ->where('queue', self::QUEUE)
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '<=', now()->subSeconds(self::RECOVERY_AFTER_SECONDS)->timestamp)
            ->update(['reserved_at' => null]);
    }

    public function dispatchStartupMaintenance(): void
    {
        // Connectivity state is meaningful only within the current desktop
        // lifecycle. Never inherit a previous process' connected state.
        Cache::forget(self::CLIENT_STATE_CACHE_KEY);

        $this->recoverExpiredReservation();
        $this->dispatchIfEligible();

        PruneAutoDLActivitiesJob::dispatch();
    }

    /** @psalm-suppress PossiblyUnusedReturnValue Transition signal is intentionally available to lifecycle callers. */
    public function recordClientConnectivity(string $clientId, bool $connected): bool
    {
        $previous = Cache::get(self::CLIENT_STATE_CACHE_KEY);
        $wasConnected = is_array($previous)
            && ($previous['client_id'] ?? null) === $clientId
            && (bool) ($previous['connected'] ?? false);

        Cache::forever(self::CLIENT_STATE_CACHE_KEY, [
            'client_id' => $clientId,
            'connected' => $connected,
        ]);

        if ($connected && $wasConnected === false) {
            return $this->dispatchIfEligible();
        }

        return false;
    }
}
