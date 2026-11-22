<?php

namespace EmilMoe\PlexRecommender\Mcp\Tools;

use EmilMoe\PlexRecommender\RecommenderService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;

class SearchLibrary extends PlexTool
{
    protected string $name = 'search-library';

    protected string $description = 'Search the library by title, exact genre, actor or director.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required(),
            'limit' => $schema->integer()->description('Max results (default 20)'),
        ];
    }

    protected function run(RecommenderService $svc, Request $request): array
    {
        return $svc->searchLibrary((string) $request->get('query', ''), (int) $request->get('limit', 20));
    }
}
