<?php

use App\Services\TraktService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

it('does not expose remote response bodies through generic Trakt API errors or logs', function () {
    $sensitive = 'remote-response-secret';
    Log::spy();

    Http::fake([
        'api.trakt.tv/search/show*' => Http::response($sensitive, 500),
    ]);

    try {
        app(TraktService::class)->search('Breaking Bad');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())
            ->toBe('Trakt API request failed for search (HTTP 500)')
            ->not->toContain($sensitive);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context) use ($sensitive) {
                return $message === 'Trakt API server error.'
                    && $context === ['status' => 500, 'endpoint' => 'search']
                    && ! str_contains($message, $sensitive)
                    && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), $sensitive);
            });

        return;
    }

    throw new RuntimeException('Expected Trakt search to fail');
});
