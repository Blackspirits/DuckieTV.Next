<?php

namespace Tests\Feature\Services;

use App\Models\Serie;
use App\Services\AutoBackupLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoBackupLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_never_period_initializes_last_run_without_becoming_due(): void
    {
        settings('autobackup.period', 'daily');

        $now = strtotime('2026-01-15 12:00:00 UTC') * 1000;
        $status = app(AutoBackupLifecycleService::class)->status($now);

        $this->assertSame('daily', $status['period']);
        $this->assertFalse($status['due']);
        $this->assertSame($now, $status['last_run_ms']);
        $this->assertSame($now + 86400000, $status['next_run_ms']);
    }

    public function test_due_schedule_requires_at_least_one_favorite(): void
    {
        settings('autobackup.period', 'daily');

        $now = strtotime('2026-01-15 12:00:00 UTC') * 1000;
        settings('autobackup.lastrun', $now - (2 * 86400000));

        $withoutFavorite = app(AutoBackupLifecycleService::class)->status($now);
        $this->assertFalse($withoutFavorite['due']);
        $this->assertFalse($withoutFavorite['has_favorites']);

        Serie::create([
            'name' => 'Scheduled Backup Show',
            'trakt_id' => 101,
        ]);

        $withFavorite = app(AutoBackupLifecycleService::class)->status($now);
        $this->assertTrue($withFavorite['due']);
        $this->assertTrue($withFavorite['has_favorites']);
    }

    public function test_never_period_does_not_initialize_last_run(): void
    {
        settings('autobackup.period', 'never');

        $now = strtotime('2026-01-15 12:00:00 UTC') * 1000;
        $status = app(AutoBackupLifecycleService::class)->status($now);

        $this->assertSame('never', $status['period']);
        $this->assertFalse($status['due']);
        $this->assertNull($status['last_run_ms']);
        $this->assertNull($status['next_run_ms']);
        $this->assertNull(settings()->get('autobackup.lastrun'));
    }

    public function test_monthly_schedule_preserves_javascript_date_overflow_semantics(): void
    {
        settings('autobackup.period', 'monthly');

        $lastRun = strtotime('2026-01-31 12:00:00 UTC') * 1000;
        settings('autobackup.lastrun', $lastRun);

        $now = strtotime('2026-02-15 12:00:00 UTC') * 1000;
        $status = app(AutoBackupLifecycleService::class)->status($now);

        $this->assertSame(
            strtotime('2026-03-03 12:00:00 UTC') * 1000,
            $status['next_run_ms']
        );
        $this->assertFalse($status['due']);
    }

    public function test_recording_a_run_advances_the_schedule(): void
    {
        settings('autobackup.period', 'weekly');

        Serie::create([
            'name' => 'Scheduled Backup Show',
            'trakt_id' => 202,
        ]);

        $now = strtotime('2026-01-15 12:00:00 UTC') * 1000;
        settings('autobackup.lastrun', $now - (8 * 86400000));

        $service = app(AutoBackupLifecycleService::class);
        $this->assertTrue($service->status($now)['due']);

        $service->recordRun($now);
        $status = $service->status($now);

        $this->assertFalse($status['due']);
        $this->assertSame($now, $status['last_run_ms']);
        $this->assertSame($now + (7 * 86400000), $status['next_run_ms']);
    }
}
