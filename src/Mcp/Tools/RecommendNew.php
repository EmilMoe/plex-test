<?php

namespace EmilMoe\PlexRecommender\Mcp\Tools;

use EmilMoe\PlexRecommender\RecommenderService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;

class RecommendNew extends PlexTool
{
    protected string $name = 'recommend-new';

    protected string $description = 'Titles NOT in the library, found via TMDB recommendations for the user\'s most watched '
        .'titles and re-ranked by their genre profile and rating. Requires TMDB_API_KEY.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $this->typeSchema($schema)->required(),
            'limit' => $schema->integer()->description('Max results (default 20)'),
            'seeds' => $schema->integer()->description('How many top-watched titles to base it on (default 15)'),
            'min_votes' => $schema->integer()->description('Minimum TMDB vote count (default 50)'),
        ];
    }

    protected function run(RecommenderService $svc, Request $request): array
    {
        return $svc->recommendNew(
            $request->get('type', 'movie'),
            (int) $request->get('limit', 20),
            (int) $request->get('seeds', 15),
            (int) $request->get('min_votes', 50),
        );
    }
}
