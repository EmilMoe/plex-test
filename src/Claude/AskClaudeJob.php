<?php

namespace EmilMoe\PlexRecommender\Claude;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Asks Claude in the background so the user can leave the page.
 * Progress and the result are shared with the page through the cache.
 */
class AskClaudeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Claude Code can take a while; never retry (it would just ask again). */
    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public string $type,
        public ?string $wish,
        public string $niche,
        public bool $fresh = false,
    ) {}

    public function handle(ClaudeAdvisor $advisor): void
    {
        @set_time_limit($this->timeout + 30);
        $advisor->runQueued($this->type, $this->wish, $this->niche, $this->fresh);
    }

    public function failed(?Throwable $e): void
    {
        app(ClaudeAdvisor::class)->markFailed($this->type, $this->wish, $this->niche, 'Claude-jobbet fejlede'.($e ? ': '.$e->getMessage() : '.'));
    }
}
