<?php

return [

    /*
    | Forbindelse til Plex. Nemmest: gå til /plex og tryk "Log ind med Plex",
    | eller kør `php artisan plex:login`. Så findes server og token automatisk
    | og gemmes krypteret i `credentials_path`.
    |
    | Alternativt kan du sætte PLEX_URL + PLEX_TOKEN manuelt; de vinder over login.
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
    ],

    // Hvor login-forbindelsen gemmes (krypteret med APP_KEY).
    'credentials_path' => storage_path('app/plex-recommender.json'),

    // Siden med "Log ind med Plex"-knappen.
    'routes' => [
        'enabled' => env('PLEX_RECOMMENDER_ROUTES', true),
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

    'profile' => [
        // Halveringstid i dage: hvor hurtigt gamle visninger mister betydning.
        'recency_half_life_days' => 365,
    ],
];
