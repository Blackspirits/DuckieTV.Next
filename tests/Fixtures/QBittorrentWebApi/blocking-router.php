<?php

$logPath = getenv('QBITTORRENT_BLOCKING_LOG');

if (is_string($logPath) && $logPath !== '') {
    file_put_contents(
        $logPath,
        json_encode([
            'path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH),
            'host' => $_SERVER['HTTP_HOST'] ?? '',
            'origin' => $_SERVER['HTTP_ORIGIN'] ?? '',
            'referer' => $_SERVER['HTTP_REFERER'] ?? '',
        ], JSON_THROW_ON_ERROR).PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

// Keep the queue worker inside real application I/O long enough for the
// parent test process to observe the reservation and send SIGKILL.
usleep(20_000_000);

http_response_code(503);
echo 'blocked';
