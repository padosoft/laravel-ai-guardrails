<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Output;

use Closure;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Padosoft\AiGuardrails\Contracts\OutputSanitizer;
use Padosoft\AiGuardrails\Contracts\PiiRedaction;

/**
 * laravel/ai agent middleware (Control C). Treats the model response as untrusted: after the model
 * runs it rewrites `$response->text` in place — sanitize (HTML/markdown) then redact PII. Only the
 * text is rewritten; tool calls are governed by Controls A/D (documented limitation).
 */
final readonly class GuardrailOutputMiddleware
{
    public function __construct(
        private OutputSanitizer $sanitizer,
        private PiiRedaction $pii,
        private bool $enabled = true,
    ) {}

    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        if (! $this->enabled) {
            return $next($prompt);
        }

        $response = $next($prompt);

        if ($response instanceof AgentResponse) {
            $response->text = $this->pii->redact($this->sanitizer->sanitize($response->text));
        }

        return $response;
    }
}
