<?php

namespace EmilMoe\PlexRecommender\Mcp\Tools;

use EmilMoe\PlexRecommender\RecommenderService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;

class TasteProfile extends PlexTool
{
    protected string $name = 'taste-profile';

    protected string $description = "The user's taste profile derived from what they watch most: weighted top genres, "
        .'directors, actors, decades and the titles they are most engaged with. Omit type for both movies and shows.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $this->typeSchema($schema),
            'top_n' => $schema->integer()->description('How many entries per list (default 10)'),
        ];
    }

    protected function run(RecommenderService $svc, Request $request): array
    {
        return $svc->tasteProfile($request->get('type'), (int) $request->get('top_n', 10));
    }
}
