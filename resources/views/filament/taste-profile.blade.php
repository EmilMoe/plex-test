@php($p = $this->profile())
<x-filament-panels::page>
    <div class="pr-root">
        <div class="pr-toolbar">
            <div class="pr-segmented">
                <button type="button" wire:click="$set('type', null)" @class(['pr-seg', 'is-active' => $type === null])>Alt</button>
                <button type="button" wire:click="$set('type', 'movie')" @class(['pr-seg', 'is-active' => $type === 'movie'])>Film</button>
                <button type="button" wire:click="$set('type', 'show')" @class(['pr-seg', 'is-active' => $type === 'show'])>Serier</button>
            </div>
        </div>

        @if ($p === null)
            <x-filament::section>
                <div class="pr-empty">
                    <x-filament::icon icon="heroicon-o-link" class="pr-empty-icon" />
                    <h3>Forbind din Plex først</h3>
                    <p>Så kan vi vise, hvad du kan lide.</p>
                    @if ($url = $this->settingsUrl())
                        <x-filament::button tag="a" :href="$url" color="warning">Gå til indstillinger</x-filament::button>
                    @endif
                </div>
            </x-filament::section>
        @elseif (isset($p['error']))
            <x-filament::section>
                <div class="pr-empty">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="pr-empty-icon is-danger" />
                    <h3>Kunne ikke hente data fra Plex</h3>
                    <p>{{ $p['error'] }}</p>
                </div>
            </x-filament::section>
        @else
            <div class="pr-columns" wire:loading.class="pr-dim">
                @foreach ([
                    'Yndlingsgenrer' => $p['top_genres'],
                    'Instruktører' => $p['top_directors'],
                    'Skuespillere' => $p['top_actors'],
                    'Årtier' => $p['favorite_decades'],
                ] as $heading => $rows)
                    <x-filament::section :heading="$heading">
                        @forelse ($rows as $row)
                            <div class="pr-bar-row">
                                <span class="pr-bar-label">{{ match ($heading) { 'Yndlingsgenrer' => ucfirst($row['name']), 'Årtier' => str_replace('s', "'erne", $row['name']), default => $row['name'] } }}</span>
                                <span class="pr-bar"><span style="width: {{ max(4, round($row['weight'] * 100)) }}%"></span></span>
                            </div>
                        @empty
                            <p class="pr-muted">Ikke nok data endnu.</p>
                        @endforelse
                    </x-filament::section>
                @endforeach
            </div>

            <div class="pr-columns pr-columns-2">
                <x-filament::section heading="Det du har set mest" description="Gensyn, sete afsnit og din egen bedømmelse tæller med.">
                    <ol class="pr-list">
                        @forelse ($p['most_engaged_titles'] as $t)
                            <li>
                                <span>{{ $t['title'] }} <span class="pr-muted">{{ $t['year'] }}</span></span>
                                <span class="pr-muted">{{ $t['type'] === 'show' ? $t['episodes_watched'].' afsnit' : ($t['view_count'] > 1 ? $t['view_count'].' gange' : 'set') }}</span>
                            </li>
                        @empty
                            <li class="pr-muted">Ingen historik endnu.</li>
                        @endforelse
                    </ol>
                </x-filament::section>
                <x-filament::section heading="Lige nu" description="Mest afspillet de sidste 90 dage.">
                    <ol class="pr-list">
                        @forelse ($p['recent'] as $t)
                            <li>
                                <span>{{ $t['title'] }} <span class="pr-muted">{{ $t['year'] ?? '' }}</span></span>
                                <span class="pr-muted">{{ $t['plays_in_period'] }} {{ $t['plays_in_period'] === 1 ? 'afspilning' : 'afspilninger' }}</span>
                            </li>
                        @empty
                            <li class="pr-muted">Intet set de sidste 90 dage.</li>
                        @endforelse
                    </ol>
                </x-filament::section>
            </div>
        @endif
    </div>
</x-filament-panels::page>
