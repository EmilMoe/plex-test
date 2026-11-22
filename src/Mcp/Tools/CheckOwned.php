<?php

namespace EmilMoe\PlexRecommender\Mcp\Tools;

use EmilMoe\PlexRecommender\RecommenderService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;

class CheckOwned extends PlexTool
{
    protected string $name = 'check-owned';

    protected string $description = 'Check a list of titles (optionally "Title (Year)") against the library. Returns whether '
        .'each is in the library and its watch status. Use this to filter your own suggestions.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'titles' => $schema->array()->items($schema->string())->description('e.g. ["Dark (2017)", "The Bear"]')->required(),
        ];
    }

    protected function run(RecommenderService $svc, Request $request): array
    {
        return $svc->checkOwned(array_map('strval', (array) $request->get('titles', [])));
    }
}
