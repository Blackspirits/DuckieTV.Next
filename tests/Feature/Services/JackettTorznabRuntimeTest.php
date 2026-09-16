<?php

namespace Tests\Feature\Services;

use App\Models\Jackett;
use App\Services\TorrentSearchEngines\JackettTorznabEngine;
use App\Services\TorrentSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JackettTorznabRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabled_torznab_row_is_registered_and_searches_without_exposing_api_key(): void
    {
        Jackett::create([
            'name' => 'Local Indexer',
            'torznab' => 'http://127.0.0.1:9117/api/v2.0/indexers/local/results/torznab/',
            'enabled' => 1,
            'torznabEnabled' => 1,
            'apiKey' => 'super-secret-key',
        ]);

        Http::fake([
            '*' => Http::response($this->torznabFixture(), 200, [
                'Content-Type' => 'application/rss+xml',
            ]),
        ]);

        $service = $this->freshSearchService();
        $engine = $service->getSearchEngine('Local Indexer');

        $this->assertInstanceOf(JackettTorznabEngine::class, $engine);
        $this->assertArrayNotHasKey('apiKey', $engine->getConfig());

        $results = $engine->search('Example Show S01E01', 'seeders.d');

        $this->assertCount(2, $results);
        $this->assertSame('Local Indexer', $results[0]['engine']);
        $this->assertSame('Example.Show.S01E01.1080p', $results[0]['releasename']);
        $this->assertSame(1_500_000_000, $results[0]['sizeBytes']);
        $this->assertFalse($results[0]['sizeParseError']);
        $this->assertSame(42, $results[0]['seeders']);
        $this->assertSame(7, $results[0]['leechers']);
        $this->assertSame(
            '00112233445566778899aabbccddeeff00112233',
            $results[0]['infoHash']
        );
        $this->assertStringStartsWith('magnet:?xt=urn:btih:', $results[0]['magnetUrl']);

        Http::assertSent(function (Request $request): bool {
            $parts = parse_url($request->url());
            parse_str($parts['query'] ?? '', $query);

            return ($parts['scheme'] ?? null) === 'http'
                && ($parts['host'] ?? null) === '127.0.0.1'
                && ($parts['port'] ?? null) === 9117
                && ($parts['path'] ?? null) === '/api/v2.0/indexers/local/results/torznab'
                && ($query['t'] ?? null) === 'search'
                && ($query['apikey'] ?? null) === 'super-secret-key'
                && ($query['q'] ?? null) === 'Example Show S01E01';
        });
    }

    public function test_legacy_torznab_root_gets_api_suffix(): void
    {
        Jackett::create([
            'name' => 'Legacy Indexer',
            'torznab' => 'http://localhost:9117/torznab/legacy',
            'enabled' => 1,
            'torznabEnabled' => 1,
            'apiKey' => 'legacy-key',
        ]);

        Http::fake(['*' => Http::response($this->torznabFixture(), 200)]);

        $engine = $this->freshSearchService()->getSearchEngine('Legacy Indexer');
        $engine->search('Legacy Query');

        Http::assertSent(
            fn (Request $request): bool => str_starts_with(
                $request->url(),
                'http://localhost:9117/torznab/legacy/api?'
            )
        );
    }

    public function test_disabled_non_torznab_and_invalid_rows_do_not_enter_active_registry(): void
    {
        Jackett::create([
            'name' => 'Disabled',
            'torznab' => 'http://localhost:9117/api/v2.0/indexers/disabled/results/torznab/',
            'enabled' => 0,
            'torznabEnabled' => 1,
            'apiKey' => 'key',
        ]);
        Jackett::create([
            'name' => 'Admin Only',
            'torznab' => 'http://localhost:9117/api/v2.0/indexers/admin/results/torznab/',
            'enabled' => 1,
            'torznabEnabled' => 0,
            'apiKey' => 'key',
        ]);
        Jackett::create([
            'name' => 'Invalid',
            'torznab' => 'file:///tmp/not-http',
            'enabled' => 1,
            'torznabEnabled' => 1,
            'apiKey' => 'key',
        ]);

        $engines = $this->freshSearchService()->getSearchEngines();

        $this->assertArrayNotHasKey('Disabled', $engines);
        $this->assertArrayNotHasKey('Admin Only', $engines);
        $this->assertArrayNotHasKey('Invalid', $engines);
        $this->assertArrayHasKey('ThePirateBay', $engines);
    }

    public function test_enabled_jackett_engine_overrides_native_engine_with_same_name(): void
    {
        Jackett::create([
            'name' => 'Nyaa',
            'torznab' => 'http://localhost:9117/api/v2.0/indexers/nyaa/results/torznab/',
            'enabled' => 1,
            'torznabEnabled' => 1,
            'apiKey' => 'key',
        ]);

        $engine = $this->freshSearchService()->getSearchEngine('Nyaa');

        $this->assertInstanceOf(JackettTorznabEngine::class, $engine);
    }

    public function test_remote_failure_and_redirect_do_not_leak_api_key(): void
    {
        $jackett = Jackett::create([
            'name' => 'Private Indexer',
            'torznab' => 'http://localhost:9117/api/v2.0/indexers/private/results/torznab/',
            'enabled' => 1,
            'torznabEnabled' => 1,
            'apiKey' => 'never-expose-this-key',
        ]);

        $engine = new JackettTorznabEngine($jackett);

        Http::fake([
            '*' => Http::response('', 302, ['Location' => 'https://example.invalid/steal']),
        ]);

        try {
            $engine->search('test');
            $this->fail('Expected Torznab redirect to fail closed.');
        } catch (\Exception $e) {
            $this->assertStringNotContainsString('never-expose-this-key', $e->getMessage());
            $this->assertStringNotContainsString('example.invalid', $e->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_invalid_xml_fails_without_leaking_endpoint_credentials(): void
    {
        $jackett = Jackett::create([
            'name' => 'Broken XML',
            'torznab' => 'http://localhost:9117/api/v2.0/indexers/broken/results/torznab/',
            'enabled' => 1,
            'torznabEnabled' => 1,
            'apiKey' => 'xml-secret',
        ]);

        Http::fake(['*' => Http::response('<rss><broken>', 200)]);

        try {
            (new JackettTorznabEngine($jackett))->search('test');
            $this->fail('Expected invalid Torznab XML to fail.');
        } catch (\Exception $e) {
            $this->assertStringNotContainsString('xml-secret', $e->getMessage());
            $this->assertStringContainsString('invalid XML', $e->getMessage());
        }
    }

    private function freshSearchService(): TorrentSearchService
    {
        $this->app->forgetInstance(TorrentSearchService::class);

        return $this->app->make(TorrentSearchService::class);
    }

    private function torznabFixture(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:torznab="http://torznab.com/schemas/2015/feed">
  <channel>
    <item>
      <title>Example.Show.S01E01.720p</title>
      <size>750000000</size>
      <comments>https://tracker.example/details/720</comments>
      <link>https://127.0.0.1:9117/dl/720.torrent</link>
      <torznab:attr name="seeders" value="12"/>
      <torznab:attr name="peers" value="4"/>
      <torznab:attr name="infohash" value="abcdefabcdefabcdefabcdefabcdefabcdefabcd"/>
    </item>
    <item>
      <title>Example.Show.S01E01.1080p</title>
      <size>1500000000</size>
      <comments>https://tracker.example/details/1080</comments>
      <link>magnet:?xt=urn:btih:00112233445566778899AABBCCDDEEFF00112233&amp;dn=Example</link>
      <torznab:attr name="seeders" value="42"/>
      <torznab:attr name="peers" value="7"/>
    </item>
  </channel>
</rss>
XML;
    }
}
