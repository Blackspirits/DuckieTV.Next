<?php

namespace Tests\Unit\Services;

use App\Services\TraktRequestThrottle;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TraktRequestThrottleTest extends TestCase
{
    public function test_separate_instances_share_the_same_request_window(): void
    {
        config(['cache.default' => 'array']);
        app('cache')->forgetDriver('array');
        app('cache')->setDefaultDriver('array');
        Cache::flush();

        $first = new TraktRequestThrottle(100);
        $second = new TraktRequestThrottle(100);

        $first->wait();

        $started = microtime(true);
        $second->wait();
        $elapsed = microtime(true) - $started;

        $this->assertGreaterThanOrEqual(0.06, $elapsed);
        $this->assertLessThan(0.5, $elapsed);
    }
}
