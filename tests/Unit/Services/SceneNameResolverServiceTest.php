<?php

use App\Models\Episode;
use App\Models\Serie;
use App\Services\SceneNameResolverService;

it('appends the persisted camelCase custom search string', function () {
    $serie = new Serie([
        'name' => 'Test Show',
        'trakt_id' => 123,
        'customSearchString' => '1080p HEVC',
    ]);

    $episode = new Episode([
        'seasonnumber' => 1,
        'episodenumber' => 2,
    ]);

    $result = app(SceneNameResolverService::class)
        ->getSearchStringForEpisode($serie, $episode);

    expect($result)->toBe('Test Show s01e02 1080p HEVC');
});
