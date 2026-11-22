<?php

namespace EmilMoe\PlexRecommender\Claude;

use EmilMoe\PlexRecommender\Data\MediaItem;
use EmilMoe\PlexRecommender\RecommenderService;
use EmilMoe\PlexRecommender\Settings;
use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

/**
 * Asks Claude for recommendations based on the taste profile and watch
 * history, then checks every suggestion against the Plex library.
 */
class ClaudeAdvisor
{
    public const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'recommendations' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string', 'description' => 'Titel som den kendes i Danmark'],
                        'original_title' => ['type' => ['string', 'null']],
                        'year' => ['type' => ['integer', 'null']],
                        'reason' => ['type' => 'string', 'description' => '1-2 sætninger på dansk: hvorfor netop denne bruger vil kunne lide den'],
                        'because_you_watched' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['title', 'original_title', 'year', 'reason', 'because_you_watched'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['recommendations'],
        'additionalProperties' => false,
    ];

    public function __construct(
        protected RecommenderService $svc,
        protected Settings $settings,
        protected Cache $cache,
        protected ?ClaudeDriver $driver = null,
    ) {}

    public function driver(): ?ClaudeDriver
    {
        if ($this->driver) {
            return $this->driver;
        }

        return match ($this->settings->claudeDriver()) {
            'code' => new ClaudeCodeDriver($this->settings->claudeBinary(), $this->settings->claudeModel()),
            'api' => ($key = $this->settings->anthropicApiKey()) ? new AnthropicApiDriver($key, $this->settings->claudeModel()) : null,
            default => null,
        };
    }

    public function enabled(): bool
    {
        return $this->driver() !== null;
    }

    /** Previously fetched answer for these inputs, without calling Claude. */
    public function cached(string $type, ?string $wish = null): ?array
    {
        return $this->cache->get($this->cacheKey($type, $wish));
    }

    /**
     * @return array{recommendations: list<array>, driver: string, asked_at: int}|array{error: string}
     */
    public function recommend(string $type, ?string $wish = null, int $limit = 12, bool $fresh = false): array
    {
        $type = RecommenderService::normalizeType($type);
        $wish = trim((string) $wish) ?: null;
        if (! $driver = $this->driver()) {
            return ['error' => 'Claude er ikke sat op. Vælg Claude Code eller indsæt en API-nøgle under Plex-indstillinger.'];
        }
        if (! $fresh && ($hit = $this->cached($type, $wish))) {
            return $hit;
        }

        try {
            $text = $driver->ask($this->systemPrompt(), $this->prompt($type, $wish, $limit), self::SCHEMA);
        } catch (ClaudeException $e) {
            return ['error' => $e->getMessage()];
        }

        $data = self::decode($text);
        if (! isset($data['recommendations']) || ! is_array($data['recommendations'])) {
            return ['error' => 'Kunne ikke forstå svaret fra Claude. Prøv igen.'];
        }

        $recs = array_values(array_filter($data['recommendations'], fn ($r) => is_array($r) && ! empty($r['title'])));
        $owned = $this->svc->checkOwned(array_map(
            fn ($r) => ! empty($r['year']) ? "{$r['title']} ({$r['year']})" : $r['title'],
            $recs,
        ));

        $out = [];
        foreach ($recs as $i => $r) {
            $o = $owned[$i] ?? ['in_library' => false];
            // Also try the original title if the Danish one didn't match.
            if (! $o['in_library'] && ! empty($r['original_title']) && $r['original_title'] !== $r['title']) {
                $o = $this->svc->checkOwned([! empty($r['year']) ? "{$r['original_title']} ({$r['year']})" : $r['original_title']])[0];
            }
            if (($o['status'] ?? null) === 'watched') {
                continue; // Claude didn't know you've seen it
            }
            $out[] = [
                'title' => (string) $r['title'],
                'original_title' => ! empty($r['original_title']) && $r['original_title'] !== $r['title'] ? (string) $r['original_title'] : null,
                'year' => isset($r['year']) && is_numeric($r['year']) ? (int) $r['year'] : null,
                'reason' => (string) ($r['reason'] ?? ''),
                'because_you_watched' => array_values(array_filter((array) ($r['because_you_watched'] ?? []), 'is_string')),
                'in_library' => (bool) $o['in_library'],
                'status' => $o['status'] ?? null,
                'rating_key' => $o['rating_key'] ?? null,
                'genres' => $o['genres'] ?? [],
            ];
        }

        $result = [
            'recommendations' => array_slice($out, 0, $limit),
            'driver' => $driver->label(),
            'asked_at' => time(),
        ];
        $this->cache->put($this->cacheKey($type, $wish), $result, now()->addHours(12));

        return $result;
    }

