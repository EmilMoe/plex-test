<?php

namespace EmilMoe\PlexRecommender\Filament\Widgets;

use EmilMoe\PlexRecommender\RecommenderService;
use EmilMoe\PlexRecommender\Settings;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Throwable;

class LibraryStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        if (! app(Settings::class)->connected()) {
            return [];
        }
        try {
            $stats = app(RecommenderService::class)->libraryOverview()['stats'];
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach (['movie' => ['Film', 'set'], 'show' => ['Serier', 'set (helt eller delvist)']] as $type => [$label, $seenLabel]) {
            $s = $stats[$type];
            $seen = ($s['watched'] ?? 0) + ($s['in_progress'] ?? 0);
            $pct = $s['total'] ? round($seen / $s['total'] * 100) : 0;
            $out[] = Stat::make($label.' i biblioteket', number_format($s['total'], 0, ',', '.'))
                ->description("{$pct} % {$seenLabel}")
                ->color('primary');
        }
        $unseen = ($stats['movie']['unwatched'] ?? 0) + ($stats['show']['unwatched'] ?? 0);
        $out[] = Stat::make('Usete titler', number_format($unseen, 0, ',', '.'))
            ->description('Klar til at blive set')
            ->color('success');

        return $out;
    }
}
