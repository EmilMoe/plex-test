<?php

namespace EmilMoe\PlexRecommender\Tests;

use EmilMoe\PlexRecommender\Filament\PlexRecommenderPlugin;
use Filament\Panel;
use Filament\PanelProvider;

class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('admin')->path('admin')->default()->plugin(PlexRecommenderPlugin::make());
    }
}
