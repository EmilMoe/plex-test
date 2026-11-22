<?php

namespace EmilMoe\PlexRecommender\Filament\Pages;

use EmilMoe\PlexRecommender\Filament\Pages\Concerns\UsesPluginNavigation;
use EmilMoe\PlexRecommender\Filament\Widgets\LibraryStats;
use EmilMoe\PlexRecommender\RecommenderService;
use EmilMoe\PlexRecommender\Settings;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use Throwable;

class TasteProfile extends Page
{
    use UsesPluginNavigation;

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?string $navigationLabel = 'Min smag';

    protected static ?string $title = 'Min smag';

    protected static ?string $slug = 'plex/min-smag';

    protected static ?int $navigationSort = 20;

    protected string $view = 'plex-recommender::filament.taste-profile';

    /** null = both, 'movie' or 'show' */
    #[Url]
    public ?string $type = null;

    public function getSubheading(): ?string
    {
        return 'Det her siger din Plex-historik om dig.';
    }

    protected function getHeaderWidgets(): array
    {
        return [LibraryStats::class];
    }

    public function isConnected(): bool
    {
        return app(Settings::class)->connected();
    }

    public function settingsUrl(): ?string
    {
        return PlexSettings::canAccess() ? PlexSettings::getUrl() : null;
    }

    public function profile(): ?array
    {
        if (! $this->isConnected()) {
            return null;
        }
        try {
            $svc = app(RecommenderService::class);
            $profile = $svc->tasteProfile($this->type, 8);
            $types = $this->type ? [$this->type] : ['movie', 'show'];
            $recent = [];
            foreach ($types as $t) {
                array_push($recent, ...$svc->mostWatched($t, 8, 90));
            }
            usort($recent, fn ($a, $b) => $b['last_viewed_at'] <=> $a['last_viewed_at']);
            $profile['recent'] = array_slice(array_filter($recent, fn ($r) => isset($r['title'])), 0, 8);

            return $profile;
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
