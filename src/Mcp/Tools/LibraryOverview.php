<?php

namespace EmilMoe\PlexRecommender\Mcp\Tools;

use EmilMoe\PlexRecommender\RecommenderService;
use Laravel\Mcp\Request;

class LibraryOverview extends PlexTool
{
    protected string $name = 'library-overview';

    protected string $description = 'List Plex libraries and counts of watched / in-progress / unwatched movies and shows.';

    protected function run(RecommenderService $svc, Request $request): array
    {
        return $svc->libraryOverview();
    }
}
