<?php

return [

    /*
    | Forbindelse til Plex. Nemmest: gå til /plex og tryk "Log ind med Plex",
    | eller kør `php artisan plex:login`. Så findes server og token automatisk
    | og gemmes krypteret i `credentials_path`.
    |
    | Alle værdier her er kun standardværdier: det der gemmes i Filament-
    | indstillingerne (eller via login) vinder over .env.
    */
    'plex' => [
        'url' => env('PLEX_URL'),
        'token' => env('PLEX_TOKEN'),
        'verify_ssl' => env('PLEX_VERIFY_SSL', true),
        // Begræns historik til én Plex-bruger (ejeren er typisk 1). null = alle.
        'account_id' => env('PLEX_ACCOUNT_ID'),
        'timeout' => 30,
    ],

    /*
    | Valgfri, men anbefalet: gratis nøgle fra https://www.themoviedb.org/settings/api
    | Både v3 "API Key" og v4 "API Read Access Token" virker.
    */
    'tmdb' => [
        'api_key' => env('TMDB_API_KEY'),
        'language' => env('PLEX_RECOMMENDER_LANGUAGE', 'da-DK'),
        'base_url' => env('TMDB_BASE_URL', 'https://api.themoviedb.org/3'),
    ],

    // Hvor login-forbindelsen gemmes (krypteret med APP_KEY).
    'credentials_path' => storage_path('app/plex-recommender.json'),

    // Siden med "Log ind med Plex"-knappen.
    'routes' => [
        // Standalone-side uden Filament. Filament-pluginnet har sin egen side.
        'enabled' => env('PLEX_RECOMMENDER_ROUTES', false),
        'prefix' => 'plex',
        // Standard kræver at man er logget ind i din app. Har appen ingen login,
        // så sæt PLEX_RECOMMENDER_MIDDLEWARE=web
        'middleware' => explode(',', env('PLEX_RECOMMENDER_MIDDLEWARE', 'web,auth')),
    ],

    // Biblioteksdata caches i så mange sekunder (Laravel-cachen).
    'cache_ttl' => env('PLEX_RECOMMENDER_CACHE_TTL', 600),

    // Navnet MCP-serveren registreres under: php artisan mcp:start plex
    // Sæt til null hvis du selv vil registrere den i routes/ai.php.
    'mcp_handle' => 'plex',

    'recommendations' => [
        // Minimum antal TMDB-stemmer før en titel foreslås.
        'min_votes' => 50,
    ],

    'profile' => [
        // Halveringstid i dage: hvor hurtigt gamle visninger mister betydning.
        'recency_half_life_days' => 180,
    ],
];
