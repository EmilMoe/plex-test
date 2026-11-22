<?php

return [

    /*
    | Din Plex Media Server, f.eks. http://192.168.1.10:32400
    | Tokenet finder du i Plex Web: en film → Get Info → View XML → X-Plex-Token=...
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
