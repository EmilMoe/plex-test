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

        $this->registerFilamentPlugin($files);

        $this->newLine();
        $this->line('Næste skridt:');
        $this->line('  1. Åbn dit Filament-panel, vælg Plex > Indstillinger, og tryk "Log ind med Plex"');
        $this->line('     (uden Filament: php artisan plex:login)');
        $this->line('  2. Tilføj evt. en gratis TMDB-nøgle samme sted for forslag til nye titler');
        $this->line('  3. Åbn projektet i Claude Code og kør /anbefal');

        return self::SUCCESS;
    }

    /** Add ->plugin(PlexRecommenderPlugin::make()) to the app's Filament panel provider(s). */
    protected function registerFilamentPlugin(Filesystem $files): void
    {
        if (! class_exists(\Filament\Panel::class)) {
            return;
        }
        $providers = $files->glob(app_path('Providers/Filament/*PanelProvider.php'));
        if (! $providers) {
            $this->components->warn('Fandt intet Filament-panel. Opret et med: php artisan filament:install --panels');

            return;
        }

        foreach ($providers as $path) {
            $code = $files->get($path);
            $name = basename($path);
            if (str_contains($code, 'PlexRecommenderPlugin')) {
                $this->components->info("Plex-pluginnet er allerede i {$name}");

                continue;
            }
            $updated = preg_replace(
                '/(->id\(\s*[\'"][^\'"]+[\'"]\s*\))/',
                "$1\n            ->plugin(\\EmilMoe\\PlexRecommender\\Filament\\PlexRecommenderPlugin::make())",
                $code,
                1,
            );
            if ($updated === null || $updated === $code) {
                $this->components->warn("Kunne ikke tilføje pluginnet automatisk i {$name}. Tilføj selv: ->plugin(PlexRecommenderPlugin::make())");

                continue;
            }
            $files->put($path, $updated);
            $this->components->info("Plex-pluginnet er tilføjet til {$name}");
        }

        if ($this->getApplication()->has('filament:assets')) {
            $this->callSilently('filament:assets');
        }
    }
}
