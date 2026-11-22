<?php

namespace EmilMoe\PlexRecommender\Tests\Feature;

use EmilMoe\PlexRecommender\Mcp\PlexServer;
use EmilMoe\PlexRecommender\Mcp\Tools;
use EmilMoe\PlexRecommender\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Laravel\Mcp\Server\Registrar;

class McpTest extends TestCase
{
    public function test_server_registered_as_local_handle(): void
    {
        $this->assertNotNull(app(Registrar::class)->getLocalServer('plex'));
    }

    public function test_lists_tools(): void
    {
        PlexServer::tools()->assertRegistered([
            Tools\TasteProfile::class, Tools\UnwatchedInLibrary::class,
            Tools\RecommendNew::class, Tools\CheckOwned::class,
        ]);
    }

    public function test_unwatched_tool(): void
    {
        PlexServer::tool(Tools\UnwatchedInLibrary::class, ['type' => 'movie', 'limit' => 2])
            ->assertOk()
            ->assertSee('Dune')
            ->assertSee('Denis Villeneuve');
    }

    public function test_check_owned_tool(): void
    {
        PlexServer::tool(Tools\CheckOwned::class, ['titles' => ['Dark (2017)', 'Ex Machina']])
            ->assertOk()
            ->assertSee('"in_library":true')
            ->assertSee('"in_library":false');
    }

    public function test_recommend_new_tool(): void
    {
        PlexServer::tool(Tools\RecommendNew::class, ['type' => 'movie'])->assertOk()->assertSee('Ex Machina');
    }

    public function test_install_command_writes_mcp_json_and_claude_commands(): void
    {
        $base = $this->app->basePath();
        File::delete([$base.'/.mcp.json']);
        File::deleteDirectory($base.'/.claude');
        File::put($base.'/.mcp.json', json_encode(['mcpServers' => ['other' => ['command' => 'x']]]));

        $this->artisan('plex:install')->assertSuccessful();

        $mcp = json_decode(File::get($base.'/.mcp.json'), true);
        $this->assertSame(['artisan', 'mcp:start', 'plex'], $mcp['mcpServers']['plex']['args']);
        $this->assertArrayHasKey('other', $mcp['mcpServers']);
        $this->assertStringContainsString('`plex` MCP', File::get($base.'/.claude/commands/anbefal.md'));
        $this->assertFileExists(config_path('plex-recommender.php'));

        File::delete([$base.'/.mcp.json', config_path('plex-recommender.php')]);
        File::deleteDirectory($base.'/.claude');
    }
}
