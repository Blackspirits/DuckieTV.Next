<?php

namespace Tests\Unit\Rules;

use App\Rules\ValidTorrentClientServer;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ValidTorrentClientServerTest extends TestCase
{
    public function test_accepts_http_and_https_origins_only(): void
    {
        foreach ([
            'http://localhost',
            'http://localhost/',
            'https://127.0.0.1',
            'https://torrent.example',
            'http://[::1]',
        ] as $server) {
            $validator = Validator::make(
                ['server' => $server],
                ['server' => ['required', 'string', new ValidTorrentClientServer]]
            );

            $this->assertFalse($validator->fails(), $server);
        }
    }

    public function test_rejects_transport_data_that_belongs_in_separate_settings(): void
    {
        foreach ([
            'ftp://torrent.example',
            'file:///tmp/torrent.sock',
            'torrent.example',
            'http://user:pass@torrent.example',
            'http://torrent.example:8080',
            'http://torrent.example/path',
            'http://torrent.example?query=1',
            'http://torrent.example#fragment',
            ' http://torrent.example',
            "http://torrent.example\n",
        ] as $server) {
            $validator = Validator::make(
                ['server' => $server],
                ['server' => ['required', 'string', new ValidTorrentClientServer]]
            );

            $this->assertTrue($validator->fails(), $server);
        }
    }
}
