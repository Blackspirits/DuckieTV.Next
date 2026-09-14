<?php

namespace Tests\Feature\Services;

use App\Services\DatabaseRefreshProgressService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DatabaseRefreshProgressServiceTest extends TestCase
{
    public function test_progress_tracks_successes_failures_and_completion(): void
    {
        Cache::flush();

        $progress = app(DatabaseRefreshProgressService::class);
        $progress->queued(2);
        $progress->running();
        $progress->recordSuccess('One');
        $progress->recordFailure(22, 'Two');
        $progress->complete();

        $state = $progress->get();

        $this->assertSame('completed', $state['status']);
        $this->assertSame(2, $state['total']);
        $this->assertSame(2, $state['processed']);
        $this->assertSame(1, $state['completed']);
        $this->assertSame(1, $state['failed']);
        $this->assertSame(100, $state['percent']);
        $this->assertSame('Two', $state['current']);
        $this->assertSame([
            [
                'id' => 22,
                'name' => 'Two',
                'message' => 'Refresh failed.',
            ],
        ], $state['failures']);
        $this->assertSame('Database refresh completed with failures.', $state['message']);
    }
}
