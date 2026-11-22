@php
    $cards = $this->cards();
    $genres = $this->genres();
    $connected = $this->isConnected();
    $claude = $source === 'claude' ? $this->claudeResult() : null;
@endphp
<x-filament-panels::page>
    <div class="pr-root" wire:init="load">
        <div class="pr-toolbar">
            <div class="pr-segmented" role="tablist" aria-label="Type">
                <button type="button" wire:click="$set('type', 'movie')" @class(['pr-seg', 'is-active' => $type === 'movie'])>Film</button>
                <button type="button" wire:click="$set('type', 'show')" @class(['pr-seg', 'is-active' => $type === 'show'])>Serier</button>
            </div>
            <div class="pr-segmented" role="tablist" aria-label="Kilde">
                <button type="button" wire:click="$set('source', 'claude')" @class(['pr-seg', 'is-active' => $source === 'claude'])>Claudes forslag</button>
                <button type="button" wire:click="$set('source', 'library')" @class(['pr-seg', 'is-active' => $source === 'library'])>Uset i dit bibliotek</button>
                <button type="button" wire:click="$set('source', 'new')" @class(['pr-seg', 'is-active' => $source === 'new'])>Lignende titler</button>
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
                    <p><strong>Claudes forslag</strong>: Claude får dine favoritter (med dine egne stjerner fra Plex), det du har set for nylig, det du gav lave stjerner eller droppede, og alt du har set. Claude beskriver først din smag med egne ord og finder så titler der passer, i den grad af "smalhed" du vælger. Hvert forslag slås op på TMDB: opfundne titler og titler der er for kendte til det valgte niveau, sorteres fra, og det samme gør alt du har set. Svaret gemmes en uge. "Nye forslag" spørger igen og undgår det, Claude har foreslået før.</p>
                    <p>De to andre faner beregnes uden AI direkte ud fra din Plex-historik:</p>
                    <ol>
                        <li><strong>Din smagsprofil.</strong> Alt du har set, vægtes efter hvor meget du har engageret dig: genset film og serier du har set mange afsnit af tæller mere, dine egne stjerner i Plex hæver vægten, og 2 stjerner eller mindre trækker genren, instruktøren og skuespillerne ned, og nyere visninger tæller mere end gamle. Ud fra det får genrer, instruktører, skuespillere og årtier en vægt. Se den under <em>Smagsprofil</em>.</li>
                        <li><strong>Uset i dit bibliotek</strong> giver usete titler point for genre (op til 3), instruktør (op til 1,5), skuespillere (op til 1,5), årti (op til 0,4) og bedømmelse (op til 0,8).</li>
                        <li><strong>Lignende titler</strong> tager dine mest sete titler og henter TMDB's lignende titler. En titel der går igen hos flere af dine favoritter, scorer højere. Derefter ganges der med hvor godt genren passer, og med bedømmelsen på TMDB. Titler du allerede har, sorteres fra.</li>
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
        @elseif ($source === 'claude' && ! $this->claudeEnabled())
            <x-filament::section>
                <div class="pr-empty">
                    <x-filament::icon icon="heroicon-o-chat-bubble-left-right" class="pr-empty-icon" />
                    <h3>Claude er slået fra</h3>
                    <p>Slå "Spørg Claude" til under indstillingerne. Kører appen på samme computer som Claude Code, kræver det ingen API-nøgle.</p>
                    @if ($url = $this->settingsUrl())
                        <x-filament::button tag="a" :href="$url">Åbn indstillinger</x-filament::button>
                    @endif
                </div>
            </x-filament::section>
        @elseif ($source === 'claude')
            <form class="pr-claude" wire:submit="askClaude({{ $claude ? 'true' : 'false' }})">
                <div class="pr-claude-row">
                    <input type="text" class="pr-input" wire:model="wish" maxlength="300"
                           placeholder="Hvad er du i humør til? F.eks. &quot;noget stille og nordisk&quot; (valgfrit)">
                    <select class="pr-select" wire:model.live="niche" aria-label="Hvor smalt">
                        <option value="kendt">Også kendte titler</option>
                        <option value="smal">Mindre kendte</option>
                        <option value="perler">Skjulte perler</option>
                    </select>
                    <x-filament::button type="submit" :color="$claude ? 'gray' : 'primary'" :icon="$claude ? 'heroicon-o-arrow-path' : 'heroicon-o-sparkles'"
                        wire:loading.attr="disabled" wire:target="askClaude,autoAskClaude">
                        {{ $claude ? 'Nye forslag' : 'Få forslag' }}
                    </x-filament::button>
                </div>
                <div class="pr-claude-status" wire:loading.flex wire:target="askClaude,autoAskClaude">
                    <x-filament::loading-indicator class="pr-spinner" />
                    <span>Claude læser din historik og finder forslag… Det tager typisk 30–90 sekunder.</span>
                </div>
                <div wire:loading.remove wire:target="askClaude,autoAskClaude">
                    @if ($claudeError)
                        <p class="pr-claude-error">{{ $claudeError }}</p>
                    @elseif ($claude)
                        <p class="pr-muted">
                            Fra {{ $claude['driver'] }} {{ \Illuminate\Support\Carbon::createFromTimestamp($claude['asked_at'])->locale('da')->diffForHumans() }}.
                            @php($f = $claude['filtered'] ?? [])
                            @if (($f['seen'] ?? 0) + ($f['popular'] ?? 0) + ($f['not_found'] ?? 0) > 0)
                                Sorteret fra: {{ collect([
                                    ($f['seen'] ?? 0) ? $f['seen'].' du har set' : null,
                                    ($f['popular'] ?? 0) ? $f['popular'].' for kendte' : null,
                                    ($f['not_found'] ?? 0) ? $f['not_found'].' ikke fundet på TMDB' : null,
                                ])->filter()->implode(', ') }}.
                            @endif
                        </p>
                    @else
                        <p class="pr-muted" wire:init="autoAskClaude">Claude læser dine favoritter, dine stjerner og det du har set for nylig, og finder titler du ikke har set.</p>
                    @endif
                </div>
            </form>

            @if ($claude && $claude['taste_summary'])
                <div class="pr-taste">
                    <x-filament::icon icon="heroicon-o-sparkles" class="pr-taste-icon" />
                    <div>
                        <p class="pr-taste-label">Din smag ifølge Claude</p>
                        <p>{{ $claude['taste_summary'] }}</p>
                    </div>
                </div>
            @endif

            @if ($cards)
                @include('plex-recommender::filament.partials.cards', ['cards' => $cards])
            @elseif ($claude)
                <x-filament::section>
                    <div class="pr-empty">
                        <x-filament::icon icon="heroicon-o-funnel" class="pr-empty-icon" />
                        <h3>Alle forslag blev sorteret fra</h3>
                        <p>Prøv "Nye forslag" eller et mindre smalt niveau.</p>
                    </div>
                </x-filament::section>
            @endif
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
                    <p>{{ $source === 'library' ? 'Du har set alt i denne kategori. Prøv "Claudes forslag".' : 'Se lidt mere i Plex, så kommer der forslag.' }}</p>
                </div>
            </x-filament::section>
        @else
            @include('plex-recommender::filament.partials.cards', ['cards' => $cards])
        @endif
    </div>
</x-filament-panels::page>
