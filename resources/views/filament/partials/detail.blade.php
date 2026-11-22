{{-- Title modal: opened by openDetail() on the page. --}}
<x-filament::modal id="pr-detail" width="4xl" :close-by-clicking-away="true">
    @if ($d = $detail)
        <div class="pr-detail" wire:key="detail-{{ $d['tmdb_id'] ?? $d['title'] }}" x-data="{ play: false }" x-on:close-modal.window="if ($event.detail.id === 'pr-detail') play = false">
            <div class="pr-detail-media" @if (! empty($d['backdrop'])) style="background-image: url('{{ $d['backdrop'] }}')" @endif>
                <button type="button" class="pr-detail-close" aria-label="Luk" x-on:click="play = false; $dispatch('close-modal', { id: 'pr-detail' })">
                    <x-filament::icon icon="heroicon-m-x-mark" class="pr-detail-close-icon" />
                </button>
                @if (! empty($d['trailer']))
                    <template x-if="play">
                        <iframe class="pr-trailer" src="https://www.youtube-nocookie.com/embed/{{ $d['trailer'] }}?autoplay=1&rel=0"
                            title="Trailer: {{ $d['title'] }}" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen></iframe>
                    </template>
                    <button type="button" class="pr-play" x-show="! play" x-on:click="play = true">
                        <x-filament::icon icon="heroicon-s-play" class="pr-play-icon" />
                        Se trailer
                    </button>
                @endif
            </div>

            <div class="pr-detail-body">
                <div class="pr-detail-side">
                    <div class="pr-detail-poster">
                        <span class="pr-poster-fallback">{{ mb_substr($d['title'], 0, 1) }}</span>
                        @if (! empty($d['poster']))
                            <img src="{{ $d['poster'] }}" alt="" loading="lazy" onerror="this.remove()">
                        @endif
                    </div>
                </div>

                <div class="pr-detail-info">
                    <header class="pr-detail-header">
                        <h2 class="pr-detail-title">{{ $d['title'] }}</h2>
                        <p class="pr-detail-facts">
                            {{ collect([
                                $d['year'] ?? null,
                                ! empty($d['runtime']) ? ($type === 'show' ? $d['runtime'].' min pr. afsnit' : intdiv($d['runtime'], 60).' t '.($d['runtime'] % 60).' min') : null,
                                ! empty($d['seasons']) ? $d['seasons'].' '.($d['seasons'] === 1 ? 'sæson' : 'sæsoner') : null,
                                ! empty($d['countries']) ? implode(', ', array_slice($d['countries'], 0, 2)) : null,
                            ])->filter()->implode(' · ') }}
                        </p>
                        @if (! empty($d['original_title']) && $d['original_title'] !== $d['title'])
                            <p class="pr-detail-original">Originaltitel: {{ $d['original_title'] }}</p>
                        @endif
                    </header>

                    <div class="pr-detail-tags">
                        @if (! empty($d['tmdb_rating']))
                            <span class="pr-detail-rating" title="{{ number_format($d['votes'] ?? 0, 0, ',', '.') }} stemmer på TMDB">★ {{ number_format($d['tmdb_rating'], 1, ',', '') }}</span>
                        @endif
                        @foreach (array_slice($d['genres'] ?? [], 0, 4) as $g)
                            <span class="pr-chip is-static">{{ $g }}</span>
                        @endforeach
                    </div>

                    @if ($overview = ($d['overview'] ?? null) ?: ($d['summary'] ?? null))
                        <p class="pr-detail-overview">{{ $overview }}</p>
                    @endif

                    @if (! empty($d['reasons']))
                        <div class="pr-detail-why">
                            <p class="pr-detail-why-label">Derfor er den foreslået</p>
                            @foreach ($d['reasons'] as $reason)
                                <p>{{ $reason }}</p>
                            @endforeach
                        </div>
                    @endif

                    @if (! empty($d['directors']) || ! empty($d['cast']))
                        <dl class="pr-detail-credits">
                            @if (! empty($d['directors']))
                                <div><dt>{{ $type === 'show' ? 'Skabt af' : 'Instruktør' }}</dt><dd>{{ implode(', ', $d['directors']) }}</dd></div>
                            @endif
                            @if (! empty($d['cast']))
                                <div><dt>Medvirkende</dt><dd>{{ implode(', ', $d['cast']) }}</dd></div>
                            @endif
                        </dl>
                    @endif

                    <div class="pr-detail-actions">
                        @if (! empty($d['plex_url']))
                            <x-filament::button tag="a" :href="$d['plex_url']" target="_blank" icon="heroicon-s-play" color="warning" size="sm">Åbn i Plex</x-filament::button>
                        @elseif (! empty($d['in_library']))
                            <x-filament::badge color="success">På din Plex</x-filament::badge>
                        @endif
                        @if (! empty($d['imdb_id']))
                            <x-filament::button tag="a" :href="'https://www.imdb.com/title/'.$d['imdb_id'].'/'" target="_blank" color="gray" size="sm" icon="heroicon-o-arrow-top-right-on-square">IMDb</x-filament::button>
                        @endif
                        @if (! empty($d['url']))
                            <x-filament::button tag="a" :href="$d['url']" target="_blank" color="gray" size="sm" icon="heroicon-o-arrow-top-right-on-square">TMDB</x-filament::button>
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
