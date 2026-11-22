<?php

namespace EmilMoe\PlexRecommender\Claude;

use Anthropic\Client;
use Throwable;

/**
 * Calls the Claude API with an API key from console.anthropic.com.
 * Optional fallback for when the app doesn't run next to Claude Code.
 * Requires: composer require anthropic-ai/sdk
 */
class AnthropicApiDriver implements ClaudeDriver
{
    public function __construct(
        protected string $apiKey,
        protected ?string $model = null,
    ) {}

    public static function available(): bool
    {
        return class_exists(Client::class);
    }

    public function label(): string
    {
        return 'Claude API';
    }

    public function ask(string $system, string $prompt, array $schema): string
    {
        if (! self::available()) {
            throw new ClaudeException('Claude API kræver pakken anthropic-ai/sdk. Kør: composer require anthropic-ai/sdk');
        }

        try {
            $client = new Client(apiKey: $this->apiKey);
            $message = $client->messages->create(
                model: $this->model ?: 'claude-opus-5-5',
                maxTokens: 16000,
                system: $system,
                messages: [['role' => 'user', 'content' => $prompt]],
                outputConfig: ['format' => ['type' => 'json_schema', 'schema' => $schema]],
            );
        } catch (Throwable $e) {
            throw new ClaudeException('Claude API fejlede: '.$e->getMessage(), previous: $e);
        }

        if ($message->stopReason === 'refusal') {
            throw new ClaudeException('Claude afviste forespørgslen. Prøv at omformulere dit ønske.');
        }
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                return $block->text;
            }
        }

        throw new ClaudeException('Claude API returnerede intet svar.');
    }
}
