<?php

namespace Tests\Feature\Controllers;

use App\Jobs\RefreshDatabaseJob;
use App\Jobs\RestoreBackupJob;
use App\Models\Serie;
use App\Services\AutoDownloadLifecycleService;
use App\Services\DatabaseMaintenanceLock;
use App\Services\DatabaseMaintenanceService;
use App\Services\DatabaseRefreshProgressService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class SettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_backup_endpoint_downloads_json(): void
    {
        Serie::create([
            'name' => 'Export Me',
            'trakt_id' => 123,
            'customSearchString' => 'PROPER 1080p',
        ]);

        $response = $this->get(route('settings.backup-export'));

        $response->assertOk()
            ->assertHeader('content-type', 'application/json');

        $this->assertStringContainsString(
            'attachment; filename="DuckieTV ',
            (string) $response->headers->get('content-disposition')
        );

        $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($data['settings']['useTrakt_id']);
        $this->assertSame('PROPER 1080p', $data['series']['123'][0]['customSearchString']);
    }

    public function test_auto_backup_settings_view_uses_canonical_periods_and_real_action(): void
    {
        settings('autobackup.period', 'weekly');

        $this->get(route('settings.show', 'backup'))
            ->assertOk()
            ->assertSee('BackupRestore.saveAutoBackupPeriod(this.value)', false)
            ->assertSee('value="never"', false)
            ->assertSee('value="daily"', false)
            ->assertSee('value="weekly"', false)
            ->assertSee('value="monthly"', false)
            ->assertDontSee('Auto-backup setting not yet implemented')
            ->assertDontSee('value="7"', false);
    }

    public function test_auto_backup_period_update_uses_canonical_contract(): void
    {
        $this->postJson(route('settings.update', 'backup'), [
            'autobackup.period' => 'daily',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertSame('daily', settings()->get('autobackup.period'));

        $this->postJson(route('settings.update', 'backup'), [
            'autobackup.period' => '1',
        ])->assertStatus(422);
    }

    public function test_auto_backup_status_initializes_historical_last_run(): void
    {
        settings('autobackup.period', 'daily');

        $response = $this->getJson(route('settings.autobackup-status'))
            ->assertOk()
            ->assertJson([
                'period' => 'daily',
                'due' => false,
            ]);

        $data = $response->json();

        $this->assertIsInt($data['last_run_ms']);
        $this->assertIsInt($data['next_run_ms']);
        $this->assertGreaterThan($data['last_run_ms'], $data['next_run_ms']);
    }

    public function test_auto_backup_status_does_not_initialize_during_other_maintenance(): void
    {
        settings('autobackup.period', 'daily');

        $lock = Mockery::mock(DatabaseMaintenanceLock::class);
        $lock->shouldReceive('acquire')->once()->andReturn(null);
        $lock->shouldNotReceive('release');
        $this->app->instance(DatabaseMaintenanceLock::class, $lock);

        $this->getJson(route('settings.autobackup-status'))
            ->assertStatus(409)
            ->assertExactJson([
                'success' => false,
                'message' => 'Another database maintenance operation is already running.',
            ]);

        $this->assertNull(settings()->get('autobackup.lastrun'));
    }

    public function test_auto_backup_export_records_last_run_after_successful_snapshot(): void
    {
        settings('autobackup.period', 'daily');
        settings('autobackup.lastrun', 1);

        Serie::create([
            'name' => 'Auto Backup Me',
            'trakt_id' => 909,
        ]);

        $response = $this->post(route('settings.autobackup-export'));

        $response->assertOk()
            ->assertHeader('content-type', 'application/json');

        $this->assertStringContainsString(
            'attachment; filename="DuckieTV ',
            (string) $response->headers->get('content-disposition')
        );

        $lastRun = (int) settings()->get('autobackup.lastrun');
        $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertGreaterThan(1, $lastRun);
        $this->assertSame($lastRun, (int) $data['settings']['autobackup.lastrun']);
    }

    public function test_auto_backup_export_rejects_concurrent_maintenance_without_advancing_schedule(): void
    {
        settings('autobackup.period', 'daily');
        settings('autobackup.lastrun', 123);

        $lock = Mockery::mock(DatabaseMaintenanceLock::class);
        $lock->shouldReceive('acquire')->once()->andReturn(null);
        $lock->shouldNotReceive('release');
        $this->app->instance(DatabaseMaintenanceLock::class, $lock);

        $this->postJson(route('settings.autobackup-export'))
            ->assertStatus(409)
            ->assertExactJson([
                'success' => false,
                'message' => 'Another database maintenance operation is already running.',
            ]);

        $this->assertSame(123, (int) settings()->get('autobackup.lastrun'));
    }

    public function test_manual_backup_rejects_concurrent_database_maintenance(): void
    {
        $lock = Mockery::mock(DatabaseMaintenanceLock::class);
        $lock->shouldReceive('acquire')->once()->andReturn(null);
        $lock->shouldNotReceive('release');
        $this->app->instance(DatabaseMaintenanceLock::class, $lock);

        $this->getJson(route('settings.backup-export'))
            ->assertStatus(409)
            ->assertExactJson([
                'success' => false,
                'message' => 'Another database maintenance operation is already running.',
            ]);
    }

    public function test_standalone_wipe_endpoint_executes_database_wipe(): void
    {
        $maintenance = Mockery::mock(DatabaseMaintenanceService::class);
        $maintenance->shouldReceive('wipeUserDatabase')->once();
        $this->app->instance(DatabaseMaintenanceService::class, $maintenance);

        $lock = Mockery::mock(DatabaseMaintenanceLock::class);
        $lock->shouldReceive('acquire')->once()->andReturn('wipe-owner');
        $lock->shouldReceive('release')->once()->with('wipe-owner');
        $this->app->instance(DatabaseMaintenanceLock::class, $lock);

        $this->postJson(route('settings.wipe'))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Database wiped successfully.',
            ]);
    }

    public function test_standalone_wipe_endpoint_fails_closed(): void
    {
        $maintenance = Mockery::mock(DatabaseMaintenanceService::class);
        $maintenance->shouldReceive('wipeUserDatabase')
            ->once()
            ->andThrow(new \RuntimeException('internal failure detail'));
        $this->app->instance(DatabaseMaintenanceService::class, $maintenance);

        $lock = Mockery::mock(DatabaseMaintenanceLock::class);
        $lock->shouldReceive('acquire')->once()->andReturn('wipe-owner');
        $lock->shouldReceive('release')->once()->with('wipe-owner');
        $this->app->instance(DatabaseMaintenanceLock::class, $lock);

        $response = $this->postJson(route('settings.wipe'));

        $response->assertStatus(500)
            ->assertExactJson([
                'success' => false,
                'message' => 'Database wipe failed.',
            ]);
        $this->assertStringNotContainsString('internal failure detail', $response->getContent());
    }

    public function test_standalone_wipe_rejects_concurrent_database_maintenance(): void
    {
        $maintenance = Mockery::mock(DatabaseMaintenanceService::class);
        $maintenance->shouldNotReceive('wipeUserDatabase');
        $this->app->instance(DatabaseMaintenanceService::class, $maintenance);

        $lock = Mockery::mock(DatabaseMaintenanceLock::class);
        $lock->shouldReceive('acquire')->once()->andReturn(null);
        $lock->shouldNotReceive('release');
        $this->app->instance(DatabaseMaintenanceLock::class, $lock);

        $this->postJson(route('settings.wipe'))
            ->assertStatus(409)
            ->assertExactJson([
                'success' => false,
                'message' => 'Another database maintenance operation is already running.',
            ]);
    }

    public function test_backup_settings_view_wires_real_standalone_wipe_action(): void
    {
        $this->get(route('settings.show', 'backup'))
            ->assertOk()
            ->assertSee('BackupRestore.wipeDatabase()', false)
            ->assertDontSee('Wipe functionality not yet implemented');
    }

    public function test_database_refresh_dispatches_snapshot_under_maintenance_lock(): void
    {
        Bus::fake();

        $first = Serie::create(['name' => 'One', 'trakt_id' => 101]);
        $second = Serie::create(['name' => 'Two', 'trakt_id' => 202]);

        $lock = Mockery::mock(DatabaseMaintenanceLock::class);
        $lock->shouldReceive('acquire')->once()->andReturn('refresh-owner');
        $lock->shouldNotReceive('release');
        $this->app->instance(DatabaseMaintenanceLock::class, $lock);

        $progress = Mockery::mock(DatabaseRefreshProgressService::class);
        $progress->shouldReceive('queued')->once()->with(2);
        $progress->shouldNotReceive('fail');
        $this->app->instance(DatabaseRefreshProgressService::class, $progress);

        $this->postJson(route('settings.refresh'))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'status' => 'started',
                'total' => 2,
            ]);

        Bus::assertDispatched(RefreshDatabaseJob::class, function (RefreshDatabaseJob $job) use ($first, $second): bool {
            $ids = new \ReflectionProperty($job, 'seriesIds');
            $owner = new \ReflectionProperty($job, 'maintenanceLockOwner');

            return $ids->getValue($job) === [$first->id, $second->id]
                && $owner->getValue($job) === 'refresh-owner';
        });
    }

    public function test_database_refresh_rejects_concurrent_maintenance(): void
    {
        Bus::fake();

        $lock = Mockery::mock(DatabaseMaintenanceLock::class);
        $lock->shouldReceive('acquire')->once()->andReturn(null);
        $this->app->instance(DatabaseMaintenanceLock::class, $lock);

        $progress = Mockery::mock(DatabaseRefreshProgressService::class);
        $progress->shouldNotReceive('queued');
        $progress->shouldNotReceive('fail');
        $this->app->instance(DatabaseRefreshProgressService::class, $progress);

        $this->postJson(route('settings.refresh'))
            ->assertStatus(409)
            ->assertExactJson([
                'success' => false,
                'message' => 'Another database maintenance operation is already running.',
            ]);

        Bus::assertNotDispatched(RefreshDatabaseJob::class);
    }

    public function test_database_refresh_progress_endpoint_returns_current_state(): void
    {
        $progress = Mockery::mock(DatabaseRefreshProgressService::class);
        $progress->shouldReceive('get')->once()->andReturn([
            'status' => 'running',
            'total' => 4,
            'processed' => 2,
            'completed' => 2,
            'failed' => 0,
            'percent' => 50,
            'current' => 'Two',
            'failures' => [],
            'batch_id' => 'batch-1',
            'message' => 'Refreshing series from Trakt...',
        ]);
        $this->app->instance(DatabaseRefreshProgressService::class, $progress);

        $this->getJson(route('settings.refresh-progress'))
            ->assertOk()
            ->assertJson([
                'status' => 'running',
                'total' => 4,
                'processed' => 2,
                'percent' => 50,
            ]);
    }

    public function test_backup_settings_view_wires_real_database_refresh_action(): void
    {
        $this->get(route('settings.show', 'backup'))
            ->assertOk()
            ->assertSee('BackupRestore.refreshDatabase()', false)
            ->assertDontSee('Refresh functionality not yet implemented');
    }

    public function test_restore_endpoint_dispatches_job()
    {
        Bus::fake();

        $lock = Mockery::mock(DatabaseMaintenanceLock::class);
        $lock->shouldReceive('acquire')->once()->andReturn('restore-owner');
        $lock->shouldNotReceive('release');
        $this->app->instance(DatabaseMaintenanceLock::class, $lock);

        $file = UploadedFile::fake()->createWithContent('backup.json', '{}');

        $response = $this->postJson(route('settings.restore'), [
            'backup_file' => $file,
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        Bus::assertDispatched(RestoreBackupJob::class, function (RestoreBackupJob $job): bool {
            $property = new \ReflectionProperty($job, 'maintenanceLockOwner');

            return $property->getValue($job) === 'restore-owner';
        });
    }

    public function test_restore_endpoint_propagates_wipe_flag(): void
    {
        Bus::fake();

        $lock = Mockery::mock(DatabaseMaintenanceLock::class);
        $lock->shouldReceive('acquire')->once()->andReturn('restore-owner');
        $lock->shouldNotReceive('release');
        $this->app->instance(DatabaseMaintenanceLock::class, $lock);

        $file = UploadedFile::fake()->createWithContent('backup.json', '{}');

        $this->postJson(route('settings.restore'), [
            'backup_file' => $file,
            'wipe' => true,
        ])->assertOk();

        Bus::assertDispatched(RestoreBackupJob::class, function (RestoreBackupJob $job): bool {
            $property = new \ReflectionProperty($job, 'wipe');

            return $property->getValue($job) === true;
        });
    }

    public function test_restore_rejects_concurrent_database_maintenance_without_dispatching(): void
    {
        Bus::fake();

        $lock = Mockery::mock(DatabaseMaintenanceLock::class);
        $lock->shouldReceive('acquire')->once()->andReturn(null);
        $this->app->instance(DatabaseMaintenanceLock::class, $lock);

        $file = UploadedFile::fake()->createWithContent('backup.json', '{}');

        $this->postJson(route('settings.restore'), [
            'backup_file' => $file,
        ])->assertStatus(409)
            ->assertExactJson([
                'success' => false,
                'message' => 'Another database maintenance operation is already running.',
            ]);

        Bus::assertNotDispatched(RestoreBackupJob::class);
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
