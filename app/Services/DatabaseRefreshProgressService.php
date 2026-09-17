<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class DatabaseRefreshProgressService
{
    private const CACHE_KEY = 'database_refresh_progress';

    private const LOCK_KEY = 'database_refresh_progress:update';

    public function queued(int $total): void
    {
        Cache::put(self::CACHE_KEY, [
            'status' => 'queued',
            'message' => 'Database refresh queued...',
            'total' => $total,
            'processed' => 0,
            'completed' => 0,
            'failed' => 0,
            'percent' => $total === 0 ? 100 : 0,
            'current' => null,
            'failures' => [],
            'batch_id' => null,
        ]);
    }

    public function running(): void
    {
        $this->mutate(function (array $progress): array {
            $progress['status'] = 'running';
            $progress['message'] = 'Refreshing series from Trakt...';

            return $progress;
        });
    }

    public function setBatchId(string $batchId): void
    {
        $this->mutate(function (array $progress) use ($batchId): array {
            $progress['batch_id'] = $batchId;

            return $progress;
        });
    }

    public function recordSuccess(string $seriesName): void
    {
        $this->mutate(function (array $progress) use ($seriesName): array {
            $progress['processed'] = (int) ($progress['processed'] ?? 0) + 1;
            $progress['completed'] = (int) ($progress['completed'] ?? 0) + 1;
            $progress['current'] = $seriesName;
            $this->recalculatePercent($progress);

            return $progress;
        });
    }

    public function recordFailure(int $seriesId, string $seriesName): void
    {
        $this->mutate(function (array $progress) use ($seriesId, $seriesName): array {
            $progress['processed'] = (int) ($progress['processed'] ?? 0) + 1;
            $progress['failed'] = (int) ($progress['failed'] ?? 0) + 1;
            $progress['current'] = $seriesName;
            $failures = $progress['failures'] ?? [];
            $failures = is_array($failures) ? $failures : [];
            $failures[] = [
                'id' => $seriesId,
                'name' => $seriesName,
                'message' => 'Refresh failed.',
            ];
            $progress['failures'] = $failures;
            $this->recalculatePercent($progress);

            return $progress;
        });
    }

    public function complete(): void
    {
        $this->mutate(function (array $progress): array {
            $progress['status'] = 'completed';
            $progress['percent'] = 100;
            $progress['message'] = ((int) ($progress['failed'] ?? 0)) > 0
                ? 'Database refresh completed with failures.'
                : 'Database refresh completed.';

            return $progress;
        });
    }

    public function fail(string $message = 'Database refresh failed.'): void
    {
        $this->mutate(function (array $progress) use ($message): array {
            $progress['status'] = 'failed';
            $progress['message'] = $message;

            return $progress;
        });
    }

    public function get(): array
    {
        $progress = Cache::get(self::CACHE_KEY, $this->defaults());

        return is_array($progress) ? $progress : $this->defaults();
    }

    private function mutate(\Closure $callback): void
    {
        Cache::lock(self::LOCK_KEY, 10)->block(10, function () use ($callback): array {
            $progress = Cache::get(self::CACHE_KEY, $this->defaults());
            $progress = is_array($progress) ? $progress : $this->defaults();
            $updated = $callback($progress);
            Cache::put(self::CACHE_KEY, $updated);

            return $updated;
        });
    }

    private function recalculatePercent(array &$progress): void
    {
        $total = max(0, (int) ($progress['total'] ?? 0));
        $processed = max(0, (int) ($progress['processed'] ?? 0));
        $progress['percent'] = $total === 0
            ? 100
            : min(100, (int) floor(($processed / $total) * 100));
    }

    private function defaults(): array
    {
        return [
            'status' => 'idle',
            'message' => 'Waiting for start...',
            'total' => 0,
            'processed' => 0,
            'completed' => 0,
            'failed' => 0,
            'percent' => 0,
            'current' => null,
            'failures' => [],
            'batch_id' => null,
        ];
    }
}
