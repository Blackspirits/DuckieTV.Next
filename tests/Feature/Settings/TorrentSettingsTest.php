<?php

namespace Tests\Feature\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TorrentSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_classic_utorrent_cannot_be_selected_as_an_active_client()
    {
        settings('torrenting.client', 'Transmission');

        $response = $this->postJson(route('settings.update', 'torrent'), [
            'torrenting.client' => 'uTorrent',
        ]);

        $response->assertStatus(422);
        $this->assertEquals('Transmission', settings('torrenting.client'));
    }

    public function test_can_update_qbittorrent_client_setting()
    {
        // Check for the specific key that might be failing
        $response = $this->postJson(route('settings.update', 'torrent'), [
            'torrenting.client' => 'qBittorrent 4.1+',
        ]);

        // If validation fails, this will be 422
        $response->assertStatus(200);
        $this->assertEquals('qBittorrent 4.1+', settings('torrenting.client'));

        // Also verify in database directly
        $this->assertDatabaseHas('settings', [
            'key' => 'torrenting.client',
            'value' => 'qBittorrent 4.1+',
        ]);
    }

    public function test_status_endpoint_reports_disconnected_for_unconfigured_client()
    {
        // Set client but don't configure server/port
        settings('torrenting.client', 'Transmission');

        $response = $this->getJson('/torrents/status');

        $response->assertStatus(200);
        $response->assertJson([
            'connected' => false,
            'client' => 'Transmission',
        ]);
    }
}
