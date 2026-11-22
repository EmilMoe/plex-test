<?php

namespace EmilMoe\PlexRecommender\Filament\Pages;

use EmilMoe\PlexRecommender\Claude\ClaudeAdvisor;
use EmilMoe\PlexRecommender\Data\MediaItem;
use EmilMoe\PlexRecommender\Filament\Pages\Concerns\UsesPluginNavigation;
use EmilMoe\PlexRecommender\RecommenderService;
use EmilMoe\PlexRecommender\Settings;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\URL;
use Livewire\Attributes\Url as UrlAttribute;
use Throwable;

class Recommendations extends Page
{
    use UsesPluginNavigation;

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedFilm;

    protected static ?string $navigationLabel = 'Forslag';

    protected static ?string $title = 'Hvad skal jeg se?';

    protected static ?string $slug = 'plex/anbefalinger';

    protected static ?int $navigationSort = -10;

    /** As the panel's start page (plugin ->homePage()), the page lives at the panel root. */
    public static function getRoutePath(\Filament\Panel $panel): string
    {
        try {
            if ($panel->hasPlugin('plex-recommender') && $panel->getPlugin('plex-recommender')->isHomePage()) {
                return '/';
            }
        } catch (Throwable) {
            // fall through to the normal slug
        }

        return parent::getRoutePath($panel);
    }

    protected string $view = 'plex-recommender::filament.recommendations';

    #[UrlAttribute]
    public string $type = MediaItem::MOVIE;

    #[UrlAttribute]
    public string $source = 'claude';

    #[UrlAttribute]
    public ?string $genre = null;

    public bool $ready = false;

    public ?string $error = null;

    /** Free-text wish for Claude, e.g. "noget let og sjovt". */
    #[UrlAttribute(as: 'onske')]
    public string $wish = '';

    public ?string $claudeError = null;

    /** How niche Claude's suggestions should be: kendt | smal | perler. */
    #[UrlAttribute(as: 'smal')]
    public string $niche = 'smal';

    public function mount(): void
    {
        // AI suggestions are the default; fall back when Claude is turned off.
        if ($this->source === 'claude' && ! $this->claudeEnabled()) {
            $this->source = 'library';
        }
    }

    /** Ask Claude (slow: runs Claude Code or calls the API). Result is cached. */
    public function askClaude(bool $fresh = false): void
    {
        @set_time_limit(300);
        $this->claudeError = null;
        $result = app(ClaudeAdvisor::class)->recommend($this->type, $this->wish, $this->niche, 12, $fresh);
        if (isset($result['error'])) {
            $this->claudeError = $result['error'];
        }
    }

    public function claudeEnabled(): bool
    {
        return app(ClaudeAdvisor::class)->enabled();
    }

    /** Cached Claude answer for the current type + wish, or null if not asked yet. */
    public function claudeResult(): ?array
    {
        if (! $this->ready || ! $this->isConnected()) {
            return null;
        }

        return app(ClaudeAdvisor::class)->cached($this->type, $this->wish, $this->niche);
    }

