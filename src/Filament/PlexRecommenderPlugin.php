<?php

namespace EmilMoe\PlexRecommender\Filament;

use Closure;
use EmilMoe\PlexRecommender\Filament\Pages\PlexSettings;
use EmilMoe\PlexRecommender\Filament\Pages\Recommendations;
use EmilMoe\PlexRecommender\Filament\Pages\TasteProfile;
use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Register in your panel provider:
 *
 *     ->plugin(PlexRecommenderPlugin::make())
 */
class PlexRecommenderPlugin implements Plugin
{
    protected string $navigationGroup = 'Plex';

    protected ?Closure $canManageSettings = null;

    protected bool $settingsPage = true;

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        return filament(app(static::class)->getId());
    }

    public function getId(): string
    {
        return 'plex-recommender';
    }

    /** Navigation group for the pages (null = no group). */
    public function navigationGroup(?string $group): static
    {
        $this->navigationGroup = $group ?? '';

        return $this;
    }

    public function getNavigationGroup(): ?string
    {
        return $this->navigationGroup ?: null;
    }

    /** Restrict who may open the settings page, e.g. fn () => auth()->user()->is_admin */
    public function canManageSettings(Closure $callback): static
    {
        $this->canManageSettings = $callback;

        return $this;
    }

    public function userCanManageSettings(): bool
    {
        return $this->canManageSettings ? (bool) app()->call($this->canManageSettings) : true;
    }

    /** Hide the settings page (when configuring via .env only). */
    public function settingsPage(bool $enabled = true): static
    {
        $this->settingsPage = $enabled;

        return $this;
    }

    public function register(Panel $panel): void
    {
        $panel->pages(array_filter([
            Recommendations::class,
            TasteProfile::class,
            $this->settingsPage ? PlexSettings::class : null,
        ]));
    }

    public function boot(Panel $panel): void {}
}
