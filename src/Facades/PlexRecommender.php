<?php

namespace EmilMoe\PlexRecommender\Facades;

use EmilMoe\PlexRecommender\RecommenderService;
use Illuminate\Support\Facades\Facade;

/**
 * @method static array libraryOverview()
 * @method static array tasteProfile(?string $type = null, int $topN = 10)
 * @method static array mostWatched(string $type, int $limit = 20, ?int $days = null)
 * @method static array recentHistory(int $limit = 30)
 * @method static array unwatchedInLibrary(string $type, int $limit = 20, ?string $genre = null, bool $includeInProgress = false)
 * @method static array recommendNew(string $type, int $limit = 20, int $seeds = 15, int $minVotes = 50)
 * @method static array checkOwned(array $titles)
 * @method static array searchLibrary(string $query, int $limit = 20)
 * @method static \EmilMoe\PlexRecommender\TasteProfile profile(?string $type = null)
 * @method static list<\EmilMoe\PlexRecommender\Data\MediaItem> items(string $type)
 * @method static void refresh()
 *
 * @see RecommenderService
 */
class PlexRecommender extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RecommenderService::class;
    }
}
