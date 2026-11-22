<?php

namespace EmilMoe\PlexRecommender\Http\Controllers;

use EmilMoe\PlexRecommender\Settings;
use EmilMoe\PlexRecommender\Auth\PlexTv;
use EmilMoe\PlexRecommender\RecommenderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Throwable;

class PlexAuthController extends Controller
{
    public function __construct(protected PlexTv $plexTv, protected Settings $store) {}

    /** Status page with the "Log ind med Plex" button, or a server picker. */
    public function show(Request $request): View
    {
        $servers = [];
        if ($accountToken = $request->session()->get('plex.account_token')) {
            try {
                $servers = $this->plexTv->servers($accountToken);
            } catch (Throwable $e) {
                $request->session()->now('error', 'Kunne ikke hente servere fra Plex: '.$e->getMessage());
            }
        }

        return view('plex-recommender::connect', [
            'store' => $this->store,
            'servers' => $servers,
        ]);
    }

    /** Create a PIN and send the user to plex.tv to sign in. */
    public function connect(Request $request): RedirectResponse
    {
        $pin = $this->plexTv->createPin();
        $request->session()->put('plex.pin', $pin);

        return redirect()->away($this->plexTv->authUrl($pin['code'], route('plex-recommender.callback')));
    }

    /** Plex sends the user back here after sign-in. */
    public function callback(Request $request): RedirectResponse|View
    {
        $pin = $request->session()->get('plex.pin');
        if (! $pin) {
            return redirect()->route('plex-recommender.show')->with('error', 'Login-sessionen udløb. Prøv igen.');
        }

        $accountToken = $this->plexTv->checkPin($pin['id'], $pin['code']);
        if (! $accountToken) {
            // Plex can be a second behind; let the page retry a few times.
            $tries = (int) $request->query('try', 0);
            if ($tries < 10) {
                return view('plex-recommender::waiting', ['next' => route('plex-recommender.callback', ['try' => $tries + 1])]);
            }

            return redirect()->route('plex-recommender.show')->with('error', 'Login blev ikke godkendt i Plex. Prøv igen.');
        }

        $request->session()->forget('plex.pin');
        $servers = $this->plexTv->servers($accountToken);

        if (count($servers) === 1) {
            return $this->finish($request, $servers[0], $accountToken);
        }
        $request->session()->put('plex.account_token', $accountToken);

        return redirect()->route('plex-recommender.show')
            ->with($servers ? 'status' : 'error', $servers ? 'Vælg hvilken server der skal bruges.' : 'Din Plex-konto har ingen servere.');
    }

    public function select(Request $request): RedirectResponse
    {
        $accountToken = $request->session()->get('plex.account_token');
        $server = collect($accountToken ? $this->plexTv->servers($accountToken) : [])
            ->firstWhere('id', $request->input('server'));
        if (! $server) {
            return redirect()->route('plex-recommender.show')->with('error', 'Ukendt server. Log ind igen.');
        }

        return $this->finish($request, $server, $accountToken);
    }

    public function disconnect(Request $request, RecommenderService $svc): RedirectResponse
    {
        $this->store->disconnect();
        $svc->refresh();
        $request->session()->forget(['plex.account_token', 'plex.pin']);

        return redirect()->route('plex-recommender.show')->with('status', 'Forbindelsen til Plex er fjernet.');
    }

    protected function finish(Request $request, array $server, string $accountToken): RedirectResponse
    {
        $url = $this->plexTv->connect($server, $accountToken);
        if (! $url) {
            $request->session()->put('plex.account_token', $accountToken);

            return redirect()->route('plex-recommender.show')->with('error',
                "Kunne ikke nå serveren \"{$server['name']}\" fra denne maskine. Tjek at den er tændt og tilgængelig.");
        }
        $request->session()->forget('plex.account_token');
        app(RecommenderService::class)->refresh();

        return redirect()->route('plex-recommender.show')->with('status', "Forbundet til {$server['name']}.");
    }
}
