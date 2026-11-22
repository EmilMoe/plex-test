<div class="pr-grid" wire:loading.class="pr-dim" wire:target="type,source,genre,niche">
    @foreach ($cards as $card)
        <article class="pr-card">
            <div class="pr-poster">
                <span class="pr-poster-fallback">{{ mb_substr($card['title'], 0, 1) }}</span>
                @if ($card['poster'])
                    <img src="{{ $card['poster'] }}" alt="" loading="lazy" onerror="this.remove()">
                @endif
                @if ($card['badge'])
                    <span class="pr-badge" title="{{ $card['badge_title'] ?? 'Bedømmelse på TMDB' }}">{{ isset($card['badge_title']) ? '' : '★ ' }}{{ $card['badge'] }}</span>
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
