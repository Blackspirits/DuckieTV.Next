<?php

namespace Tests\Feature\Services;

use App\Services\SubtitlesService;
use Mockery;
use Tests\TestCase;

class SubtitlesLanguageFilterTest extends TestCase
{
    public function test_empty_language_selection_uses_historical_all_filter(): void
    {
        $service = Mockery::mock(SubtitlesService::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $service->shouldReceive('xmlRpcCall')
            ->once()
            ->with('LogIn', ['', '', 'en', 'DuckieTV v1.00'])
            ->andReturn(['token' => 'token']);

        $service->shouldReceive('xmlRpcCall')
            ->once()
            ->with('SearchSubtitles', Mockery::on(function (array $params): bool {
                return ($params[0] ?? null) === 'token'
                    && ($params[1][0]['query'] ?? null) === 'DuckieTV'
                    && ($params[1][0]['sublanguageid'] ?? null) === 'all';
            }))
            ->andReturn(['data' => []]);

        $this->assertSame([], $service->searchByQuery('DuckieTV', []));
    }

    public function test_selected_languages_are_forwarded_as_historical_comma_separated_codes(): void
    {
        $service = Mockery::mock(SubtitlesService::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $service->shouldReceive('xmlRpcCall')
            ->once()
            ->with('LogIn', ['', '', 'en', 'DuckieTV v1.00'])
            ->andReturn(['token' => 'token']);

        $service->shouldReceive('xmlRpcCall')
            ->once()
            ->with('SearchSubtitles', Mockery::on(function (array $params): bool {
                return ($params[1][0]['sublanguageid'] ?? null) === 'por,eng';
            }))
            ->andReturn(['data' => []]);

        $this->assertSame([], $service->searchByQuery('DuckieTV', ['por', 'eng']));
    }
}
