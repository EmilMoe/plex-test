<?php

namespace EmilMoe\PlexRecommender\Mcp\Tools;

use EmilMoe\PlexRecommender\RecommenderService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[IsReadOnly]
abstract class PlexTool extends Tool
{
    abstract protected function run(RecommenderService $svc, Request $request): array|string;

    public function handle(Request $request, RecommenderService $svc): Response
    {
        try {
            $result = $this->run($svc, $request);
        } catch (Throwable $e) {
            return Response::error($e->getMessage());
        }

        return is_string($result) ? Response::text($result) : Response::json($result);
    }

    protected function typeSchema(JsonSchema $schema)
    {
        return $schema->string()->enum(['movie', 'show'])->description("'movie' for films, 'show' for TV series");
    }
}
