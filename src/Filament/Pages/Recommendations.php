<?php

namespace EmilMoe\PlexRecommender\Filament\Pages;

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

    protected static ?string $navigationLabel = 'Anbefalinger';

    protected static ?string $title = 'Hvad skal jeg se?';

    protected static ?string $slug = 'plex/anbefalinger';

    protected static ?int $navigationSort = 10;

    protected string $view = 'plex-recommender::filament.recommendations';

    #[UrlAttribute]
    public string $type = MediaItem::MOVIE;

    #[UrlAttribute]
    public string $source = 'library';

    #[UrlAttribute]
    public ?string $genre = null;

    public bool $ready = false;

    public ?string $error = null;

    public function getSubheading(): ?string
    {
        return 'Forslag baseret på det, du ser mest i Plex.';
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
                    'reasons' => $r['because_you_watched'] ? ['Fordi du så '.$this->list($r['because_you_watched'])] : [],
                    'summary' => $r['overview'],
                    'url' => $r['url'],
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

    protected function list(array $titles): string
    {
        $titles = array_slice($titles, 0, 3);
        $last = array_pop($titles);

        return $titles ? implode(', ', $titles).' og '.$last : (string) $last;
    }
}
