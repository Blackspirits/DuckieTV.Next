<?php

namespace App\Services;

use App\Models\Serie;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;

class AutoBackupLifecycleService
{
    private const PERIODS = ['never', 'daily', 'weekly', 'monthly'];

    public function __construct(
        private readonly SettingsService $settings
    ) {}

    public function status(?int $nowMilliseconds = null): array
    {
        $period = (string) $this->settings->get('autobackup.period', 'monthly');

        if (! in_array($period, self::PERIODS, true)) {
            Log::warning('Invalid auto-backup period; disabling schedule.', [
                'period' => $period,
            ]);
            $period = 'never';
        }

        if ($period === 'never') {
            return [
                'period' => $period,
                'due' => false,
                'has_favorites' => Serie::query()->exists(),
                'last_run_ms' => $this->numericSetting('autobackup.lastrun'),
                'next_run_ms' => null,
            ];
        }

        $currentMilliseconds = $nowMilliseconds ?? $this->currentMilliseconds();
        $now = $this->dateFromMilliseconds($currentMilliseconds);
        $lastRunMilliseconds = $this->numericSetting('autobackup.lastrun');

        if ($lastRunMilliseconds === null) {
            $lastRunMilliseconds = $this->millisecondsFromDate($now);
            $this->settings->set('autobackup.lastrun', $lastRunMilliseconds);
        }

        $lastRun = $this->dateFromMilliseconds($lastRunMilliseconds);
        $interval = match ($period) {
            'daily' => new DateInterval('P1D'),
            'weekly' => new DateInterval('P7D'),
            'monthly' => new DateInterval('P1M'),
            default => new DateInterval('P0D'),
        };
        $nextRunMilliseconds = $this->millisecondsFromDate($lastRun->add($interval));

        $hasFavorites = Serie::query()->exists();

        return [
            'period' => $period,
            'due' => $hasFavorites && $currentMilliseconds >= $nextRunMilliseconds,
            'has_favorites' => $hasFavorites,
            'last_run_ms' => $lastRunMilliseconds,
            'next_run_ms' => $nextRunMilliseconds,
        ];
    }

    public function recordRun(?int $nowMilliseconds = null): void
    {
        $this->settings->set(
            'autobackup.lastrun',
            $nowMilliseconds ?? $this->currentMilliseconds()
        );
    }

    private function numericSetting(string $key): ?int
    {
        $value = $this->settings->get($key);

        return is_numeric($value) ? (int) $value : null;
    }

    private function currentMilliseconds(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    private function dateFromMilliseconds(int $milliseconds): DateTimeImmutable
    {
        $timezone = new DateTimeZone((string) config('app.timezone', 'UTC'));

        return (new DateTimeImmutable('@'.intdiv($milliseconds, 1000)))
            ->setTimezone($timezone);
    }

    private function millisecondsFromDate(DateTimeImmutable $date): int
    {
        return ((int) $date->format('U')) * 1000;
    }
}
