<?php

namespace EmilMoe\PlexRecommender\Mcp\Tools;

use EmilMoe\PlexRecommender\RecommenderService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;

class UnwatchedInLibrary extends PlexTool
{
    protected string $name = 'unwatched-in-library';

    protected string $description = 'Titles the user OWNS but has not watched, ranked by fit with their taste profile, '
        .'with reasons. Optional genre filter (Plex genre name, e.g. "Thriller").';

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $this->typeSchema($schema)->required(),
            'limit' => $schema->integer()->description('Max results (default 20)'),
            'genre' => $schema->string()->description('Only titles with this genre'),
            'include_in_progress' => $schema->boolean()->description('Also include shows that are started but not finished'),
        ];
    }

    protected function run(RecommenderService $svc, Request $request): array
    {
        return $svc->unwatchedInLibrary(
            $request->get('type', 'movie'),
            (int) $request->get('limit', 20),
            $request->get('genre'),
            (bool) $request->get('include_in_progress', false),
        );
    }
}
