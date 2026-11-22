<?php

namespace EmilMoe\PlexRecommender\Console;

use EmilMoe\PlexRecommender\Auth\CredentialStore;
use EmilMoe\PlexRecommender\RecommenderService;
use Illuminate\Console\Command;

class LogoutCommand extends Command
{
    protected $signature = 'plex:logout';

    protected $description = 'Fjern den gemte Plex-forbindelse';

    public function handle(CredentialStore $store, RecommenderService $svc): int
    {
        $store->disconnect();
        $svc->refresh();
        $this->components->info('Plex-forbindelsen er fjernet.');

        return self::SUCCESS;
    }
}
