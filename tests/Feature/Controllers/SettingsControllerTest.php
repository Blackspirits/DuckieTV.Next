<?php

namespace Tests\Feature\Controllers;

use App\Jobs\RestoreBackupJob;
use App\Services\AutoDownloadLifecycleService;
use App\Services\SettingsService;
use App\Services\TorrentClients\TorrentClientInterface;
use App\Services\TorrentClientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class SettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_restore_endpoint_dispatches_job()
    {
        Bus::fake();

        $file = UploadedFile::fake()->createWithContent('backup.json', '{}');

        $response = $this->postJson(route('settings.restore'), [
            'backup_file' => $file,
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        Bus::assertDispatched(RestoreBackupJob::class);
    }

    public function test_restore_progress_endpoint_returns_json()
    {
        Cache::put('backup_progress', ['percent' => 50, 'status' => 'running']);

        $response = $this->getJson(route('settings.restore-progress'));

        $response->assertStatus(200)
            ->assertJson(['percent' => 50, 'status' => 'running']);
    }

    public function test_enabling_periodic_auto_download_dispatches_prompt_check(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('torrenting.enabled', true);
        $settings->set('torrenting.autodownload', false);

        $lifecycle = Mockery::mock(AutoDownloadLifecycleService::class);
        $lifecycle->shouldReceive('dispatchIfEligible')->once()->andReturn(true);
        $this->app->instance(AutoDownloadLifecycleService::class, $lifecycle);

        $this->postJson(route('settings.update', 'auto-download'), [
            'torrenting.autodownload' => true,
            'autodownload.period' => 1,
            'autodownload.delay' => 15,
        ])->assertOk()->assertJson(['success' => true]);

    }

    public function test_updating_auto_download_values_while_already_enabled_does_not_add_reenable_trigger(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('torrenting.enabled', true);
        $settings->set('torrenting.autodownload', true);

        $lifecycle = Mockery::mock(AutoDownloadLifecycleService::class);
        $lifecycle->shouldNotReceive('dispatchIfEligible');
        $this->app->instance(AutoDownloadLifecycleService::class, $lifecycle);

        $this->postJson(route('settings.update', 'auto-download'), [
            'torrenting.autodownload' => true,
            'autodownload.period' => 3,
            'autodownload.delay' => 30,
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertSame(3, (int) $settings->get('autodownload.period'));
        $this->assertSame(30, (int) $settings->get('autodownload.delay'));
    }

    public function test_connection_test_exception_records_disconnected_state(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('torrenting.enabled', false);
        $settings->set('torrenting.autodownload', false);

        $client = Mockery::mock(TorrentClientInterface::class);
        $client->shouldReceive('readConfig')->once();
        $client->shouldReceive('connect')->once()->andThrow(new \RuntimeException('offline'));
        $client->shouldReceive('getId')->once()->andReturn('mock-client');
        $client->shouldReceive('getName')->once()->andReturn('Mock Client');

        $clientService = Mockery::mock(TorrentClientService::class);
        $clientService->shouldReceive('getAvailableClients')->zeroOrMoreTimes()->andReturn([]);
        $clientService->shouldReceive('getActiveClient')->once()->andReturn($client);
        $this->app->instance(TorrentClientService::class, $clientService);

        $lifecycle = Mockery::mock(AutoDownloadLifecycleService::class);
        $lifecycle->shouldReceive('recordClientConnectivity')
            ->once()
            ->with('mock-client', false)
            ->andReturn(false);
        $lifecycle->shouldNotReceive('dispatchIfEligible');
        $this->app->instance(AutoDownloadLifecycleService::class, $lifecycle);

        $this->postJson(route('settings.update', 'torrent'), [
            'test' => true,
        ])->assertOk()->assertJson([
            'connection_success' => false,
            'connection_error' => 'Connection to Mock Client failed: offline',
        ]);
    }

    public function test_auto_download_period_and_delay_use_canonical_integer_ranges(): void
    {
        foreach ([0, 22, 1.5] as $period) {
            $this->postJson(route('settings.update', 'auto-download'), [
                'torrenting.autodownload' => true,
                'autodownload.period' => $period,
                'autodownload.delay' => 15,
            ])->assertStatus(422);
        }

        $this->postJson(route('settings.update', 'auto-download'), [
            'torrenting.autodownload' => true,
            'autodownload.period' => 1,
            'autodownload.delay' => -1,
        ])->assertStatus(422);

        $this->postJson(route('settings.update', 'auto-download'), [
            'torrenting.autodownload' => true,
            'autodownload.period' => 21,
            'autodownload.delay' => 0,
        ])->assertOk();
    }

    public function test_rendering_auto_download_settings_does_not_mutate_persisted_values(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('torrenting.autodownload', true);
        $settings->set('autodownload.period', 7);
        $settings->set('autodownload.delay', 45);

        $this->get(route('settings.show', 'auto-download'))->assertOk();

        $settings->restore();

        $this->assertTrue((bool) $settings->get('torrenting.autodownload'));
        $this->assertSame(7, (int) $settings->get('autodownload.period'));
        $this->assertSame(45, (int) $settings->get('autodownload.delay'));
    }

    public function test_auto_download_settings_view_exposes_canonical_units_and_fixed_cadence(): void
    {
        $response = $this->get(route('settings.show', 'auto-download'));

        $response->assertOk()
            ->assertSee('15 minutes')
            ->assertSee('Lookback (days)')
            ->assertSee('Delay (minutes)')
            ->assertDontSee('Default: 6 hours');
    }
}
