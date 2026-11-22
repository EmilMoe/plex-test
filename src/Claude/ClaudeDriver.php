<?php

namespace EmilMoe\PlexRecommender\Claude;

/** Something that can send a prompt to Claude and return the text answer. */
interface ClaudeDriver
{
    /**
     * @param  array<string, mixed>  $schema  JSON schema the answer must follow
     *
     * @throws ClaudeException
     */
    public function ask(string $system, string $prompt, array $schema): string;

    /** Short Danish label for the UI, e.g. "Claude Code". */
    public function label(): string;
}
