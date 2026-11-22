<?php

namespace EmilMoe\PlexRecommender\Http\Controllers;

use EmilMoe\PlexRecommender\Clients\PlexClient;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Streams poster images from Plex so the server token never reaches the browser.
 * URLs are signed (see Recommendations page), so no session is required.
 */
class PosterController extends Controller
{
    public function __invoke(Request $request, PlexClient $plex): Response
    {
        $path = (string) $request->query('path');
        abort_unless(str_starts_with($path, '/library/'), 404);

        try {
            [$body, $type] = $plex->image($path);
        } catch (Throwable) {
            abort(404);
        }

        return response($body, 200, [
            'Content-Type' => $type,
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
