<?php

namespace Tests\Feature\Services;

use App\Jobs\TraktUpdateJob;
use App\Services\SettingsService;
use App\Services\TraktUpdateLifecycleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TraktUpdateLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    protected TraktUpdateLifecycleService $lifecycle;

    protected SettingsService $settings;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'queue.default' => 'database',
            'queue.connections.database.connection' => config('database.default'),
        ]);

        Cache::flush();
        Carbon::setTestNow('2026-09-13 16:00:00');

        $this->settings = app(SettingsService::class);
        $this->lifecycle = app(TraktUpdateLifecycleService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_startup_dispatches_one_delayed_job_to_the_dedicated_database_queue(): void
    {
        $this->assertTrue($this->lifecycle->dispatchStartup());

        $row = DB::table('jobs')
            ->where('queue', TraktUpdateLifecycleService::QUEUE)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(now()->addSeconds(TraktUpdateLifecycleService::STARTUP_DELAY_SECONDS)->timestamp, (int) $row->available_at);

        $payload = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(TraktUpdateJob::class, $payload['data']['commandName']);

        $command = unserialize($payload['data']['command']);
        $this->assertSame(TraktUpdateLifecycleService::CONNECTION, $command->connection);
        $this->assertSame(TraktUpdateLifecycleService::QUEUE, $command->queue);
    }

    public function test_scheduler_skips_dispatch_while_show_and_trending_checks_are_fresh(): void
    {
        $this->settings->set('trakt-update.period', 2);
        $this->settings->set('trakttv.lastupdated', now()->subHour()->getTimestampMs());
        $this->settings->set('trakttv.lastupdated.trending', now()->subHours(12)->getTimestampMs());

        $this->assertFalse($this->lifecycle->dispatchIfDue());
        $this->assertSame(
            0,
            DB::table('jobs')->where('queue', TraktUpdateLifecycleService::QUEUE)->count()
        );
    }

    public function test_scheduler_dispatches_when_show_update_period_is_due(): void
    {
        $this->settings->set('trakt-update.period', 2);
        $this->settings->set('trakttv.lastupdated', now()->subHours(2)->getTimestampMs());
        $this->settings->set('trakttv.lastupdated.trending', now()->getTimestampMs());

        $this->assertTrue($this->lifecycle->dispatchIfDue());
        $this->assertSame(
            1,
            DB::table('jobs')->where('queue', TraktUpdateLifecycleService::QUEUE)->count()
        );
    }

    public function test_scheduler_dispatches_when_trending_is_due_even_if_show_update_is_fresh(): void
    {
        $this->settings->set('trakt-update.period', 24);
        $this->settings->set('trakttv.lastupdated', now()->getTimestampMs());
        $this->settings->set('trakttv.lastupdated.trending', now()->subDays(2)->getTimestampMs());

        $this->assertTrue($this->lifecycle->dispatchIfDue());
        $this->assertSame(
            1,
            DB::table('jobs')->where('queue', TraktUpdateLifecycleService::QUEUE)->count()
        );
    }

    public function test_existing_pending_or_reserved_row_blocks_duplicate_dispatch(): void
    {
        DB::table('jobs')->insert([
            'queue' => TraktUpdateLifecycleService::QUEUE,
            'payload' => '{}',
            'attempts' => 2,
            'reserved_at' => now()->timestamp,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        $this->assertFalse($this->lifecycle->dispatchIfIdle());
        $this->assertSame(
            1,
            DB::table('jobs')->where('queue', TraktUpdateLifecycleService::QUEUE)->count()
        );
    }

    public function test_shared_dispatch_lock_closes_startup_scheduler_race(): void
    {
        $lock = Cache::lock(TraktUpdateLifecycleService::DISPATCH_LOCK, 10);
        $this->assertTrue($lock->get());

        try {
            $this->assertFalse($this->lifecycle->dispatchIfIdle());
            $this->assertSame(
                0,
                DB::table('jobs')->where('queue', TraktUpdateLifecycleService::QUEUE)->count()
            );
        } finally {
            $lock->release();
        }

        $this->assertTrue($this->lifecycle->dispatchIfIdle());
        $this->assertSame(
            1,
            DB::table('jobs')->where('queue', TraktUpdateLifecycleService::QUEUE)->count()
        );
    }
}
