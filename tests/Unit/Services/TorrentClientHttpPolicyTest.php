<?php

it('routes torrent client HTTP calls through the bounded base request policy', function () {
    $root = dirname(__DIR__, 3);
    $base = file_get_contents($root.'/app/Services/TorrentClients/BaseTorrentClient.php');

    expect($base)
        ->toContain('CONNECT_TIMEOUT_SECONDS = 3')
        ->toContain('REQUEST_TIMEOUT_SECONDS = 8')
        ->toContain('Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)')
        ->toContain('->timeout(self::REQUEST_TIMEOUT_SECONDS)');

    $drivers = [
        'Aria2Client.php',
        'DelugeClient.php',
        'KTorrentClient.php',
        'QBittorrentClient.php',
        'RTorrentClient.php',
        'TixatiClient.php',
        'TransmissionClient.php',
        'TTorrentClient.php',
        'UTorrentClient.php',
        'UTorrentWebUIClient.php',
    ];

    foreach ($drivers as $driver) {
        $source = file_get_contents($root.'/app/Services/TorrentClients/'.$driver);
        expect($source, $driver)->not->toContain('Http::');
    }
});
