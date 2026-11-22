<?php

namespace EmilMoe\PlexRecommender\Filament\Pages\Concerns;

use EmilMoe\PlexRecommender\Filament\PlexRecommenderPlugin;
use Throwable;
use UnitEnum;

trait UsesPluginNavigation
{
    public static function getNavigationGroup(): string | UnitEnum | null
    {
        try {
            return PlexRecommenderPlugin::get()->getNavigationGroup();
        } catch (Throwable) {
            return 'Plex';
        }
    }
}