    /** Quick round trip to check the setup works. Returns the driver label. */
    public function test(): string
    {
        if (! $driver = $this->driver()) {
            throw new ClaudeException('Claude er ikke sat op.');
        }
        $text = $driver->ask('Du er en testassistent.', 'Svar med {"ok": true}', [
            'type' => 'object',
            'properties' => ['ok' => ['type' => 'boolean']],
            'required' => ['ok'],
            'additionalProperties' => false,
        ]);
        if (! (self::decode($text)['ok'] ?? false)) {
            throw new ClaudeException('Claude svarede, men ikke som forventet: '.mb_substr($text, 0, 200));
        }

        return $driver->label();
    }

    public function systemPrompt(): string
    {
        return <<<'TXT'
        Du er en filmkender der giver personlige anbefalinger til en dansk bruger ud fra deres Plex-historik.
        - Vægt det brugeren har set for nylig højest. Gamle favoritter er baggrund, ikke hovedsagen.
        - Foreslå aldrig noget brugeren allerede har set (se listen).
        - Bland gerne titler fra brugerens eget bibliotek (usete) med titler de ikke har.
        - Undgå de mest oplagte valg, hvis de allerede er populære i brugerens genrer; vis lidt bredde.
        - Begrundelsen skal være konkret og personlig (nævn hvad i historikken der peger på titlen), på dansk, højst 2 sætninger.
        TXT;
    }

    public function prompt(string $type, ?string $wish, int $limit): string
    {
        $kind = $type === MediaItem::SHOW ? 'serier' : 'film';
        $items = $this->svc->items($type);
        $profile = $this->svc->profile($type);
        $summary = $profile->summary(10);

        $fmt = fn (array $rows) => implode(', ', array_map(fn ($r) => $r['name'].' ('.$r['weight'].')', $rows)) ?: '–';
        $label = fn (MediaItem $i) => $i->label().($i->lastViewedAt ? ', sidst set '.date('Y-m-d', $i->lastViewedAt) : '')
            .($i->type === MediaItem::SHOW ? ", {$i->viewedLeafCount}/{$i->leafCount} afsnit" : ($i->viewCount > 1 ? ", set {$i->viewCount} gange" : ''))
            .($i->userRating !== null ? ', egen bedømmelse '.$i->userRating.'/10' : '');

        $recent = [];
        try {
            foreach ($this->svc->mostWatched($type, 15, 90) as $r) {
                if (isset($r['title'])) {
                    $recent[] = "- {$r['title']} (sidst set {$r['last_viewed']}, {$r['plays_in_period']} ".($type === MediaItem::SHOW ? 'afsnit' : 'gange').')';
                }
            }
        } catch (Throwable) {
            // History endpoint unavailable: the profile is enough.
        }

        $top = array_map(fn ($t) => '- '.$label($t[0]), array_slice($profile->topTitles, 0, 20));

        $watched = array_filter($items, fn (MediaItem $i) => $i->watched());
        usort($watched, fn ($a, $b) => ($b->lastViewedAt ?? 0) <=> ($a->lastViewedAt ?? 0));
        $seen = array_map(fn (MediaItem $i) => $i->label(), array_slice($watched, 0, 400));

        $unwatched = array_map(fn ($r) => $r['title'].($r['year'] ? " ({$r['year']})" : ''), $this->svc->unwatchedInLibrary($type, 150));
        $sections = [
            "Giv mig {$limit} anbefalinger til {$kind}.",
            $wish ? "Mit ønske lige nu: {$wish}" : null,
            "## Set for nylig (sidste 90 dage)\n".($recent ? implode("\n", $recent) : '–'),
            "## Min smagsprofil (vægt 0-1, nyere visninger tæller mest)\n"
                .'Genrer: '.$fmt($summary['top_genres'])."\n"
                .'Instruktører: '.$fmt($summary['top_directors'])."\n"
                .'Skuespillere: '.$fmt($summary['top_actors'])."\n"
                .'Årtier: '.$fmt($summary['favorite_decades']),
            "## Det jeg har engageret mig mest i\n".(implode("\n", $top) ?: '–'),
            "## Usete {$kind} i mit bibliotek (kan foreslås)\n".(implode('; ', $unwatched) ?: '–'),
            "## Allerede set (foreslå IKKE disse)\n".(implode('; ', $seen) ?: '–'),
        ];

        return implode("\n\n", array_filter($sections));
    }

    /** Parse JSON from a model answer, tolerating code fences or text around it. */
    public static function decode(string $text): ?array
    {
        $text = trim($text);
        if (is_array($json = json_decode($text, true))) {
            return $json;
        }
        if (preg_match('~```(?:json)?\s*(.+?)```~s', $text, $m) && is_array($json = json_decode(trim($m[1]), true))) {
            return $json;
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end > $start && is_array($json = json_decode(substr($text, $start, $end - $start + 1), true))) {
            return $json;
        }

        return null;
    }

    protected function cacheKey(string $type, ?string $wish): string
    {
        return 'plex-recommender:claude:'.md5($type.'|'.mb_strtolower((string) $wish).'|'.$this->settings->claudeDriver());
    }
}
