<?php

namespace App\Infrastructure\Ai;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Optional LLM layer for the AI agent. Talks to any OpenAI-compatible
 * /chat/completions endpoint. Inert unless an API key is configured, so the
 * knowledge-base agent works deterministically without it.
 */
final class OpenAiChatProvider
{
    public function isConfigured(): bool
    {
        return (bool) config('gateway.ai.llm.enabled', false)
            && filled(config('gateway.ai.llm.api_key'));
    }

    /**
     * Return a natural-language answer, or null when unavailable so the caller
     * can fall back to the raw knowledge-base entry.
     */
    public function answer(string $systemPrompt, string $userMessage): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::asJson()
                ->withToken((string) config('gateway.ai.llm.api_key'))
                ->timeout((int) config('gateway.ai.llm.timeout', 20))
                ->post((string) config('gateway.ai.llm.endpoint'), [
                    'model' => (string) config('gateway.ai.llm.model'),
                    'temperature' => 0.2,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $content = $response->json('choices.0.message.content');

        return is_string($content) && trim($content) !== '' ? trim($content) : null;
    }
}
