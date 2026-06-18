<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Output;

use Closure;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Padosoft\AiGuardrails\Contracts\OutputSanitizer;
use Padosoft\AiGuardrails\Contracts\OutputStatStore;
use Padosoft\AiGuardrails\Contracts\PiiRedaction;
use Padosoft\AiGuardrails\Contracts\ReportingOutputSanitizer;
use Padosoft\AiGuardrails\Support\ControlMode;

/**
 * laravel/ai agent middleware (Control C). Treats the model response as untrusted: after the model
 * runs it rewrites `$response->text` in place — sanitize (HTML/markdown) then redact PII. For a
 * StructuredAgentResponse it ALSO recursively sanitizes every string leaf of `$structured` (those
 * fields are model-produced and may be rendered in a UI). Tool calls are governed by Controls A/D
 * (documented limitation). Each neutralisation is counted via the OutputStatStore (GET /output/stats).
 *
 * Modes: `enforce` rewrites the output; `monitor` records the SAME would-sanitize stats (so an
 * operator can see what enforcement would neutralise) but returns the ORIGINAL text unchanged
 * (shadow rollout); `off` is pure pass-through (no sanitize, no stats).
 */
final readonly class GuardrailOutputMiddleware
{
    public function __construct(
        private OutputSanitizer $sanitizer,
        private PiiRedaction $pii,
        private bool $enabled = true,
        private ?OutputStatStore $stats = null,
        private ?ControlMode $mode = null,
    ) {}

    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        // Explicit mode wins; otherwise the legacy `enabled` flag maps true→enforce, false→off.
        $mode = $this->mode ?? ($this->enabled ? ControlMode::Enforce : ControlMode::Off);

        // Control off → true pass-through: no sanitization, no stats.
        if (! $mode->isActive()) {
            return $next($prompt);
        }

        $response = $next($prompt);

        // enforce → apply the cleaned text; monitor → count what WOULD change but keep the original.
        $apply = $mode->enforces();

        if ($response instanceof AgentResponse) {
            $response->text = $this->clean($response->text, $apply);

            // StructuredAgentResponse extends AgentResponse, so $text above is already handled; its
            // structured fields are additional untrusted model output and must be cleaned too.
            if ($response instanceof StructuredAgentResponse) {
                $response->structured = $this->cleanStructured($response->structured, $apply);
            }
        }

        return $response;
    }

    /**
     * Compute the sanitized text and record per-kind stats for everything that changed. Returns the
     * cleaned text when $apply (enforce) or the ORIGINAL untouched text when not (monitor) — the stats
     * are recorded either way so monitor mode reports exactly what enforcement would have done.
     */
    private function clean(string $text, bool $apply): string
    {
        // Use the reporting sanitizer when available so html/markdown neutralisations can be counted
        // separately; otherwise fall back to plain sanitize() (no per-kind stats).
        if ($this->sanitizer instanceof ReportingOutputSanitizer) {
            $report = $this->sanitizer->sanitizeReport($text);
            if ($report->htmlChanged) {
                $this->recordStat(OutputStatKind::HtmlStripped);
            }
            if ($report->markdownChanged) {
                $this->recordStat(OutputStatKind::MarkdownSanitized);
            }
            $sanitized = $report->text;
        } else {
            $sanitized = $this->sanitizer->sanitize($text);
        }

        $redacted = $this->pii->redact($sanitized);
        if ($redacted !== $sanitized) {
            $this->recordStat(OutputStatKind::PiiRedaction);
        }

        return $apply ? $redacted : $text;
    }

    /**
     * Fire-and-forget stat write: a store failure (DB down, etc.) must never abort the sanitization
     * pass — that would leave model output un-neutralised. Log instead of propagating.
     */
    private function recordStat(OutputStatKind $kind): void
    {
        if ($this->stats === null) {
            return;
        }

        try {
            $this->stats->record($kind);
        } catch (\Throwable $e) {
            Log::warning('laravel-ai-guardrails: failed to record an output stat.', [
                'kind' => $kind->value,
                'exception' => $e,
            ]);
        }
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function cleanStructured(array $data, bool $apply): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->cleanStructured($value, $apply);
            } elseif (is_string($value)) {
                $data[$key] = $this->clean($value, $apply);
            }
        }

        return $data;
    }
}
