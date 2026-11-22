<?php

namespace EmilMoe\PlexRecommender\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Publishes config and sets up Claude Code: .mcp.json entry + /anbefal and /smagsprofil commands.
 */
class InstallCommand extends Command
{
    protected $signature = 'plex:install {--force : Overskriv eksisterende Claude-kommandoer}';

    protected $description = 'Publicér config og opsæt Claude Code-integration (.mcp.json + slash-kommandoer)';

    public function handle(Filesystem $files): int
    {
        $this->call('vendor:publish', ['--tag' => 'plex-recommender-config']);

        // .mcp.json – merge, don't clobber other servers
        $mcpPath = base_path('.mcp.json');
        $mcp = $files->exists($mcpPath) ? (json_decode($files->get($mcpPath), true) ?: []) : [];
        $handle = config('plex-recommender.mcp_handle', 'plex') ?: 'plex';
        $mcp['mcpServers'][$handle] = [
            'type' => 'stdio',
            'command' => 'php',
            'args' => ['artisan', 'mcp:start', $handle],
        ];
        $files->put($mcpPath, json_encode($mcp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $this->components->info("MCP-serveren '{$handle}' er tilføjet til .mcp.json");

        // Claude Code slash commands
        $target = base_path('.claude/commands');
        $files->ensureDirectoryExists($target);
        foreach ($files->files(__DIR__.'/../../stubs/claude/commands') as $stub) {
            $dest = $target.'/'.$stub->getFilename();
            if ($files->exists($dest) && ! $this->option('force')) {
                $this->components->warn("Springer over {$stub->getFilename()} (findes allerede, brug --force)");

                continue;
            }
            $files->put($dest, str_replace('{{handle}}', $handle, $files->get($stub->getPathname())));
            $this->components->info('Oprettede .claude/commands/'.$stub->getFilename());
        }

        $this->newLine();
        $this->line('Næste skridt:');
        $this->line('  1. Forbind Plex: gå til '.url(config('plex-recommender.routes.prefix', 'plex'))
            .' og tryk "Log ind med Plex", eller kør: php artisan plex:login');
        $this->line('  2. (Valgfrit) Sæt TMDB_API_KEY i .env for forslag til nye titler');
        $this->line('  3. Åbn projektet i Claude Code og kør /anbefal');

        return self::SUCCESS;
    }
}
