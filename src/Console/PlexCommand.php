<?php

namespace EmilMoe\PlexRecommender\Console;

use EmilMoe\PlexRecommender\RecommenderService;
use Illuminate\Console\Command;

/**
 * php artisan plex {action} ...  – recommendations from the terminal.
 */
class PlexCommand extends Command
{
    protected $signature = 'plex
        {action : overview | profile | most-watched | history | unwatched | recommend | claude | check | refresh}
        {args?* : type (movie/show) – eller titler til "check"}
        {--genre= : Filtrér på genre (unwatched)}
        {--days= : Kun de sidste N dage (most-watched)}
        {--wish= : Dit ønske til Claude, f.eks. "noget let" (claude)}
        {--fresh : Spørg Claude igen i stedet for at bruge sidste svar (claude)}
        {--limit=20 : Antal resultater}
        {--json : Output som JSON}';

    protected $description = 'Plex-anbefalinger: smagsprofil, mest sete, usete titler og nye forslag';

    public function handle(RecommenderService $svc): int
    {
        $args = $this->argument('args');
        $type = $args[0] ?? 'movie';
        $limit = (int) $this->option('limit');

        $result = match ($this->argument('action')) {
            'overview' => $svc->libraryOverview(),
            'profile' => $svc->tasteProfile($args[0] ?? null, $limit),
            'most-watched' => $svc->mostWatched($type, $limit, $this->option('days') ? (int) $this->option('days') : null),
            'history' => $svc->recentHistory($limit),
            'unwatched' => $svc->unwatchedInLibrary($type, $limit, $this->option('genre')),
            'recommend' => $svc->recommendNew($type, $limit),
            'claude' => app(\EmilMoe\PlexRecommender\Claude\ClaudeAdvisor::class)->recommend($type, $this->option('wish'), min($limit, 20), (bool) $this->option('fresh')),
            'check' => $svc->checkOwned($args),
            'refresh' => tap(['ok' => 'Cache ryddet.'], fn () => $svc->refresh()),
            default => null,
        };

        if ($result === null) {
            $this->error('Ukendt handling. Se: php artisan plex --help');

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->render($result);

        return self::SUCCESS;
    }

    protected function render(array $result): void
    {
        if (isset($result['error'])) {
            $this->warn($result['error']);

            return;
        }
        if (isset($result['driver'])) {
            foreach ($result['recommendations'] as $r) {
                $this->newLine();
                $this->line('<info>'.$r['title'].($r['year'] ? " ({$r['year']})" : '').'</info>'.($r['in_library'] ? ' <comment>[i dit bibliotek]</comment>' : ''));
                $this->line('  '.$r['reason']);
                if ($r['because_you_watched']) {
                    $this->line('  <fg=gray>Fordi du så '.implode(', ', $r['because_you_watched']).'</>');
                }
            }

            return;
        }
        if (isset($result['recommendations'])) {
            $this->info('Baseret på: '.implode(', ', $result['seeds_used'] ?? []));
            $result = $result['recommendations'];
        }
        if (array_is_list($result) && $result && is_array($result[0])) {
            $rows = array_map(fn ($r) => array_map(
                fn ($v) => is_array($v) ? implode(', ', array_map(fn ($x) => is_array($x) ? json_encode($x) : $x, $v)) : $v,
                array_diff_key($r, array_flip(['summary', 'overview', 'url', 'breakdown'])),
            ), $result);
            $headers = array_keys(array_merge(...$rows));
            $rows = array_map(fn ($r) => array_map(fn ($h) => $r[$h] ?? '', $headers), $rows);
            $this->table($headers, $rows);

            return;
        }
        foreach ($result as $section => $value) {
            $this->newLine();
            $this->info(str_replace('_', ' ', ucfirst((string) $section)));
            is_array($value) && $value && array_is_list($value)
                ? $this->render($value)
                : $this->line($value === [] ? '  (ingen)' : (is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value));
        }
    }
}
