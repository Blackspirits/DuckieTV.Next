<?php

namespace Tests\Feature\Services;

use App\Models\Jackett;
use App\Services\TorrentSearchEngines\JackettAdminEngine;
use App\Services\TorrentSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JackettAdminRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_historical_api_v1_admin_row_is_registered_and_posts_legacy_search_contract(): void
    {
        Jackett::create([
            'name' => 'Legacy Admin V1',
            'torznab' => 'http://localhost:9117/torznab/legacy',
            'enabled' => 1,
            'torznabEnabled' => 0,
            'apiKey' => 'not-used-by-v1-admin-search',
            'json' => [
                'isJackett' => true,
                'apiVersion' => 1,
                'mirror' => 'http://127.0.0.1:9117/Admin/search?drop=this#fragment',
                'name' => 'Legacy Admin V1',
                'tracker' => 'legacy-tracker',
                'useTorznab' => false,
            ],
        ]);

        Http::fake(['*' => Http::response($this->adminFixture(), 200)]);

        $engine = $this->freshSearchService()->getSearchEngine('Legacy Admin V1');

        $this->assertInstanceOf(JackettAdminEngine::class, $engine);
        $this->assertArrayNotHasKey('apiKey', $engine->getConfig());

        $results = $engine->search('Example Show S01E01', 'seeders.d');

        $this->assertCount(2, $results);
        $this->assertSame('Example.Show.S01E01.1080p', $results[0]['releasename']);
        $this->assertSame(1_500_000_000, $results[0]['sizeBytes']);
        $this->assertSame(42, $results[0]['seeders']);
        $this->assertSame(7, $results[0]['leechers']);
        $this->assertSame(
            '00112233445566778899aabbccddeeff00112233',
            $results[0]['infoHash']
        );

        Http::assertSent(function (Request $request): bool {
            parse_str($request->body(), $body);

            return $request->method() === 'POST'
                && $request->url() === 'http://127.0.0.1:9117/Admin/search'
                && ($body['Query'] ?? null) === 'Example Show S01E01'
                && ($body['Category'] ?? null) === ''
                && ($body['Tracker'] ?? null) === 'legacy-tracker'
                && ! str_contains($request->body(), 'not-used-by-v1-admin-search');
        });
    }

    public function test_historical_api_v2_admin_row_uses_key_and_tracker_without_exposing_key_in_config(): void
    {
        Jackett::create([
            'name' => 'Legacy Admin V2',
            'torznab' => 'http://localhost:9117/api/v2.0/indexers/example/results/torznab/',
            'enabled' => 1,
            'torznabEnabled' => 0,
            'apiKey' => 'v2-secret-key',
            'json' => [
                'isJackett' => true,
                'apiVersion' => 2,
                'apiKey' => 'stale-json-key',
                'mirror' => 'http://localhost:9117/api/v2.0/indexers/all/results',
                'name' => 'Legacy Admin V2',
                'tracker' => 'tracker-id',
                'useTorznab' => false,
            ],
        ]);

        Http::fake(['*' => Http::response($this->adminFixture(), 200)]);

        $engine = $this->freshSearchService()->getSearchEngine('Legacy Admin V2');
        $this->assertArrayNotHasKey('apiKey', $engine->getConfig());

        $engine->search('Example Show');

        Http::assertSent(function (Request $request): bool {
            $parts = parse_url($request->url());
            parse_str($parts['query'] ?? '', $query);

            return $request->method() === 'GET'
                && ($parts['path'] ?? null) === '/api/v2.0/indexers/all/results'
                && ($query['apikey'] ?? null) === 'v2-secret-key'
                && ($query['Query'] ?? null) === 'Example Show'
                && ($query['Tracker'][0] ?? null) === 'tracker-id'
                && ! str_contains($request->url(), 'stale-json-key');
        });
    }

    public function test_api_v2_all_tracker_omits_tracker_filter(): void
    {
        $jackett = Jackett::create([
            'name' => 'All Trackers',
            'enabled' => 1,
            'torznabEnabled' => 0,
            'apiKey' => 'key',
            'json' => [
                'apiVersion' => 2,
                'mirror' => 'http://localhost:9117/api/v2.0/indexers/all/results',
                'tracker' => 'all',
            ],
        ]);

        Http::fake(['*' => Http::response($this->adminFixture(), 200)]);

        (new JackettAdminEngine($jackett))->search('query');

        Http::assertSent(function (Request $request): bool {
            $parts = parse_url($request->url());
            parse_str($parts['query'] ?? '', $query);

            return ! array_key_exists('Tracker', $query);
        });
    }

    public function test_missing_or_invalid_admin_config_is_skipped_without_blocking_native_engines(): void
    {
        Jackett::create([
            'name' => 'Missing Config',
            'enabled' => 1,
            'torznabEnabled' => 0,
            'apiKey' => 'secret',
            'json' => null,
        ]);
        Jackett::create([
            'name' => 'Unsafe Config',
            'enabled' => 1,
            'torznabEnabled' => 0,
            'apiKey' => 'secret',
            'json' => [
                'apiVersion' => 2,
                'mirror' => 'file:///tmp/admin',
                'tracker' => 'tracker',
            ],
        ]);

        $engines = $this->freshSearchService()->getSearchEngines();

        $this->assertArrayNotHasKey('Missing Config', $engines);
        $this->assertArrayNotHasKey('Unsafe Config', $engines);
        $this->assertArrayHasKey('ThePirateBay', $engines);
    }

    public function test_ambiguous_api_version_and_corrupt_protocol_state_are_skipped(): void
    {
        Jackett::create([
            'name' => 'Ambiguous Version',
            'enabled' => 1,
            'torznabEnabled' => 0,
            'apiKey' => 'secret',
            'json' => [
                'apiVersion' => 1.5,
                'mirror' => 'http://localhost:9117/Admin/search',
                'tracker' => 'tracker',
            ],
        ]);
        Jackett::create([
            'name' => 'Corrupt Protocol',
            'enabled' => 1,
            'torznabEnabled' => 2,
            'apiKey' => 'secret',
            'json' => [
                'apiVersion' => 2,
                'mirror' => 'http://localhost:9117/api/v2.0/indexers/all/results',
                'tracker' => 'tracker',
            ],
        ]);

        $engines = $this->freshSearchService()->getSearchEngines();

        $this->assertArrayNotHasKey('Ambiguous Version', $engines);
        $this->assertArrayNotHasKey('Corrupt Protocol', $engines);
    }

    public function test_admin_settings_copy_reports_runtime_supported_but_management_read_only(): void
    {
        Jackett::create([
            'name' => 'Legacy Admin UI',
            'torznab' => 'http://localhost:9117/torznab/legacy',
            'enabled' => 1,
            'torznabEnabled' => 0,
            'apiKey' => 'ui-secret',
            'json' => [
                'apiVersion' => 1,
                'mirror' => 'http://localhost:9117/Admin/search',
                'tracker' => 'legacy',
            ],
        ]);

        $this->get(route('settings.show', 'jackett-search'))
            ->assertOk()
            ->assertSee('Search runtime is supported; editing and testing remain read-only')
            ->assertDontSee('ui-secret');
    }

    public function test_api_v2_redirect_and_transport_failure_do_not_leak_api_key(): void
    {
        $jackett = Jackett::create([
            'name' => 'Private Admin',
            'enabled' => 1,
            'torznabEnabled' => 0,
            'apiKey' => 'never-expose-admin-key',
            'json' => [
                'apiVersion' => 2,
                'mirror' => 'http://localhost:9117/api/v2.0/indexers/all/results',
                'tracker' => 'private',
            ],
        ]);

        $engine = new JackettAdminEngine($jackett);

        Http::fake([
            '*' => Http::response('', 302, ['Location' => 'https://example.invalid/steal']),
        ]);

        try {
            $engine->search('test');
            $this->fail('Expected Admin API redirect to fail closed.');
        } catch (\Exception $e) {
            $this->assertStringNotContainsString('never-expose-admin-key', $e->getMessage());
            $this->assertStringNotContainsString('example.invalid', $e->getMessage());
        }

        Http::assertSentCount(1);

        Http::fake(function (): never {
            throw new \RuntimeException(
                'Connection failed for http://localhost:9117/results?apikey=never-expose-admin-key'
            );
        });

        try {
            $engine->search('test');
            $this->fail('Expected Admin API transport failure.');
        } catch (\Exception $e) {
            $this->assertSame('Jackett Admin API search failed for Private Admin.', $e->getMessage());
            $this->assertStringNotContainsString('never-expose-admin-key', $e->getMessage());
            $this->assertStringNotContainsString('apikey=', $e->getMessage());
        }
    }

    public function test_invalid_json_fails_with_safe_error(): void
    {
        $jackett = Jackett::create([
            'name' => 'Broken Admin',
            'enabled' => 1,
            'torznabEnabled' => 0,
            'apiKey' => 'json-secret',
            'json' => [
                'apiVersion' => 2,
                'mirror' => 'http://localhost:9117/api/v2.0/indexers/all/results',
                'tracker' => 'all',
            ],
        ]);

        Http::fake(['*' => Http::response('{broken', 200)]);

        try {
            (new JackettAdminEngine($jackett))->search('test');
            $this->fail('Expected invalid JSON failure.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('invalid JSON', $e->getMessage());
            $this->assertStringNotContainsString('json-secret', $e->getMessage());
        }
    }

    private function freshSearchService(): TorrentSearchService
    {
        $this->app->forgetInstance(TorrentSearchService::class);

        return $this->app->make(TorrentSearchService::class);
    }

    private function adminFixture(): string
    {
        return <<<'JSON'
{
  "Results": [
    {
      "Title": "Example.Show.S01E01.720p",
      "Size": 750000000,
      "Seeders": 12,
      "Peers": 4,
      "Details": "https://tracker.example/details/720",
      "MagnetUri": null,
      "Link": "https://tracker.example/download/720.torrent",
      "InfoHash": "ABCDEFABCDEFABCDEFABCDEFABCDEFABCDEFABCD"
    },
    {
      "Title": "Example.Show.S01E01.1080p",
      "Size": 1500000000,
      "Seeders": 42,
      "Peers": 7,
      "Details": "https://tracker.example/details/1080",
      "MagnetUri": "magnet:?xt=urn:btih:00112233445566778899AABBCCDDEEFF00112233&dn=Example",
      "Link": null
    }
  ]
}
JSON;
    }
}
