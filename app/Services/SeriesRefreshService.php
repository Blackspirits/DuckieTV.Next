<?php

namespace App\Services;

use App\Models\Serie;

class SeriesRefreshService
{
    public function __construct(
        private readonly FavoritesService $favorites,
        private readonly TraktService $trakt
    ) {}

    public function refresh(Serie $serie, bool $throttle = false): Serie
    {
        if (! $serie->trakt_id) {
            throw new \InvalidArgumentException('Series is missing a Trakt ID.');
        }

        $fetch = fn (): array => $this->trakt->serie((string) $serie->trakt_id);
        $data = $throttle
            ? $this->trakt->withThrottling($fetch)
            : $fetch();

        if (! is_array($data)) {
            throw new \RuntimeException('Trakt returned invalid series data.');
        }

        return $this->favorites->addFavorite($data, [], true);
    }
}
