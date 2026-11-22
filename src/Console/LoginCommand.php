<?php

namespace EmilMoe\PlexRecommender\Console;

use EmilMoe\PlexRecommender\Settings;
use EmilMoe\PlexRecommender\Auth\PlexTv;
use EmilMoe\PlexRecommender\RecommenderService;
use Illuminate\Console\Command;

class LoginCommand extends Command
{
    protected $signature = 'plex:login
        {--server= : Navn eller id på serveren (spørger hvis der er flere)}
        {--timeout=300 : Sekunder der ventes på login}';

    protected $description = 'Log ind med din Plex-konto (ingen token nødvendig)';

    public function handle(PlexTv $plexTv, Settings $store, RecommenderService $svc): int
    {
        $pin = $plexTv->createPin();
        $this->newLine();
        $this->line('  Åbn dette link og log ind med din Plex-konto:');
        $this->newLine();
        $this->line('  <fg=yellow>'.$plexTv->authUrl($pin['code']).'</>');
        $this->newLine();

        $token = null;
        $deadline = time() + (int) $this->option('timeout');
        $this->output->write('  Venter på login');
        while (! $token && time() < $deadline) {
            sleep(2);
            $this->output->write('.');
            $token = $plexTv->checkPin($pin['id'], $pin['code']);
        }
        $this->newLine(2);

        if (! $token) {
            $this->components->error('Login blev ikke gennemført i tide. Prøv igen.');

            return self::FAILURE;
        }

        $user = $plexTv->user($token);
        $this->components->info('Logget ind som '.($user['username'] ?? 'Plex-bruger'));

        $servers = $plexTv->servers($token);
        if (! $servers) {
            $this->components->error('Din Plex-konto har ingen servere.');

            return self::FAILURE;
        }

        $server = $servers[0];
        if ($wanted = $this->option('server')) {
            $server = collect($servers)->first(fn ($s) => $s['id'] === $wanted || strcasecmp($s['name'], $wanted) === 0);
            if (! $server) {
                $this->components->error("Fandt ingen server ved navn '{$wanted}'.");

                return self::FAILURE;
            }
        } elseif (count($servers) > 1) {
            $labels = array_map(fn ($s) => $s['name'].($s['owned'] ? '' : ' (delt)'), $servers);
            $choice = $this->choice('Hvilken server?', $labels, 0);
            $server = $servers[array_search($choice, $labels, true)];
        }

        $this->line("  Finder den bedste forbindelse til {$server['name']} …");
        if (! $url = $plexTv->connect($server, $token)) {
            $this->components->error("Kunne ikke nå {$server['name']} fra denne maskine.");

            return self::FAILURE;
        }
        $svc->refresh();
        $this->components->info("Forbundet til {$server['name']} via {$url}");

        return self::SUCCESS;
    }
}