    public function getSubheading(): ?string
    {
        return 'Personlige forslag ud fra det, du ser og bedømmer i Plex.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Opdater')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(function (RecommenderService $svc) {
                    $svc->refresh();
                    $this->ready = true;
                }),
        ];
    }

    public function load(): void
    {
        $this->ready = true;
    }

    /** First visit without a saved answer: ask Claude right away (called from the view). */
    public function autoAskClaude(): void
    {
        if ($this->source === 'claude' && $this->isConnected() && $this->claudeEnabled() && ! $this->claudeResult() && ! $this->wish) {
            $this->askClaude();
        }
    }

    public function updated(string $property): void
    {
        if ($property === 'type') {
            $this->genre = null;
        }
    }

    public function isConnected(): bool
    {
        return app(Settings::class)->connected();
    }

    public function hasTmdb(): bool
    {
        return app(RecommenderService::class)->hasTmdb();
    }

    public function settingsUrl(): ?string
    {
        return PlexSettings::canAccess() ? PlexSettings::getUrl() : null;
    }

    /** @return list<array> normalized cards for the view */
    public function cards(): array
    {
        if (! $this->ready || ! $this->isConnected()) {
            return [];
        }
        $this->error = null;
        $svc = app(RecommenderService::class);

        try {
            if ($this->source === 'claude') {
                return array_map(fn ($r) => [
                    'title' => $r['title'],
                    'year' => $r['year'],
                    'poster' => ($thumb = $this->thumbFor($r['rating_key'])) ? URL::temporarySignedRoute('plex-recommender.poster', now()->addDay(), ['path' => $thumb]) : ($r['poster'] ?? null),
                    'badge' => $r['in_library'] ? 'På din Plex' : ($r['tmdb_rating'] ? number_format($r['tmdb_rating'], 1, ',', '') : null),
                    'badge_title' => $r['in_library'] ? 'Ligger allerede på din Plex' : null,
                    'genres' => array_values(array_filter([$r['director'] ? 'Instr. '.$r['director'] : null, $r['country']])),
                    'reasons' => array_values(array_filter([
                        $r['reason'],
                        $r['because_you_watched'] ? 'Fordi du så '.$this->list($r['because_you_watched']) : null,
                    ])),
                    'summary' => $r['overview'] ?: ($r['original_title'] ? 'Originaltitel: '.$r['original_title'] : null),
                    'url' => $r['url'] ?? 'https://www.themoviedb.org/search?query='.rawurlencode($r['title']),
                    'score' => null,
                    'why' => [],
                ], $this->claudeResult()['recommendations'] ?? []);
            }

            if ($this->source === 'new') {
                $result = $svc->recommendNew($this->type, 24);
                if (isset($result['error'])) {
                    return [];
                }

                return array_map(fn ($r) => [
                    'title' => $r['title'],
                    'year' => $r['year'],
                    'poster' => $r['poster'] ?? null,
                    'badge' => $r['tmdb_rating'] ? number_format($r['tmdb_rating'], 1, ',', '') : null,
                    'genres' => $r['genres'],
                    'reasons' => $r['because_you_watched'] ? ['Fordi du så '.$this->list($r['because_you_watched'])] : ['Populær blandt folk med lignende smag'],
                    'summary' => $r['overview'],
                    'url' => $r['url'],
                    'score' => $r['score'],
                    'why' => $this->newBreakdown($r),
                ], $result['recommendations']);
            }

            return array_map(fn ($r) => [
                'title' => $r['title'],
                'year' => $r['year'],
                'poster' => $r['thumb'] ? URL::temporarySignedRoute('plex-recommender.poster', now()->addDay(), ['path' => $r['thumb']]) : null,
                'badge' => null,
                'genres' => $r['genres'],
                'reasons' => array_map('ucfirst', $r['why']),
                'summary' => $r['summary'],
                'url' => null,
                'progress' => $r['type'] === MediaItem::SHOW && ($r['status'] ?? '') === 'in_progress' ? $r['episodes_watched'] : null,
                'score' => $r['score'],
                'why' => array_map(fn ($p) => [
                    'label' => $p['label'],
                    'detail' => $p['detail'],
                    'value' => ($p['points'] >= 0 ? '+' : '').$this->num($p['points']),
                ], $r['breakdown'] ?? []),
            ], $svc->unwatchedInLibrary($this->type, 24, $this->genre));
        } catch (Throwable $e) {
            $this->error = $e->getMessage();

            return [];
        }
    }

    /** Genres present among unwatched items, for the filter. */
    public function genres(): array
    {
        if (! $this->ready || ! $this->isConnected() || $this->source !== 'library') {
            return [];
        }
        try {
            $genres = [];
            foreach (app(RecommenderService::class)->items($this->type) as $item) {
                if ($item->status() !== 'watched') {
                    foreach ($item->genres as $g) {
                        $genres[$g] = ($genres[$g] ?? 0) + 1;
                    }
                }
            }
            arsort($genres);

            return array_slice(array_keys($genres), 0, 20);
        } catch (Throwable) {
            return [];
        }
    }

    protected function thumbFor(?string $ratingKey): ?string
    {
        if (! $ratingKey) {
            return null;
        }
        foreach (app(RecommenderService::class)->items($this->type) as $item) {
            if ($item->ratingKey === $ratingKey) {
                return $item->thumb;
            }
        }

        return null;
    }

    /** Human-readable explanation rows for a TMDB recommendation. */
    protected function newBreakdown(array $r): array
    {
        $b = $r['breakdown'] ?? [];
        if (! $b) {
            return [];
        }
        $rows = [[
            'label' => 'TMDB-match',
            'detail' => $b['seed_count'] === 1
                ? 'Anbefalet ud fra 1 af dine favoritter'
                : "Anbefalet ud fra {$b['seed_count']} af dine favoritter",
            'value' => $this->num($b['tmdb_match']),
        ]];
        foreach ($b['seeds'] as $seed) {
            $rows[] = ['label' => '', 'detail' => $seed['title'], 'value' => 'vægt '.$this->num($seed['engagement']), 'sub' => true];
        }
        $rows[] = ['label' => 'Genre', 'detail' => 'Hvor godt genren passer din profil', 'value' => '×'.$this->num($b['genre_factor'])];
        $rows[] = ['label' => 'Bedømmelse', 'detail' => $r['tmdb_rating'] ? number_format($r['tmdb_rating'], 1, ',', '').'/10 på TMDB' : 'Ingen bedømmelse', 'value' => '×'.$this->num($b['quality_factor'])];

        return $rows;
    }

    protected function num(float $n): string
    {
        return number_format($n, 2, ',', '');
    }

    protected function list(array $titles): string
    {
        $titles = array_slice($titles, 0, 3);
        $last = array_pop($titles);

        return $titles ? implode(', ', $titles).' og '.$last : (string) $last;
    }
}
