<?php

namespace Tests\Feature\Services;

use App\Jobs\AutoDownloadJob;
use App\Jobs\PruneAutoDLActivitiesJob;
use App\Services\AutoDownloadLifecycleService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AutoDownloadLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    protected SettingsService $settings;

    protected AutoDownloadLifecycleService $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = app(SettingsService::class);
        $this->lifecycle = app(AutoDownloadLifecycleService::class);
        Cache::flush();
    }

    public function test_dispatch_is_blocked_when_torrenting_is_disabled(): void
    {
        Queue::fake();
        $this->settings->set('torrenting.enabled', false);
        $this->settings->set('torrenting.autodownload', true);

        $this->assertFalse($this->lifecycle->dispatchIfEligible());

        Queue::assertNotPushed(AutoDownloadJob::class);
    }

    public function test_dispatch_is_blocked_when_periodic_auto_download_is_disabled(): void
    {
        Queue::fake();
        $this->settings->set('torrenting.enabled', true);
        $this->settings->set('torrenting.autodownload', false);

        $this->assertFalse($this->lifecycle->dispatchIfEligible());

        Queue::assertNotPushed(AutoDownloadJob::class);
    }

    public function test_eligible_dispatch_targets_the_managed_database_default_queue(): void
    {
        Queue::fake();
        $this->settings->set('torrenting.enabled', true);
        $this->settings->set('torrenting.autodownload', true);

        $this->assertTrue($this->lifecycle->dispatchIfEligible());

        Queue::assertPushed(AutoDownloadJob::class, function (AutoDownloadJob $job): bool {
            return $job->connection === AutoDownloadLifecycleService::CONNECTION
                && $job->queue === AutoDownloadLifecycleService::QUEUE;
        });
    }

    public function test_startup_always_dispatches_pruning_but_only_dispatches_scan_when_eligible(): void
    {
        Queue::fake();
        $this->settings->set('torrenting.enabled', true);
        $this->settings->set('torrenting.autodownload', false);

        $this->lifecycle->dispatchStartupMaintenance();

        Queue::assertPushed(PruneAutoDLActivitiesJob::class, 1);
        Queue::assertNotPushed(AutoDownloadJob::class);

        Queue::fake();
        $this->settings->set('torrenting.autodownload', true);

        $this->lifecycle->dispatchStartupMaintenance();

        Queue::assertPushed(PruneAutoDLActivitiesJob::class, 1);
        Queue::assertPushed(AutoDownloadJob::class, 1);
    }

    public function test_startup_resets_stale_connected_state_so_first_reconnect_is_detected(): void
    {
        Queue::fake();
        $this->settings->set('torrenting.enabled', true);
        $this->settings->set('torrenting.autodownload', false);

        $key = 'auto-download:client-connectivity';
        Cache::forever($key, ['client_id' => 'MockClient', 'connected' => true]);

        $this->lifecycle->dispatchStartupMaintenance();

        $this->assertFalse(Cache::has($key));
        Queue::assertPushed(PruneAutoDLActivitiesJob::class, 1);
        Queue::assertNotPushed(AutoDownloadJob::class);

        $this->settings->set('torrenting.autodownload', true);

        $this->assertTrue($this->lifecycle->recordClientConnectivity('MockClient', true));
        $this->assertSame(
            ['client_id' => 'MockClient', 'connected' => true],
            Cache::get($key)
        );
        Queue::assertPushed(AutoDownloadJob::class, 1);
    }

    public function test_connectivity_trigger_dispatches_on_transition_and_uniqueness_prevents_pending_backlog(): void
    {
        Queue::fake();
        $this->settings->set('torrenting.enabled', true);
        $this->settings->set('torrenting.autodownload', true);

        $this->assertFalse($this->lifecycle->recordClientConnectivity('MockClient', false));
        $this->assertTrue($this->lifecycle->recordClientConnectivity('MockClient', true));
        $this->assertFalse($this->lifecycle->recordClientConnectivity('MockClient', true));

        Queue::assertPushed(AutoDownloadJob::class, 1);

        $this->assertFalse($this->lifecycle->recordClientConnectivity('MockClient', false));
        $this->assertTrue($this->lifecycle->recordClientConnectivity('MockClient', true));

        // The second reconnect is a legitimate trigger attempt, but the first
        // unique periodic scan is still pending, so it must not create backlog.
        Queue::assertPushed(AutoDownloadJob::class, 1);
    }

    public function test_switching_clients_does_not_inherit_another_clients_connected_state(): void
    {
        Queue::fake();
        $this->settings->set('torrenting.enabled', true);
        $this->settings->set('torrenting.autodownload', true);

        $this->assertTrue($this->lifecycle->recordClientConnectivity('ClientA', true));
        $this->assertTrue($this->lifecycle->recordClientConnectivity('ClientB', true));

        // Both are legitimate transitions, but dispatch uniqueness keeps only
        // the first pending full scan in the queue.
        Queue::assertPushed(AutoDownloadJob::class, 1);

        $this->assertTrue($this->lifecycle->recordClientConnectivity('ClientA', true));
        Queue::assertPushed(AutoDownloadJob::class, 1);
    }

    public function test_connectivity_transition_respects_feature_eligibility(): void
    {
        Queue::fake();
        $this->settings->set('torrenting.enabled', true);
        $this->settings->set('torrenting.autodownload', false);

        $this->assertFalse($this->lifecycle->recordClientConnectivity('MockClient', true));

        Queue::assertNotPushed(AutoDownloadJob::class);
    }
}
