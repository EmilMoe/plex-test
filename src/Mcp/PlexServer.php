<?php

namespace EmilMoe\PlexRecommender\Mcp;

use Laravel\Mcp\Server;

class PlexServer extends Server
{
    protected string $name = 'Plex Recommender';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        Tools for reading the user's Plex library and watch history and recommending movies and
        TV shows they haven't seen. Typical flow: taste-profile -> unwatched-in-library (titles they
        already own) and recommend-new (titles they don't own, via TMDB). When you suggest titles
        from your own knowledge, ALWAYS run check-owned on them first and drop titles that are
        already watched. type is 'movie' or 'show'. The user speaks Danish.
        MARKDOWN;

    protected array $tools = [
        Tools\LibraryOverview::class,
        Tools\TasteProfile::class,
        Tools\MostWatched::class,
        Tools\RecentHistory::class,
        Tools\UnwatchedInLibrary::class,
        Tools\RecommendNew::class,
        Tools\CheckOwned::class,
        Tools\SearchLibrary::class,
        Tools\RefreshCache::class,
    ];
}
