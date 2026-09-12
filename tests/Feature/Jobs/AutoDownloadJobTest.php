<?php

use App\Jobs\AutoDownloadJob;
use App\Services\AutoDownloadService;
use App\Services\TorrentClients\BaseTorrentClient;
use App\Services\TorrentSearchEngines\GenericSearchEngine;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

it('can be dispatched to a queue', function () {
    Queue::fake();

    AutoDownloadJob::dispatch();

    Queue::assertPushed(AutoDownloadJob::class);
});

it('is unique at dispatch and protected against execution overlap', function () {
    $job = new AutoDownloadJob;

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('periodic-full-scan')
        ->and($job->uniqueFor)->toBeGreaterThan(AutoDownloadJob::OVERLAP_EXPIRY_SECONDS);

    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);
});

it('prevents a second execution while the overlap lock is held without releasing it for retry', function () {
    config(['cache.default' => 'array']);
    Cache::flush();

    $middleware = (new AutoDownloadJob)->middleware()[0];
    $firstRan = false;
    $secondRan = false;

    $middleware->handle(new stdClass, function () use ($middleware, &$firstRan, &$secondRan): void {
        $firstRan = true;

        $middleware->handle(new stdClass, function () use (&$secondRan): void {
            $secondRan = true;
        });
    });

    expect($firstRan)->toBeTrue()
        ->and($secondRan)->toBeFalse()
        ->and($middleware->releaseAfter)->toBeNull()
        ->and($middleware->expiresAfter)->toBe(AutoDownloadJob::OVERLAP_EXPIRY_SECONDS);
});

it('keeps cooperative, in-flight IO, timeout, overlap, and queue retry budgets ordered', function () {
    $retryAfter = (int) config('queue.connections.database.retry_after');
    $searchRequestTimeout = (new ReflectionClass(GenericSearchEngine::class))
        ->getReflectionConstant('REQUEST_TIMEOUT_SECONDS')
        ?->getValue();
    $clientRequestTimeout = (new ReflectionClass(BaseTorrentClient::class))
        ->getReflectionConstant('REQUEST_TIMEOUT_SECONDS')
        ?->getValue();

    expect($searchRequestTimeout)->toBeInt()
        ->and($clientRequestTimeout)->toBeInt();

    // ShowRSS uses at most two sequential search requests. uTorrent Web UI is
    // the widest client operation: request + one token refresh + one retry.
    $maxInFlightIoSeconds = max(
        2 * $searchRequestTimeout,
        3 * $clientRequestTimeout,
    );
    $job = new AutoDownloadJob;

    expect(AutoDownloadJob::SCAN_BUDGET_SECONDS + $maxInFlightIoSeconds)
        ->toBeLessThan($job->timeout)
        ->and($job->timeout)->toBeLessThan(AutoDownloadJob::OVERLAP_EXPIRY_SECONDS)
        ->and(AutoDownloadJob::OVERLAP_EXPIRY_SECONDS)->toBeLessThan($retryAfter)
        ->and($job->tries)->toBe(1)
        ->and($job->uniqueFor)->toBeGreaterThan(AutoDownloadJob::OVERLAP_EXPIRY_SECONDS);
});

it('delegates periodic business work to the canonical service with a bounded deadline', function () {
    Carbon::setTestNow('2026-09-12 12:00:00');

    /** @var AutoDownloadService&MockInterface $service */
    $service = Mockery::mock(AutoDownloadService::class);
    $service->shouldReceive('check')
        ->once()
        ->withArgs(function (?Carbon $deadline): bool {
            return $deadline !== null
                && $deadline->equalTo(now()->addSeconds(AutoDownloadJob::SCAN_BUDGET_SECONDS));
        });

    (new AutoDownloadJob)->handle($service);

    Carbon::setTestNow();
});
