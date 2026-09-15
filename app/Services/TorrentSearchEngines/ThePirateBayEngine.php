<?php

namespace App\Services\TorrentSearchEngines;

use App\Services\SettingsService;

/**
 * @see ThePirateBay.js in DuckieTV-angular for original implementation.
 */
class ThePirateBayEngine extends GenericSearchEngine
{
    public function __construct(SettingsService $settings)
    {
        parent::__construct([
            'name' => 'ThePirateBay',
            'mirror' => $settings->get('mirror.ThePirateBay', 'https://thepiratebay.org'),
            'includeBaseURL' => false,
            'endpoints' => [
                'search' => '/search/%s/0/%o/0',
            ],
            'selectors' => [
                'resultContainer' => '#searchResult tbody tr',
                'releasename' => ['td:nth-child(2) > div', 'innerText'],
                'magnetUrl' => ['td:nth-child(2) > a', 'href'],
                'size' => ['td:nth-child(2) .detDesc', 'innerText'], // Preserve full source text so magnitude + original unit remain available
                'seeders' => ['td:nth-child(3)', 'innerHTML'],
                'leechers' => ['td:nth-child(4)', 'innerHTML'],
                'detailUrl' => ['a.detLink', 'href'],
            ],
            'orderby' => [
                'age' => ['d' => '3', 'a' => '4'],
                'leechers' => ['d' => '9', 'a' => '10'],
                'seeders' => ['d' => '7', 'a' => '8'],
                'size' => ['d' => '5', 'a' => '6'],
            ],
        ]);
    }
}
