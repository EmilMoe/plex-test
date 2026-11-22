<?php

namespace EmilMoe\PlexRecommender\Mcp\Tools;

use EmilMoe\PlexRecommender\RecommenderService;
use Laravel\Mcp\Request;

class RefreshCache extends PlexTool
{
    protected string $name = 'refresh-cache';

    protected string $description = 'Clear the cached library data (use after adding media or watching something).';

    protected function run(RecommenderService $svc, Request $request): string
    {
        $svc->refresh();

        return 'Cache ryddet.';
    }
}
