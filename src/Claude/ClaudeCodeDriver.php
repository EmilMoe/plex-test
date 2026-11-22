<?php

namespace EmilMoe\PlexRecommender\Claude;

use Illuminate\Support\Facades\Process;

/**
 * Runs the locally installed Claude Code CLI in headless mode (`claude -p`).
 * Uses the user's own Claude login, so no API key is needed – but it only
 * works when the app runs on the same machine as Claude Code.
 */
class ClaudeCodeDriver implements ClaudeDriver
{
    public function __construct(
        protected string $binary = 'claude',
        protected ?string $model = null,
        protected int $timeout = 240,
        protected ?string $workingDirectory = null,
    ) {}

    public function label(): string
    {
        return 'Claude Code';
    }

    public function ask(string $system, string $prompt, array $schema): string
    {
        $binary = $this->resolveBinary();

        $command = [$binary, '-p', '--output-format', 'json', '--append-system-prompt', $system];
        if ($this->model) {
            array_push($command, '--model', $this->model);
        }

        // The schema goes into the prompt; the prompt itself is sent on stdin
        // so long profiles don't hit command line length limits.
        $input = $prompt."\n\nSvar KUN med JSON (ingen forklaring udenom) der følger dette JSON-schema:\n"
            .json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $dir = $this->workingDirectory ?? storage_path('app/plex-recommender-claude');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $result = Process::path($dir)
            ->timeout($this->timeout)
            ->input($input)
            ->run($command);

        $out = trim($result->output());
        $json = json_decode($out, true);

        if (! $result->successful() && ! is_array($json)) {
            $err = trim($result->errorOutput()) ?: $out;
            throw new ClaudeException($this->explain($err, $result->exitCode()));
        }
        if (! is_array($json)) {
            throw new ClaudeException('Uventet svar fra Claude Code: '.mb_substr($out, 0, 300));
        }
        if (! empty($json['is_error'])) {
            throw new ClaudeException($this->explain((string) ($json['result'] ?? $json['subtype'] ?? 'ukendt fejl'), $result->exitCode()));
        }

        return (string) ($json['result'] ?? '');
    }

    /** Find the claude binary even when the web server's PATH is minimal. */
    public function resolveBinary(): string
    {
        if (str_contains($this->binary, '/') || str_contains($this->binary, '\\')) {
            if (! is_file($this->binary)) {
                throw new ClaudeException("Claude Code blev ikke fundet på {$this->binary}. Ret stien under Plex-indstillinger → Claude.");
            }

            return $this->binary;
        }

        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? null);
        $dirs = array_filter(array_merge(
            explode(PATH_SEPARATOR, (string) getenv('PATH')),
            [
                $home ? "{$home}/.claude/local" : null,
                $home ? "{$home}/.local/bin" : null,
                $home ? "{$home}/.npm-global/bin" : null,
                $home ? "{$home}/.bun/bin" : null,
                '/opt/homebrew/bin',
                '/usr/local/bin',
                '/usr/bin',
            ],
        ));
        foreach ($dirs as $dir) {
            foreach ([$this->binary, $this->binary.'.exe', $this->binary.'.cmd'] as $name) {
                $path = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.$name;
                if (is_file($path) && is_executable($path)) {
                    return $path;
                }
            }
        }

        throw new ClaudeException(
            'Kunne ikke finde Claude Code ("'.$this->binary.'"). Kør `which claude` i en terminal og indsæt stien under Plex-indstillinger → Claude.'
        );
    }

    protected function explain(string $error, ?int $exitCode): string
    {
        $lower = mb_strtolower($error);

        return match (true) {
            str_contains($lower, 'login') || str_contains($lower, 'auth') || str_contains($lower, 'api key')
                => 'Claude Code er ikke logget ind for den bruger, webserveren kører som. Kør `claude` i en terminal som samme bruger og log ind. ('.mb_substr($error, 0, 200).')',
            str_contains($lower, 'timed out') || str_contains($lower, 'timeout')
                => 'Claude Code svarede ikke i tide. Prøv igen.',
            default => 'Claude Code fejlede'.($exitCode !== null ? " (kode {$exitCode})" : '').': '.mb_substr($error, 0, 300),
        };
    }
}
