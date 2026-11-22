{{-- Title modal: opened by openDetail() on the page. --}}
<x-filament::modal id="pr-detail" width="4xl" :close-by-clicking-away="true">
    @if ($d = $detail)
        <div class="pr-detail" wire:key="detail-{{ $d['tmdb_id'] ?? $d['title'] }}" x-data="{ play: false }" x-on:close-modal.window="if ($event.detail.id === 'pr-detail') play = false">
            <div class="pr-detail-media">
                <button type="button" class="pr-detail-close" aria-label="Luk" x-on:click="play = false; $dispatch('close-modal', { id: 'pr-detail' })">
                    <x-filament::icon icon="heroicon-m-x-mark" class="pr-detail-close-icon" />
                </button>
                @if (! empty($d['trailer']))
                    <template x-if="play">
                        <iframe class="pr-trailer" src="https://www.youtube-nocookie.com/embed/{{ $d['trailer'] }}?autoplay=1&rel=0"
                            title="Trailer: {{ $d['title'] }}" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen></iframe>
                    </template>
                @endif
                <div class="pr-detail-hero" x-show="! play" @if (! empty($d['backdrop'])) style="background-image: url('{{ $d['backdrop'] }}')" @endif>
                    @if (! empty($d['trailer']))
                        <button type="button" class="pr-play" x-on:click="play = true">
                            <x-filament::icon icon="heroicon-s-play" class="pr-play-icon" />
                            Se trailer
                        </button>
                    @endif
                </div>
            </div>

            <div class="pr-detail-body">
                @if (! empty($d['poster']))
                    <img class="pr-detail-poster" src="{{ $d['poster'] }}" alt="" loading="lazy">
                @endif
                <div class="pr-detail-info">
                    <h2 class="pr-detail-title">{{ $d['title'] }} @if (! empty($d['year']))<span class="pr-muted">({{ $d['year'] }})</span>@endif</h2>
                    @if (! empty($d['original_title']) && $d['original_title'] !== $d['title'])
                        <p class="pr-muted">{{ $d['original_title'] }}</p>
                    @endif
                    @if (! empty($d['tagline']))
                        <p class="pr-detail-tagline">{{ $d['tagline'] }}</p>
                    @endif

                    <p class="pr-detail-facts">
                        {{ collect([
                            ! empty($d['tmdb_rating']) ? '★ '.number_format($d['tmdb_rating'], 1, ',', '').(! empty($d['votes']) ? ' ('.number_format($d['votes'], 0, ',', '.').' stemmer)' : '') : null,
                            ! empty($d['runtime']) ? ($type === 'show' ? $d['runtime'].' min pr. afsnit' : intdiv($d['runtime'], 60).' t '.($d['runtime'] % 60).' min') : null,
                            ! empty($d['seasons']) ? $d['seasons'].' '.($d['seasons'] === 1 ? 'sæson' : 'sæsoner').(! empty($d['episodes']) ? ', '.$d['episodes'].' afsnit' : '') : null,
                            ! empty($d['countries']) ? implode(', ', array_slice($d['countries'], 0, 2)) : null,
                        ])->filter()->implode(' · ') }}
                    </p>
                    @if (! empty($d['genres']))
                        <div class="pr-chips">
                            @foreach (array_slice($d['genres'], 0, 5) as $g)
                                <span class="pr-chip is-static">{{ $g }}</span>
                            @endforeach
                        </div>
                    @endif

                    @foreach ($d['reasons'] ?? [] as $reason)
                        <p class="pr-reason pr-detail-reason">{{ $reason }}</p>
                    @endforeach

                    @if ($overview = $d['overview'] ?? $d['summary'] ?? null)
                        <p class="pr-detail-overview">{{ $overview }}</p>
                    @endif

                    <dl class="pr-detail-credits">
                        @if (! empty($d['directors']))
                            <div><dt>{{ $type === 'show' ? 'Skabt af' : 'Instruktør' }}</dt><dd>{{ implode(', ', $d['directors']) }}</dd></div>
                        @endif
                        @if (! empty($d['cast']))
                            <div><dt>Medvirkende</dt><dd>{{ implode(', ', $d['cast']) }}</dd></div>
                        @endif
                    </dl>

                    <div class="pr-detail-actions">
                        @if (! empty($d['plex_url']))
                            <x-filament::button tag="a" :href="$d['plex_url']" target="_blank" icon="heroicon-s-play" color="warning">Åbn i Plex</x-filament::button>
                        @elseif (! empty($d['in_library']))
                            <x-filament::badge color="success">På din Plex</x-filament::badge>
                        @endif
                        @if (! empty($d['url']))
                            <x-filament::button tag="a" :href="$d['url']" target="_blank" color="gray" icon="heroicon-o-arrow-top-right-on-square">Se på TMDB</x-filament::button>
                        @endif
                    </div>
                    @if (empty($d['has_details']))
                        <p class="pr-muted">Tilføj en TMDB-nøgle i indstillingerne for at se handling, medvirkende og trailer.</p>
                    @endif
                </div>
            </div>
        </div>
    @endif
</x-filament::modal>
