<?php

it('uses separate short and long queue connections in composer development mode', function () {
    $composer = json_decode(
        file_get_contents(base_path('composer.json')),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    $commands = $composer['scripts']['dev'] ?? [];
    $command = implode("\n", is_array($commands) ? $commands : [$commands]);

    expect($command)
        ->toContain('queue:listen database --queue=autodownload')
        ->toContain('queue:listen database_long --queue=default')
        ->toContain('--tries=1')
        ->toContain('--timeout=0');
});
