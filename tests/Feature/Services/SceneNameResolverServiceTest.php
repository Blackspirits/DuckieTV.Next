<?php

use App\Models\Episode;
use App\Models\Serie;
use App\Services\SceneNameResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('appends the persisted camelCase custom search string like the original DuckieTV model', function () {
    $serie = Serie::create([
        'name' => 'Example Show',
        'trakt_id' => 123,
        'customSearchString' => '1080p HEVC',
    ])->fresh();

    $episode = new Episode([
        'seasonnumber' => 1,
        'episodenumber' => 2,
    ]);

    $result = app(SceneNameResolverService::class)
        ->getSearchStringForEpisode($serie, $episode);

    expect($serie->customSearchString)->toBe('1080p HEVC')
        ->and($result)->toBe('Example Show s01e02 1080p HEVC');
});
