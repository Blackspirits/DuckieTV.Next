<?php

namespace Tests\Feature\Controllers;

use App\Services\TorrentSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TorrentSearchSettingsContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_torrent_search_settings_render_canonical_contract_without_writing(): void
    {
        settings('torrenting.searchprovider', 'Nyaa');
        settings('torrenting.searchquality', '1080p');
        settings('torrenting.require_keywords_mode_or', false);
        settings('torrenting.require_keywords', 'proper repack');
        settings('torrenting.ignore_keywords', 'cam ts');
        settings('torrenting.min_seeders', 77);
        settings('torrenting.global_size_min', 350);
        settings('torrenting.global_size_max', 2500);
        $before = \App\Models\Setting::query()->count();

        $this->get(route('settings.show', 'torrent-search'))
            ->assertOk()
            ->assertSee("setSearchProvider('Nyaa')", false)
            ->assertSee("setSearchQuality('1080p')", false)
            ->assertSee("setSearchQuality('2160p')", false)
            ->assertSee("setSearchQuality('x265')", false)
            ->assertSee('torrenting.require_keywords_mode_or', false)
            ->assertSee('torrenting.require_keywords', false)
            ->assertSee('torrenting.ignore_keywords', false)
            ->assertDontSee('torrenting.requirekeywordsmode', false)
            ->assertDontSee('torrenting.requirekeywords', false)
            ->assertDontSee('torrenting.ignorekeywords', false)
            ->assertDontSee('torrenting.unsafe_proxies', false)
            ->assertDontSee('UltraHD', false)
            ->assertDontSee('FullHD', false);

        $this->assertSame($before, \App\Models\Setting::query()->count());
        $this->assertSame('1080p', settings()->get('torrenting.searchquality'));
        $this->assertFalse((bool) settings()->get('torrenting.require_keywords_mode_or'));
    }

    public function test_partial_scalar_update_does_not_flip_require_keywords_mode(): void
    {
        settings('torrenting.require_keywords_mode_or', true);

        $this->postJson(route('settings.update', 'torrent-search'), [
            'torrenting.searchquality' => '1080p',
        ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertTrue((bool) settings()->get('torrenting.require_keywords_mode_or'));
    }

    public function test_torrent_search_settings_persist_partial_canonical_values(): void
    {
        $provider = 'Nyaa';
        $this->assertArrayHasKey($provider, app(TorrentSearchService::class)->getSearchEngines());

        $updates = [
            'torrenting.searchprovider' => $provider,
            'torrenting.searchquality' => '2160p',
            'torrenting.require_keywords_mode_or' => false,
            'torrenting.require_keywords' => 'proper repack',
            'torrenting.ignore_keywords' => 'cam ts',
            'torrenting.min_seeders' => 123,
            'torrenting.global_size_min' => 350,
            'torrenting.global_size_max' => 2500,
        ];

        foreach ($updates as $key => $value) {
            $this->postJson(route('settings.update', 'torrent-search'), [$key => $value])
                ->assertOk()
                ->assertJson(['success' => true]);
        }

        $this->assertSame($provider, settings()->get('torrenting.searchprovider'));
        $this->assertSame($provider, \App\Models\Setting::query()->findOrFail('torrenting.searchprovider')->value);
        $this->assertSame('2160p', settings()->get('torrenting.searchquality'));
        $this->assertFalse((bool) settings()->get('torrenting.require_keywords_mode_or'));
        $this->assertSame('proper repack', settings()->get('torrenting.require_keywords'));
        $this->assertSame('cam ts', settings()->get('torrenting.ignore_keywords'));
        $this->assertSame(123, (int) settings()->get('torrenting.min_seeders'));
        $this->assertSame(350, (int) settings()->get('torrenting.global_size_min'));
        $this->assertSame(2500, (int) settings()->get('torrenting.global_size_max'));
    }

    public function test_all_qualities_persists_historical_empty_string_sentinel(): void
    {
        settings('torrenting.searchquality', '1080p');

        $this->postJson(route('settings.update', 'torrent-search'), [
            'torrenting.searchquality' => '',
        ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('', settings()->get('torrenting.searchquality'));
    }

    public function test_torrent_search_settings_reject_invalid_values_without_overwriting(): void
    {
        settings('torrenting.searchquality', '1080p');
        settings('torrenting.min_seeders', 50);

        $this->postJson(route('settings.update', 'torrent-search'), [
            'torrenting.searchquality' => 'FullHD',
        ])->assertUnprocessable();

        $this->postJson(route('settings.update', 'torrent-search'), [
            'torrenting.min_seeders' => 3001,
        ])->assertUnprocessable();

        $this->postJson(route('settings.update', 'torrent-search'), [
            'torrenting.searchprovider' => '__missing__',
        ])->assertUnprocessable();

        $this->assertSame('1080p', settings()->get('torrenting.searchquality'));
        $this->assertSame(50, (int) settings()->get('torrenting.min_seeders'));
        $this->assertNull(\App\Models\Setting::query()->find('torrenting.searchprovider'));
    }

    public function test_noncanonical_alias_does_not_mutate_torrent_search_settings(): void
    {
        settings('torrenting.require_keywords', 'proper');
        settings('torrenting.require_keywords_mode_or', true);

        $this->postJson(route('settings.update', 'torrent-search'), [
            'torrenting.requirekeywords' => 'wrong-alias',
        ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('proper', settings()->get('torrenting.require_keywords'));
        $this->assertTrue((bool) settings()->get('torrenting.require_keywords_mode_or'));
        $this->assertNull(\App\Models\Setting::query()->find('torrenting.requirekeywords'));
    }

    public function test_legacy_torrent_endpoint_cannot_mutate_torrent_search_contract(): void
    {
        settings('torrenting.searchprovider', 'ThePirateBay');
        settings('torrenting.searchquality', '1080p');
        settings('torrenting.require_keywords', 'PROPER');

        $this->postJson(route('settings.update', 'torrent'), [
            'torrenting.searchprovider' => '__missing__',
            'torrenting.searchquality' => 'FullHD',
            'torrenting.require_keywords' => 'CAM',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertSame('ThePirateBay', settings()->get('torrenting.searchprovider'));
        $this->assertSame('1080p', settings()->get('torrenting.searchquality'));
        $this->assertSame('PROPER', settings()->get('torrenting.require_keywords'));
    }

    public function test_torrent_search_javascript_posts_to_torrent_search_endpoint(): void
    {
        $javascript = file_get_contents(public_path('js/Settings.js'));
        $this->assertIsString($javascript);

        $this->assertStringContainsString("fetch('/settings/torrent-search'", $javascript);
        $this->assertStringContainsString("saveTorrentSearchSetting('torrenting.searchprovider'", $javascript);
        $this->assertStringContainsString("saveTorrentSearchSetting('torrenting.searchquality'", $javascript);
    }
}
