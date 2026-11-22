<?php

namespace EmilMoe\PlexRecommender\Mcp\Tools;

use EmilMoe\PlexRecommender\RecommenderService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;

class MostWatched extends PlexTool
{
    protected string $name = 'most-watched';

    protected string $description = 'Most watched titles. Without days: by overall engagement (rewatches, episodes watched, '
        .'own rating, recency). With days: by number of plays in the last N days.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $this->typeSchema($schema)->required(),
            'limit' => $schema->integer()->description('Max results (default 20)'),
            'days' => $schema->integer()->description('Only count plays in the last N days'),
        ];
    }

    protected function run(RecommenderService $svc, Request $request): array
    {
        $days = $request->get('days');

        return $svc->mostWatched($request->get('type', 'movie'), (int) $request->get('limit', 20), $days ? (int) $days : null);
    }
}
