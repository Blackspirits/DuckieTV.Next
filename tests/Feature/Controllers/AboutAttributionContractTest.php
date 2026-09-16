<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the required TMDB attribution in the about credits', function () {
    $response = $this->get(route('about.index'), [
        'X-Requested-With' => 'XMLHttpRequest',
    ]);

    $response->assertOk()
        ->assertSee('https://www.themoviedb.org')
        ->assertSee('blue_short-8e7b30f73a4020692ccca9c88bafe5dcb6f8a62a4c6bc55cd9ba82bb2cd95f6c.svg')
        ->assertSeeText('This product uses the TMDB API but is not endorsed or certified by TMDB.');
});
