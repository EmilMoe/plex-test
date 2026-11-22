@php
    $cards = $this->cards();
    $genres = $this->genres();
    $connected = $this->isConnected();
@endphp
<x-filament-panels::page>
    <div class="pr-root" wire:init="load">
        <div class="pr-toolbar">
            <div class="pr-segmented" role="tablist" aria-label="Type">
                <button type="button" wire:click="$set('type', 'movie')" @class(['pr-seg', 'is-active' => $type === 'movie'])>Film</button>
                <button type="button" wire:click="$set('type', 'show')" @class(['pr-seg', 'is-active' => $type === 'show'])>Serier</button>
            </div>
            <div class="pr-segmented" role="tablist" aria-label="Kilde">
                <button type="button" wire:click="$set('source', 'library')" @class(['pr-seg', 'is-active' => $source === 'library'])>Fra dit bibliotek</button>
                <button type="button" wire:click="$set('source', 'new')" @class(['pr-seg', 'is-active' => $source === 'new'])>Værd at skaffe</button>
            </div>
            @if ($genres)
                <select class="pr-select" wire:model.live="genre" aria-label="Genre">
                    <option value="">Alle genrer</option>
                    @foreach ($genres as $g)
                        <option value="{{ $g }}">{{ $g }}</option>
                    @endforeach
                </select>
            @endif
        </div>

        @if ($connected)
            <details class="pr-explain">
                <summary>Sådan finder vi forslag</summary>
                <div class="pr-explain-body">
                    <p>Forslagene beregnes direkte ud fra din Plex-historik. Der bruges ingen AI.</p>
                    <ol>
                        <li><strong>Din smagsprofil.</strong> Alt du har set, vægtes efter hvor meget du har engageret dig: genset film og serier du har set mange afsnit af tæller mere, dine egne stjerner i Plex hæver eller sænker vægten, og nyere visninger tæller mere end gamle. Ud fra det får genrer, instruktører, skuespillere og årtier en vægt. Se den under <em>Smagsprofil</em>.</li>
                        <li><strong>Fra dit bibliotek</strong> giver usete titler point for genre (op til 3), instruktør (op til 1,5), skuespillere (op til 1,5), årti (op til 0,4) og bedømmelse (op til 0,8).</li>
                        <li><strong>Værd at skaffe</strong> tager dine mest sete titler og henter TMDB's lignende titler. En titel der går igen hos flere af dine favoritter, scorer højere. Derefter ganges der med hvor godt genren passer, og med bedømmelsen på TMDB. Titler du allerede har, sorteres fra.</li>
                    </ol>
                    <p>Klik på <em>Hvorfor?</em> på et kort for at se præcis hvor pointene kommer fra.</p>
                </div>
            </details>
        @endif

        @if (! $connected)
            <x-filament::section>
                <div class="pr-empty">
                    <x-filament::icon icon="heroicon-o-link" class="pr-empty-icon" />
                    <h3>Forbind din Plex først</h3>
                    <p>Når Plex er forbundet, kan vi se hvad du har set, og foreslå hvad du skal se næste gang.</p>
                    @if ($url = $this->settingsUrl())
                        <x-filament::button tag="a" :href="$url" color="warning">Gå til indstillinger</x-filament::button>
                    @endif
                </div>
            </x-filament::section>
        @elseif (! $ready)
            <div class="pr-grid">
                @for ($i = 0; $i < 12; $i++)
                    <div class="pr-card pr-skeleton"><div class="pr-poster"></div><div class="pr-body"><div class="pr-line"></div><div class="pr-line short"></div></div></div>
                @endfor
            </div>
        @elseif ($source === 'new' && ! $this->hasTmdb())
            <x-filament::section>
                <div class="pr-empty">
                    <x-filament::icon icon="heroicon-o-sparkles" class="pr-empty-icon" />
                    <h3>Slå forslag til nye titler til</h3>
                    <p>Tilføj en gratis TMDB-nøgle i indstillingerne for at få forslag til film og serier, du ikke har endnu.</p>
                    @if ($url = $this->settingsUrl())
                        <x-filament::button tag="a" :href="$url">Åbn indstillinger</x-filament::button>
                    @endif
                </div>
            </x-filament::section>
        @elseif ($this->error)
            <x-filament::section>
                <div class="pr-empty">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="pr-empty-icon is-danger" />
                    <h3>Kunne ikke hente data fra Plex</h3>
                    <p>{{ $this->error }}</p>
                </div>
            </x-filament::section>
        @elseif (! $cards)
            <x-filament::section>
                <div class="pr-empty">
                    <x-filament::icon icon="heroicon-o-check-badge" class="pr-empty-icon" />
                    <h3>Intet at vise</h3>
                    <p>{{ $source === 'library' ? 'Du har set alt i denne kategori. Prøv "Værd at skaffe".' : 'Se lidt mere i Plex, så kommer der forslag.' }}</p>
                </div>
            </x-filament::section>
        @else
            <div class="pr-grid" wire:loading.class="pr-dim">
                @foreach ($cards as $card)
                    <article class="pr-card">
                        <div class="pr-poster">
                            <span class="pr-poster-fallback">{{ mb_substr($card['title'], 0, 1) }}</span>
                            @if ($card['poster'])
                                <img src="{{ $card['poster'] }}" alt="" loading="lazy" onerror="this.remove()">
                            @endif
                            @if ($card['badge'])
                                <span class="pr-badge" title="Bedømmelse på TMDB">★ {{ $card['badge'] }}</span>
                            @endif
                            @if (! empty($card['progress']))
                                <span class="pr-badge" title="Sete afsnit">{{ $card['progress'] }}</span>
                            @endif
                        </div>
                        <div class="pr-body">
                            <h3 class="pr-title">
                                @if ($card['url'])
                                    <a href="{{ $card['url'] }}" target="_blank" rel="noopener">{{ $card['title'] }}</a>
                                @else
                                    {{ $card['title'] }}
                                @endif
                            </h3>
                            <p class="pr-meta">{{ collect([$card['year'], implode(', ', array_slice($card['genres'], 0, 2))])->filter()->implode(' · ') }}</p>
                            @foreach ($card['reasons'] as $reason)
                                <p class="pr-reason">{{ $reason }}</p>
                            @endforeach
                            @if ($card['summary'])
                                <p class="pr-summary">{{ $card['summary'] }}</p>
                            @endif
                            @if (! empty($card['why']))
                                <details class="pr-why">
                                    <summary>Hvorfor? <span class="pr-score">{{ number_format($card['score'], 2, ',', '') }} point</span></summary>
                                    <dl class="pr-why-list">
                                        @foreach ($card['why'] as $row)
                                            <div @class(['pr-why-row', 'is-sub' => $row['sub'] ?? false])>
                                                <dt>{{ $row['label'] }}@if ($row['label'] && $row['detail'])<span>{{ $row['detail'] }}</span>@elseif ($row['detail']){{ $row['detail'] }}@endif</dt>
                                                <dd>{{ $row['value'] }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                </details>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>
