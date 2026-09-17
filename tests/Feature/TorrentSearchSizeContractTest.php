<?php

namespace Tests\Feature;

use App\Services\SettingsService;
use App\Services\TorrentSearchEngines\OneThreeThreeSevenXEngine;
use App\Services\TorrentSearchEngines\ShowRSSEngine;
use App\Services\TorrentSearchEngines\ThePirateBayEngine;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class TorrentSearchSizeContractTest extends TestCase
{
    public function test_the_pirate_bay_preserves_iec_unit_from_source_shape(): void
    {
        Http::fake(['*' => Http::response($this->fixture('the-pirate-bay.html'), 200)]);

        $engine = new ThePirateBayEngine($this->settings([
            'mirror.ThePirateBay' => 'https://thepiratebay.org',
        ]));

        $results = $engine->search('Example Show S01E01');

        $this->assertCount(1, $results);
        $this->assertSame(1_610_612_736, $results[0]['sizeBytes']);
        $this->assertFalse($results[0]['sizeParseError']);
        $this->assertSame('1.61 GB', $results[0]['size']);
    }

    public function test_1337x_handles_nbsp_and_marks_malformed_known_size(): void
    {
        Http::fake(['*' => Http::response($this->fixture('1337x.html'), 200)]);

        $engine = new OneThreeThreeSevenXEngine($this->settings([
            'mirror.1337x' => 'https://1337x.to',
        ]));

        $results = $engine->search('Example Show S01E01');

        $this->assertCount(2, $results);
        $this->assertSame(1_500_000_000, $results[0]['sizeBytes']);
        $this->assertFalse($results[0]['sizeParseError']);
        $this->assertSame('1.5 GB', $results[0]['size']);
        $this->assertNull($results[1]['sizeBytes']);
        $this->assertTrue($results[1]['sizeParseError']);
        $this->assertSame('n/a', $results[1]['size']);
    }

    public function test_showrss_declared_unknown_size_stays_unknown_without_parse_error(): void
    {
        Http::fake([
            'https://showrss.info/browse' => Http::response($this->fixture('showrss-browse.html'), 200),
            'https://showrss.info/browse/42' => Http::response($this->fixture('showrss-series.html'), 200),
        ]);

        $engine = new ShowRSSEngine($this->settings([
            'mirror.ShowRSS' => 'https://showrss.info',
        ]));

        $results = $engine->search('Example Show S01E01');

        $this->assertCount(1, $results);
        $this->assertNull($results[0]['sizeBytes']);
        $this->assertFalse($results[0]['sizeParseError']);
        $this->assertSame('n/a', $results[0]['size']);
        $this->assertSame(str_repeat('b', 40), $results[0]['infoHash']);
    }

    public function test_results_view_derives_presentation_from_size_bytes_not_legacy_size(): void
    {
        $html = view('torrents.results', [
            'results' => [[
                'releasename' => 'Example.Show.S01E01',
                'sizeBytes' => 1_500_000_000,
                'sizeParseError' => false,
                'size' => 'BROKEN LEGACY SIZE',
                'seeders' => 10,
                'leechers' => 1,
                'noMagnet' => true,
                'noTorrent' => true,
            ]],
        ])->render();

        $this->assertStringContainsString('1.5 GB', $html);
        $this->assertStringNotContainsString('BROKEN LEGACY SIZE', $html);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function settings(array $values): SettingsService
    {
        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('get')->andReturnUsing(
            fn (string $key, mixed $default = null) => array_key_exists($key, $values) ? $values[$key] : $default
        );

        return $settings;
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(base_path('tests/Fixtures/TorrentSearch/'.$name));
        if ($contents === false) {
            $this->fail("Unable to read torrent-search fixture: {$name}");
        }

        return $contents;
    }
}
