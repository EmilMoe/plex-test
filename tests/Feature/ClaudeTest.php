<?php

namespace EmilMoe\PlexRecommender\Tests\Feature;

use EmilMoe\PlexRecommender\Claude\ClaudeAdvisor;
use EmilMoe\PlexRecommender\Data\MediaItem;
use EmilMoe\PlexRecommender\Filament\Pages\Recommendations;
use EmilMoe\PlexRecommender\TasteProfile;
use EmilMoe\PlexRecommender\Tests\TestCase;
use Filament\Facades\Filament;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

class ClaudeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Any existing file works as "the claude binary": Process is faked.
        config(['plex-recommender.claude.binary' => PHP_BINARY]);
    }

    protected function fakeClaude(array $recommendations): void
    {
        Process::fake([
            '*' => Process::result(json_encode([
                'type' => 'result',
                'is_error' => false,
                'result' => "Her er mine forslag:\n```json\n".json_encode(['recommendations' => $recommendations])."\n```",
            ])),
        ]);
    }

    protected function advisor(): ClaudeAdvisor
    {
        return app(ClaudeAdvisor::class);
    }

    public function test_runs_claude_code_and_checks_suggestions_against_library(): void
    {
        $this->fakeClaude([
            ['title' => 'Dune', 'original_title' => null, 'year' => 2021, 'reason' => 'Villeneuve igen.', 'because_you_watched' => ['Arrival']],
            ['title' => 'Arrival', 'original_title' => null, 'year' => 2016, 'reason' => 'Allerede set', 'because_you_watched' => []],
            ['title' => 'Ex Machina', 'original_title' => null, 'year' => 2015, 'reason' => 'Klog sci-fi.', 'because_you_watched' => ['Blade Runner 2049']],
        ]);

        $out = $this->advisor()->recommend('movie', 'noget med rumskibe');

        $this->assertSame('Claude Code', $out['driver']);
        $this->assertSame(['Dune', 'Ex Machina'], array_column($out['recommendations'], 'title')); // Arrival is watched
        $this->assertTrue($out['recommendations'][0]['in_library']);
        $this->assertFalse($out['recommendations'][1]['in_library']);

        Process::assertRan(function (PendingProcess $process) {
            $this->assertSame(PHP_BINARY, $process->command[0]);
            $this->assertContains('-p', $process->command);
            $this->assertStringContainsString('noget med rumskibe', $process->input);
            $this->assertStringContainsString('Blade Runner 2049', $process->input); // watched list / profile
            $this->assertStringContainsString('Love Actually', $process->input); // unwatched in library

            return true;
        });
    }

    public function test_answer_is_cached_until_asked_fresh(): void
    {
        $this->fakeClaude([['title' => 'Ex Machina', 'original_title' => null, 'year' => 2015, 'reason' => 'x', 'because_you_watched' => []]]);

        $this->assertNull($this->advisor()->cached('movie'));
        $this->advisor()->recommend('movie');
        $this->assertNotNull($this->advisor()->cached('movie'));
        $this->advisor()->recommend('movie');
        Process::assertRanTimes(fn () => true, 1);

        $this->advisor()->recommend('movie', null, 12, true);
        Process::assertRanTimes(fn () => true, 2);
    }

    public function test_reports_claude_code_errors_in_danish(): void
    {
        Process::fake(['*' => Process::result('', 'Invalid API key · Please run /login', 1)]);

        $out = $this->advisor()->recommend('movie');

        $this->assertStringContainsString('ikke logget ind', $out['error']);
    }

    public function test_missing_binary_gives_helpful_error(): void
    {
        config(['plex-recommender.claude.binary' => '/nope/claude']);
        Process::fake();

        $out = $this->advisor()->recommend('movie');

        $this->assertStringContainsString('/nope/claude', $out['error']);
        Process::assertNothingRan();
    }

    public function test_can_be_turned_off(): void
    {
        config(['plex-recommender.claude.driver' => 'off']);

        $this->assertFalse($this->advisor()->enabled());
        $this->assertArrayHasKey('error', $this->advisor()->recommend('movie'));
    }

    public function test_decode_tolerates_text_around_json(): void
    {
        $this->assertSame(['a' => 1], ClaudeAdvisor::decode('Svar: {"a": 1} Hilsen'));
        $this->assertSame(['a' => 1], ClaudeAdvisor::decode("```json\n{\"a\":1}\n```"));
        $this->assertNull(ClaudeAdvisor::decode('ingen json'));
    }

    public function test_filament_tab(): void
    {
        Filament::setCurrentPanel('admin');
        $this->fakeClaude([['title' => 'Ex Machina', 'original_title' => null, 'year' => 2015, 'reason' => 'Klog sci-fi om AI.', 'because_you_watched' => ['Arrival']]]);

        Livewire::test(Recommendations::class)
            ->call('load')
            ->set('source', 'claude')
            ->assertSee('Få forslag fra Claude')
            ->set('wish', 'noget klogt')
            ->call('askClaude')
            ->assertSee('Ex Machina')
            ->assertSee('Klog sci-fi om AI.')
            ->assertSee('Fordi du så Arrival')
            ->assertSee('Spørg igen');
    }

    public function test_long_shows_do_not_outweigh_movies(): void
    {
        $now = time();
        $movieSeenThrice = new MediaItem('1', 'M', MediaItem::MOVIE, viewCount: 3, lastViewedAt: $now);
        $longShowFinished = new MediaItem('2', 'S', MediaItem::SHOW, leafCount: 236, viewedLeafCount: 236, lastViewedAt: $now);
        $showDropped = new MediaItem('3', 'D', MediaItem::SHOW, leafCount: 20, viewedLeafCount: 2, lastViewedAt: $now);
        $movieOnce = new MediaItem('4', 'O', MediaItem::MOVIE, viewCount: 1, lastViewedAt: $now);

        $w = fn ($i) => TasteProfile::engagementWeight($i, $now);
        $this->assertEqualsWithDelta($w($movieSeenThrice), $w($longShowFinished), 0.01);
        $this->assertLessThan($w($movieOnce), $w($showDropped));
    }
}
