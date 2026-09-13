<?php

namespace Tests\Feature\Services;

use App\Services\TraktRequestThrottle;
use App\Services\TraktService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class TraktScopedThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_scoped_trakt_requests_use_the_shared_throttle(): void
    {
        $throttle = Mockery::mock(TraktRequestThrottle::class);
        $throttle->shouldReceive('wait')->once();

        $this->app->instance(TraktRequestThrottle::class, $throttle);

        Http::fake([
            'api.trakt.tv/shows/123?extended=full*' => Http::response([
                'title' => 'Test Show',
                'ids' => ['trakt' => 123],
            ], 200),
            'api.trakt.tv/shows/456?extended=full*' => Http::response([
                'title' => 'Other Show',
                'ids' => ['trakt' => 456],
            ], 200),
        ]);

        $service = app(TraktService::class);

        $service->withThrottling(
            fn (): array => $service->serie('123', null, true)
        );

        $service->serie('456', null, true);

        Http::assertSentCount(2);
    }
}
