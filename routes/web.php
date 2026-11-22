<?php

use EmilMoe\PlexRecommender\Http\Controllers\PlexAuthController;
use Illuminate\Support\Facades\Route;

Route::middleware(config('plex-recommender.routes.middleware', ['web']))
    ->prefix(config('plex-recommender.routes.prefix', 'plex'))
    ->name('plex-recommender.')
    ->group(function () {
        Route::get('/', [PlexAuthController::class, 'show'])->name('show');
        Route::post('/connect', [PlexAuthController::class, 'connect'])->name('connect');
        Route::get('/callback', [PlexAuthController::class, 'callback'])->name('callback');
        Route::post('/select', [PlexAuthController::class, 'select'])->name('select');
        Route::post('/disconnect', [PlexAuthController::class, 'disconnect'])->name('disconnect');
    });
