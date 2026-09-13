<?php

$sessionId = 'duckietv-test-session';
$logPath = getenv('TRANSMISSION_EMULATOR_LOG') ?: '';

$body = file_get_contents('php://input');
$payload = json_decode($body ?: '{}', true);
$method = is_array($payload) ? ($payload['method'] ?? '') : '';
$receivedSession = $_SERVER['HTTP_X_TRANSMISSION_SESSION_ID'] ?? '';

if ($logPath !== '') {
    file_put_contents(
        $logPath,
        json_encode([
            'method' => $method,
            'session' => $receivedSession,
        ], JSON_THROW_ON_ERROR)."\n",
        FILE_APPEND
    );
}

header('Content-Type: application/json');

if ($receivedSession !== $sessionId) {
    http_response_code(409);
    header('X-Transmission-Session-Id: '.$sessionId);
    echo json_encode(['result' => 'session-id-required'], JSON_THROW_ON_ERROR);

    return;
}

switch ($method) {
    case 'session-get':
        echo json_encode([
            'result' => 'success',
            'arguments' => ['version' => '4.0.0-test'],
        ], JSON_THROW_ON_ERROR);
        break;

    case 'torrent-get':
        echo json_encode([
            'result' => 'success',
            'arguments' => [
                'torrents' => [[
                    'id' => 7,
                    'name' => 'Runtime Evidence',
                    'hashString' => '00112233445566778899AABBCCDDEEFF00112233',
                    'status' => 4,
                    'error' => 0,
                    'errorString' => '',
                    'eta' => 120,
                    'isFinished' => false,
                    'isStalled' => false,
                    'leftUntilDone' => 500,
                    'metadataPercentComplete' => 1,
                    'percentDone' => 0.5,
                    'sizeWhenDone' => 1000,
                    'files' => [],
                    'rateDownload' => 2048,
                    'rateUpload' => 0,
                    'downloadDir' => '/downloads',
                ]],
            ],
        ], JSON_THROW_ON_ERROR);
        break;

    case 'torrent-add':
        echo json_encode([
            'result' => 'success',
            'arguments' => [
                'torrent-added' => [
                    'hashString' => '00112233445566778899AABBCCDDEEFF00112233',
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        break;

    default:
        http_response_code(400);
        echo json_encode([
            'result' => 'unsupported-method',
            'arguments' => [],
        ], JSON_THROW_ON_ERROR);
}
