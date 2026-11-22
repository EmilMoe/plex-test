<?php

namespace EmilMoe\PlexRecommender\Mcp\Tools;

use EmilMoe\PlexRecommender\RecommenderService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;

class RecentHistory extends PlexTool
{
    protected string $name = 'recent-history';

    protected string $description = 'The most recent plays from Plex watch history, newest first.';

    public function schema(JsonSchema $schema): array
    {
        return ['limit' => $schema->integer()->description('Max entries (default 30)')];
    }

    protected function run(RecommenderService $svc, Request $request): array
    {
        return $svc->recentHistory((int) $request->get('limit', 30));
    }
}
