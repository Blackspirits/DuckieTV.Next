<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('does not expose Trakt response details when adding a favorite fails', function () {
    Http::fake([
        'api.trakt.tv/*' => Http::response('remote-response-secret', 500),
    ]);

    $response = $this->from(route('search.index'))->post(route('search.add'), [
        'trakt_id' => '1388',
    ]);

    $response->assertRedirect(route('search.index'))
        ->assertSessionHas('error', 'Failed to add show. Please try again.');

    expect((string) session('error'))->not->toContain('remote-response-secret');
});
