<?php

$logPath = getenv('QBITTORRENT_EMULATOR_LOG');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = file_get_contents('php://input') ?: '';
$host = $_SERVER['HTTP_HOST'] ?? '';
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$cookie = $_SERVER['HTTP_COOKIE'] ?? '';

if (is_string($logPath) && $logPath !== '') {
    file_put_contents($logPath, json_encode([
        'method' => $method,
        'path' => $path,
        'origin' => $origin,
        'referer' => $referer,
        'cookie' => $cookie,
        'body' => $body,
    ], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND);
}

$expectedOrigin = 'http://'.$host;

if ($path === '/api/v2/auth/login') {
    if ($origin !== $expectedOrigin && $referer !== $expectedOrigin) {
        http_response_code(403);
        echo 'Forbidden: qBittorrent WebUI requires matching Origin or Referer.';

        return;
    }

    parse_str($body, $form);
    if (($form['username'] ?? null) !== 'duckie' || ($form['password'] ?? null) !== 'secret') {
        http_response_code(403);
        echo 'Fails.';

        return;
    }

    header('Set-Cookie: SID=runtime-sid; path=/; HttpOnly');
    header('Content-Type: text/plain');
    echo 'Ok.';

    return;
}

if (! str_contains($cookie, 'SID=runtime-sid')) {
    http_response_code(403);
    echo 'Forbidden';

    return;
}

if ($path === '/api/v2/torrents/info') {
    header('Content-Type: application/json');
    echo json_encode([[
        'hash' => '00112233445566778899aabbccddeeff00112233',
        'name' => 'qBittorrent Runtime Evidence',
        'progress' => 0.5,
        'dlspeed' => 321,
        'state' => 'downloading',
    ]], JSON_THROW_ON_ERROR);

    return;
}

if ($path === '/api/v2/torrents/add') {
    header('Content-Type: text/plain');
    echo 'Ok.';

    return;
}

http_response_code(404);
echo 'Not Found';
