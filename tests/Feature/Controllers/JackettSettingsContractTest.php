<?php

namespace Tests\Feature\Controllers;

use App\Models\Jackett;
use App\Services\TorrentSearchEngines\JackettTorznabEngine;
use App\Services\TorrentSearchEngines\NyaaEngine;
use App\Services\TorrentSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JackettSettingsContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_page_is_live_and_never_renders_api_keys(): void
    {
        Jackett::create([
            'name' => 'Torznab One',
            'torznab' => 'http://localhost:9117/api/v2.0/indexers/one/results/torznab/?apikey=embedded-endpoint-secret#fragment',
            'enabled' => 1,
            'torznabEnabled' => 1,
            'apiKey' => 'never-render-this-secret',
        ]);

        Jackett::create([
            'name' => 'Legacy Admin',
            'torznab' => 'http://localhost:9117/torznab/admin?apikey=admin-endpoint-secret',
            'enabled' => 0,
            'torznabEnabled' => 0,
            'apiKey' => 'legacy-secret',
        ]);

        $this->get(route('settings.show', 'jackett-search'))
            ->assertOk()
            ->assertSee('Jackett / Torznab')
            ->assertSee('Torznab One')
            ->assertSee('Legacy Admin')
            ->assertSee('Historical Jackett Admin API configuration preserved.')
            ->assertDontSee('never-render-this-secret')
            ->assertDontSee('legacy-secret')
            ->assertDontSee('embedded-endpoint-secret')
            ->assertDontSee('admin-endpoint-secret');

        $this->get(route('settings.index'))
            ->assertOk()
            ->assertSee(route('settings.show', 'jackett-search'), false);
    }

    public function test_create_sanitizes_endpoint_keeps_key_write_only_and_refreshes_registry(): void
    {
        $before = app(TorrentSearchService::class);
        $this->assertArrayNotHasKey('New Torznab', $before->getSearchEngines());

        $response = $this->postJson(route('settings.jackett.store'), [
            'name' => ' New Torznab ',
            'torznab' => 'http://localhost:9117/api/v2.0/indexers/new/results/torznab/?old=query#fragment',
            'apiKey' => 'new-secret-key',
            'enabled' => true,
        ])->assertCreated()
            ->assertJson([
                'success' => true,
                'name' => 'New Torznab',
                'enabled' => true,
            ]);

        $this->assertStringNotContainsString('new-secret-key', $response->getContent());

        $jackett = Jackett::query()->where('name', 'New Torznab')->firstOrFail();
        $this->assertSame(
            'http://localhost:9117/api/v2.0/indexers/new/results/torznab/',
            $jackett->torznab
        );
        $this->assertSame('new-secret-key', $jackett->apiKey);
        $this->assertSame(1, (int) $jackett->torznabEnabled);

        $after = app(TorrentSearchService::class);
        $this->assertNotSame($before, $after);
        $this->assertInstanceOf(
            JackettTorznabEngine::class,
            $after->getSearchEngine('New Torznab')
        );
    }

    public function test_duplicate_name_and_unsafe_endpoints_are_rejected(): void
    {
        Jackett::create([
            'name' => 'Duplicate',
            'torznab' => 'http://localhost:9117/torznab/duplicate',
            'enabled' => 0,
            'torznabEnabled' => 1,
            'apiKey' => 'existing',
        ]);

        $this->postJson(route('settings.jackett.store'), [
            'name' => 'Duplicate',
            'torznab' => 'http://localhost:9117/torznab/new',
            'apiKey' => 'secret',
        ])->assertUnprocessable();

        foreach ([
            'file:///tmp/indexer',
            'http://user:pass@localhost:9117/torznab/test',
        ] as $endpoint) {
            $response = $this->postJson(route('settings.jackett.store'), [
                'name' => 'Unsafe '.md5($endpoint),
                'torznab' => $endpoint,
                'apiKey' => 'secret',
            ])->assertUnprocessable();

            $this->assertStringNotContainsString('secret', $response->getContent());
        }
    }

    public function test_update_preserves_blank_api_key_and_name_is_immutable(): void
    {
        $jackett = Jackett::create([
            'name' => 'Immutable Name',
            'torznab' => 'http://localhost:9117/torznab/original',
            'enabled' => 1,
            'torznabEnabled' => 1,
            'apiKey' => 'keep-this-secret',
        ]);

        $response = $this->patchJson(route('settings.jackett.update', $jackett), [
            'name' => 'Renamed',
            'torznab' => 'http://127.0.0.1:9117/torznab/updated?drop=me#drop',
            'apiKey' => '',
            'enabled' => false,
        ])->assertOk();

        $this->assertStringNotContainsString('keep-this-secret', $response->getContent());

        $jackett->refresh();
        $this->assertSame('Immutable Name', $jackett->name);
        $this->assertSame('http://127.0.0.1:9117/torznab/updated', $jackett->torznab);
        $this->assertSame('keep-this-secret', $jackett->apiKey);
        $this->assertSame(0, (int) $jackett->enabled);
    }

    public function test_current_default_cannot_be_disabled_or_deleted(): void
    {
        $jackett = Jackett::create([
            'name' => 'Protected Indexer',
            'torznab' => 'http://localhost:9117/torznab/protected',
            'enabled' => 1,
            'torznabEnabled' => 1,
            'apiKey' => 'secret',
        ]);
        settings('torrenting.searchprovider', 'Protected Indexer');

        $this->patchJson(route('settings.jackett.update', $jackett), [
            'enabled' => false,
        ])->assertUnprocessable();

        $this->deleteJson(route('settings.jackett.destroy', $jackett))
            ->assertUnprocessable();

        $jackett->refresh();
        $this->assertSame(1, (int) $jackett->enabled);
    }

    public function test_inconsistent_disabled_default_can_be_repaired_without_reenabling_or_disabling_it(): void
    {
        $jackett = Jackett::create([
            'name' => 'Disabled Default',
            'torznab' => 'http://localhost:9117/torznab/old',
            'enabled' => 0,
            'torznabEnabled' => 1,
            'apiKey' => 'secret',
        ]);
        settings('torrenting.searchprovider', 'Disabled Default');

        $this->patchJson(route('settings.jackett.update', $jackett), [
            'torznab' => 'http://localhost:9117/torznab/repaired',
            'apiKey' => '',
        ])->assertOk();

        $jackett->refresh();
        $this->assertSame('http://localhost:9117/torznab/repaired', $jackett->torznab);
        $this->assertSame('secret', $jackett->apiKey);
        $this->assertSame(0, (int) $jackett->enabled);
    }

    public function test_deleting_override_restores_native_engine_after_registry_refresh(): void
    {
        $jackett = Jackett::create([
            'name' => 'Nyaa',
            'torznab' => 'http://localhost:9117/torznab/nyaa',
            'enabled' => 1,
            'torznabEnabled' => 1,
            'apiKey' => 'secret',
        ]);

        $before = app(TorrentSearchService::class);
        $this->assertInstanceOf(JackettTorznabEngine::class, $before->getSearchEngine('Nyaa'));

        $this->deleteJson(route('settings.jackett.destroy', $jackett))
            ->assertOk()
            ->assertJson(['success' => true]);

        $after = app(TorrentSearchService::class);
        $this->assertNotSame($before, $after);
        $this->assertInstanceOf(NyaaEngine::class, $after->getSearchEngine('Nyaa'));
    }

    public function test_historical_admin_api_rows_are_preserved_and_not_managed_here(): void
    {
        $jackett = Jackett::create([
            'name' => 'Legacy Admin',
            'torznab' => 'http://localhost:9117/torznab/admin',
            'enabled' => 1,
            'torznabEnabled' => 0,
            'apiKey' => 'legacy-secret',
        ]);

        $this->patchJson(route('settings.jackett.update', $jackett), [
            'enabled' => false,
        ])->assertUnprocessable();

        $this->deleteJson(route('settings.jackett.destroy', $jackett))
            ->assertUnprocessable();

        $this->assertDatabaseHas('jackett', [
            'id' => $jackett->id,
            'name' => 'Legacy Admin',
            'apiKey' => 'legacy-secret',
        ]);
    }
}
