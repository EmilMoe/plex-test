@extends('plex-recommender::layout')

@section('content')
    <h1>Plex Recommender</h1>

    @if ($servers)
        <p>Vælg den server, anbefalingerne skal baseres på:</p>
        @foreach ($servers as $server)
            <form method="POST" action="{{ route('plex-recommender.select') }}">
                @csrf
                <input type="hidden" name="server" value="{{ $server['id'] }}">
                <button class="server" type="submit">
                    <span>{{ $server['name'] }}</span>
                    <span class="tag">{{ $server['owned'] ? 'din server' : 'delt med dig' }}</span>
                </button>
            </form>
        @endforeach
    @elseif ($store->connected())
        <p>Forbundet. Claude Code og <code>php artisan plex</code> kan nu læse dit bibliotek.</p>
        <dl>
            @if ($store->get('server_name')) <dt>Server</dt><dd>{{ $store->get('server_name') }}</dd> @endif
            <dt>Adresse</dt><dd>{{ $store->url() }}</dd>
            @if ($store->get('connected_at')) <dt>Forbundet</dt><dd>{{ \Illuminate\Support\Carbon::parse($store->get('connected_at'))->format('d.m.Y H:i') }}</dd> @endif
        </dl>
        @if (! $store->isStored('server_token'))
            <p>Forbindelsen kommer fra <code>PLEX_URL</code>/<code>PLEX_TOKEN</code> i .env. Log ind med Plex for at bruge din konto i stedet.</p>
            <form method="POST" action="{{ route('plex-recommender.connect') }}">
                @csrf
                <button class="btn btn-plex" type="submit">Log ind med Plex</button>
            </form>
        @else
            <form method="POST" action="{{ route('plex-recommender.connect') }}">
                @csrf
                <button class="btn btn-plex" type="submit">Skift server / log ind igen</button>
            </form>
            <form method="POST" action="{{ route('plex-recommender.disconnect') }}">
                @csrf
                <button class="btn btn-ghost" type="submit">Afbryd forbindelsen</button>
            </form>
        @endif
    @else
        <p>Forbind din Plex-konto, så kan dine film og serier og din historik bruges til anbefalinger.</p>
        <form method="POST" action="{{ route('plex-recommender.connect') }}">
            @csrf
            <button class="btn btn-plex" type="submit">
                <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M5 2h6.5l7.5 10-7.5 10H5l7.5-10z"/></svg>
                Log ind med Plex
            </button>
        </form>
    @endif
@endsection
