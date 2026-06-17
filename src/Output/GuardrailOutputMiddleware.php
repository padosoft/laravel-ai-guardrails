<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Output;

use Closure;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Padosoft\AiGuardrails\Contracts\OutputSanitizer;
use Padosoft\AiGuardrails\Contracts\PiiRedaction;

/**
 * laravel/ai agent middleware (Control C). Treats the model response as untrusted: after the model
 * runs it rewrites `$response->text` in place — sanitize (HTML/markdown) then redact PII. For a
 * StructuredAgentResponse it ALSO recursively sanitizes every string leaf of `$structured` (those
 * fields are model-produced and may be rendered in a UI). Tool calls are governed by Controls A/D
 * (documented limitation).
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
            $response->text = $this->clean($response->text);

            // StructuredAgentResponse extends AgentResponse, so $text above is already handled; its
            // structured fields are additional untrusted model output and must be cleaned too.
            if ($response instanceof StructuredAgentResponse) {
                $response->structured = $this->cleanStructured($response->structured);
            }
        }

        return $response;
    }

    private function clean(string $text): string
    {
        return $this->pii->redact($this->sanitizer->sanitize($text));
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function cleanStructured(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->cleanStructured($value);
            } elseif (is_string($value)) {
                $data[$key] = $this->clean($value);
            }
        }

        return $data;
    }
}
