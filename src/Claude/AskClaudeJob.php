<?php

namespace EmilMoe\PlexRecommender\Claude;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Throwable;

/**
 * Asks Claude in the background so the user can leave the page.
 * Progress and the result are shared with the page through the cache.
 */
class AskClaudeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Claude Code can take a while. */
    public int $timeout = 300;

    /**
     * When the connection's retry_after is shorter than the Claude call, the
     * queue hands the same job to another worker while the first is still
     * running. Those extra attempts must be allowed to reach handle(), which
     * then skips them, instead of failing with MaxAttemptsExceededException.
     * A real error still fails the job right away (maxExceptions), so Claude
     * is never asked twice.
     */
    public int $tries = 5;

    public int $maxExceptions = 1;

    public function __construct(
        public string $type,
        public ?string $wish,
        public string $niche,
        public bool $fresh = false,
    ) {}

    public function handle(ClaudeAdvisor $advisor): void
    {
        if ($this->attempts() > 1 && $this->alreadyHandled($advisor)) {
            return; // a redelivered copy; the first worker is on it or done
        }

        @set_time_limit($this->timeout + 30);
        $advisor->runQueued($this->type, $this->wish, $this->niche, $this->fresh);
    }

    public function failed(?Throwable $e): void
    {
        $advisor = app(ClaudeAdvisor::class);
        if ($e instanceof MaxAttemptsExceededException && $this->alreadyHandled($advisor)) {
            return; // don't overwrite a result (or a run in progress) from the first attempt
        }

        $advisor->markFailed($this->type, $this->wish, $this->niche, 'Claude-jobbet fejlede'.($e ? ': '.$e->getMessage() : '.'));
    }

    /** True when an earlier attempt has started (or finished) this request. */
    protected function alreadyHandled(ClaudeAdvisor $advisor): bool
    {
        $status = $advisor->status($this->type, $this->wish, $this->niche);

        return $status !== null && $status['state'] !== 'queued';
    }
}
