<?php

namespace App\Services;

use App\Jobs\AutoDownloadJob;
use App\Jobs\AutoDownloadTickJob;
use App\Jobs\PruneAutoDLActivitiesJob;
use App\Support\AutoDownloadRuntimePolicy;
use Illuminate\Support\Facades\Cache;

class AutoDownloadLifecycleService
{
    private const CLIENT_STATE_KEY = 'autodownload:lifecycle:client-usable';

    private const PRUNE_GATE_KEY = 'autodownload:lifecycle:prune-dispatched';

    public function __construct(protected SettingsService $settings) {}

    public function onStartup(): void
    {
        $this->scheduleNextTick();
        $this->dispatchPruneIfDue();
        $this->dispatchPeriodicIfEnabled();
    }

    public function onCadenceTick(): void
    {
        // Schedule the successor first so a later failure cannot silently stop cadence.
        $this->scheduleNextTick();
        $this->dispatchPruneIfDue();
        $this->dispatchPeriodicIfEnabled();
    }

    public function onAutoDownloadReEnabled(): void
    {
        $this->scheduleNextTick();
        $this->dispatchPeriodicIfEnabled();
    }

    public function recordClientConnectivity(bool $connected): void
    {
        $previous = Cache::get(self::CLIENT_STATE_KEY);
        Cache::forever(self::CLIENT_STATE_KEY, $connected);

        if ($connected && $previous !== true) {
            $this->dispatchPeriodicIfEnabled();
        }
    }

    public function resetClientConnectivity(): void
    {
        Cache::forever(self::CLIENT_STATE_KEY, false);
    }

    public function dispatchPeriodicIfEnabled(): bool
    {
        if (! (bool) $this->settings->get('torrenting.enabled', true)
            || ! (bool) $this->settings->get('torrenting.autodownload', false)) {
            return false;
        }

        AutoDownloadJob::dispatch();

        return true;
    }

    protected function scheduleNextTick(): void
    {
        AutoDownloadTickJob::dispatch()
            ->delay(now()->addMinutes(AutoDownloadRuntimePolicy::CADENCE_MINUTES));
    }

    protected function dispatchPruneIfDue(): void
    {
        if (! Cache::add(self::PRUNE_GATE_KEY, true, now()->addDay())) {
            return;
        }

        PruneAutoDLActivitiesJob::dispatch();
    }
}
