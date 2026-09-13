<?php

namespace Tests\Feature\Services;

use App\Services\TorrentSearchEngines\GenericSearchEngine;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GenericSearchEngineTest extends TestCase
{
    protected array $mockConfig = [
        'name' => 'MockEngine',
        'mirror' => 'https://mock.engine',
        'includeBaseURL' => true,
        'endpoints' => [
            'search' => '/search/%s',
        ],
        'selectors' => [
            'resultContainer' => '.result',
            'releasename' => ['.title', 'innerText'],
            'magnetUrl' => ['.magnet', 'href'],
            'torrentUrl' => ['.torrent', 'href'],
            'size' => ['.size', 'innerText'],
            'seeders' => ['.seeders', 'innerText'],
            'leechers' => ['.leechers', 'innerText'],
            'detailUrl' => ['.title', 'href'],
        ],
    ];

    public function test_it_can_parse_search_results()
    {
        $html = '
            <div class="result">
                <a class="title" href="/details/1">Release 1</a>
                <a class="magnet" href="magnet:?xt=urn:btih:0123456789ABCDEF0123456789ABCDEF01234567">Magnet</a>
                <span class="size">1.5 GB</span>
                <span class="seeders">100</span>
                <span class="leechers">50</span>
            </div>
            <div class="result">
                <a class="title" href="/details/2">Release 2</a>
                <a class="magnet" href="magnet:?xt=urn:btih:aaisem2ekvthpcezvk54zxpo74abcirt">Magnet</a>
                <span class="size">800 MB</span>
                <span class="seeders">200</span>
                <span class="leechers">10</span>
            </div>
        ';

        Http::fake([
            'https://mock.engine/search/test_query' => Http::response($html, 200),
        ]);

        $engine = new GenericSearchEngine($this->mockConfig);
        $results = $engine->search('test_query');

        $this->assertCount(2, $results);

        $this->assertEquals('Release 1', $results[0]['releasename']);
        $this->assertSame(1_500_000_000, $results[0]['sizeBytes']);
        $this->assertFalse($results[0]['sizeParseError']);
        $this->assertSame('1.5 GB', $results[0]['size']);
        $this->assertEquals(100, $results[0]['seeders']);
        $this->assertEquals(50, $results[0]['leechers']);
        $this->assertEquals('magnet:?xt=urn:btih:0123456789ABCDEF0123456789ABCDEF01234567', $results[0]['magnetUrl']);
        $this->assertSame('0123456789abcdef0123456789abcdef01234567', $results[0]['infoHash']);
        $this->assertEquals('https://mock.engine/details/1', $results[0]['detailUrl']);

        $this->assertEquals('Release 2', $results[1]['releasename']);
        $this->assertSame(800_000_000, $results[1]['sizeBytes']);
        $this->assertFalse($results[1]['sizeParseError']);
        $this->assertSame('800 MB', $results[1]['size']);
        $this->assertSame('00112233445566778899aabbccddeeff00112233', $results[1]['infoHash']);
    }

    public function test_it_rejects_executable_or_untrusted_links_from_remote_search_html()
    {
        $validHash = '0123456789ABCDEF0123456789ABCDEF01234567';
        $html = <<<HTML
            <div class="result">
                <a class="title" href="javascript:alert(1)">Malicious release</a>
                <a class="magnet" href="javascript:?xt=urn:btih:{$validHash}">Fake magnet</a>
                <a class="torrent" href="file:///etc/passwd">Fake torrent</a>
                <span class="size">1 GB</span>
                <span class="seeders">10</span>
                <span class="leechers">1</span>
            </div>
        HTML;

        Http::fake([
            'https://mock.engine/search/hostile' => Http::response($html, 200),
        ]);

        $engine = new GenericSearchEngine($this->mockConfig);
        $result = $engine->search('hostile')[0];

        $this->assertNull($result['detailUrl']);
        $this->assertTrue($result['noMagnet']);
        $this->assertTrue($result['noTorrent']);
        $this->assertArrayNotHasKey('magnetUrl', $result);
        $this->assertArrayNotHasKey('torrentUrl', $result);
        $this->assertArrayNotHasKey('infoHash', $result);
    }

    public function test_it_rejects_private_or_credentialed_torrent_urls_but_allows_public_https_urls()
    {
        $html = <<<'HTML'
            <div class="result">
                <a class="title" href="/details/1">Private target</a>
                <a class="torrent" href="http://127.0.0.1/private.torrent">Torrent</a>
                <span class="size">1 GB</span>
                <span class="seeders">10</span>
                <span class="leechers">1</span>
            </div>
            <div class="result">
                <a class="title" href="/details/2">Credentialed target</a>
                <a class="torrent" href="https://user:pass@cdn.example/file.torrent">Torrent</a>
                <span class="size">2 GB</span>
                <span class="seeders">20</span>
                <span class="leechers">2</span>
            </div>
            <div class="result">
                <a class="title" href="/details/3">Public target</a>
                <a class="torrent" href="https://cdn.example/file.torrent">Torrent</a>
                <span class="size">3 GB</span>
                <span class="seeders">30</span>
                <span class="leechers">3</span>
            </div>
        HTML;

        Http::fake([
            'https://mock.engine/search/urls' => Http::response($html, 200),
        ]);

        $engine = new GenericSearchEngine($this->mockConfig);
        $results = $engine->search('urls');

        $this->assertTrue($results[0]['noTorrent']);
        $this->assertArrayNotHasKey('torrentUrl', $results[0]);

        $this->assertTrue($results[1]['noTorrent']);
        $this->assertArrayNotHasKey('torrentUrl', $results[1]);

        $this->assertFalse($results[2]['noTorrent']);
        $this->assertSame('https://cdn.example/file.torrent', $results[2]['torrentUrl']);
        $this->assertSame('https://mock.engine/details/3', $results[2]['detailUrl']);
    }
}
