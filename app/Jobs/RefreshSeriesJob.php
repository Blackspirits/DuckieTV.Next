<?php

namespace App\Jobs;

use App\Exceptions\RateLimitException;
use App\Models\Serie;
use App\Services\DatabaseRefreshProgressService;
use App\Services\SeriesRefreshService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RefreshSeriesJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @psalm-suppress PossiblyUnusedProperty Laravel reads this queue payload contract reflectively. */
    public $timeout = 180;

    /** @psalm-suppress PossiblyUnusedProperty Laravel reads this queue payload contract reflectively. */
    public int $tries = 0;

    /** @psalm-suppress PossiblyUnusedProperty Laravel reads this queue payload contract reflectively. */
    public int $maxExceptions = 3;

    /** @psalm-suppress PossiblyUnusedProperty Laravel reads this queue payload contract reflectively. */
    public $backoff = [5, 15];

    public function __construct(
        protected int $seriesId
    ) {}

    /** @psalm-suppress PossiblyUnusedMethod Laravel invokes queue handlers reflectively. */
    public function handle(
        SeriesRefreshService $refreshService,
        DatabaseRefreshProgressService $progress
    ): void {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $serie = Serie::find($this->seriesId);

        if (! $serie || ! $serie->trakt_id) {
            $progress->recordFailure(
                $this->seriesId,
                $serie?->name ?: "Series {$this->seriesId}"
            );

            return;
        }

        try {
            $updated = $refreshService->refresh($serie, true);
            $progress->recordSuccess((string) $updated->name);
        } catch (RateLimitException $e) {
            Log::info('RefreshSeriesJob hit Trakt rate limit.', [
                'serie_id' => $this->seriesId,
                'retry_after' => $e->retryAfter,
            ]);
            $this->release($e->retryAfter);
        } catch (\Throwable $e) {
            Log::error('RefreshSeriesJob failed.', [
                'serie_id' => $this->seriesId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            $progress->recordFailure($this->seriesId, (string) $serie->name);
        }
    }
}
