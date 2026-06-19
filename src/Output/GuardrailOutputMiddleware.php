<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Output;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Padosoft\AiGuardrails\Contracts\OutputSanitizer;
use Padosoft\AiGuardrails\Contracts\OutputStatStore;
use Padosoft\AiGuardrails\Contracts\PiiRedaction;
use Padosoft\AiGuardrails\Contracts\ReportingOutputSanitizer;
use Padosoft\AiGuardrails\Contracts\ReportingPiiRedaction;
use Padosoft\AiGuardrails\Events\OutputSanitized;
use Padosoft\AiGuardrails\Support\ControlMode;

/**
 * laravel/ai agent middleware (Control C). Treats the model response as untrusted: after the model
 * runs it rewrites `$response->text` in place — sanitize (HTML/markdown) then redact PII. For a
 * StructuredAgentResponse it ALSO recursively sanitizes every string leaf of `$structured` (those
 * fields are model-produced and may be rendered in a UI). Tool calls are primarily governed by
 * Controls A/D; an OPT-IN `sanitize_tool_calls` flag (default off) additionally cleans their string
 * arguments as defense-in-depth. Each neutralisation is counted via the OutputStatStore (GET /output/stats).
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
        private ?Dispatcher $events = null,
        private bool $sanitizeToolCalls = false,
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

        // Accumulate every neutralised kind across text + structured so a single domain event is
        // dispatched per response (the per-kind stats are still recorded individually by the store).
        $kinds = [];

        if ($response instanceof AgentResponse) {
            $response->text = $this->clean($response->text, $apply, $kinds);

            // StructuredAgentResponse extends AgentResponse, so $text above is already handled; its
            // structured fields are additional untrusted model output and must be cleaned too.
            if ($response instanceof StructuredAgentResponse) {
                $response->structured = $this->cleanStructured($response->structured, $apply, $kinds);
            }

            // L3 (opt-in, default off): defense-in-depth over the model's tool-call arguments. Tool
            // calls are normally executed and governed by Controls A/D, so this is only for hosts that
            // render/log the arguments; in monitor mode ($apply false) the leaves are left unchanged.
            // $response->toolCalls is always a Collection (initialised by TextResponse).
            if ($this->sanitizeToolCalls) {
                $response->toolCalls = $this->cleanToolCalls($response->toolCalls, $apply, $kinds);
            }
        }

        // One event per response when at least one neutralisation occurred (deduped kinds), from the
        // same path that recorded the stats. $enforced mirrors $apply: true = text was rewritten;
        // false = monitor mode (kinds reflect would-be enforcement, output is unchanged).
        if ($kinds !== []) {
            $this->events?->dispatch(new OutputSanitized(
                array_values(array_unique($kinds)),
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
                $apply,
            ));
        }

        return $response;
    }

    /**
     * Compute the sanitized text and record per-kind stats for everything that changed. Returns the
     * cleaned text when $apply (enforce) or the ORIGINAL untouched text when not (monitor) — the stats
     * are recorded either way so monitor mode reports exactly what enforcement would have done.
     *
     * @param  list<string>  $kinds  accumulator (by ref): every neutralised OutputStatKind value is appended.
     */
    private function clean(string $text, bool $apply, array &$kinds): string
    {
        // Use the reporting sanitizer when available so html/markdown neutralisations can be counted
        // separately; otherwise fall back to plain sanitize() (no per-kind stats).
        if ($this->sanitizer instanceof ReportingOutputSanitizer) {
            $report = $this->sanitizer->sanitizeReport($text);
            if ($report->htmlChanged) {
                $this->recordStat(OutputStatKind::HtmlStripped, $kinds);
            }
            if ($report->markdownChanged) {
                $this->recordStat(OutputStatKind::MarkdownSanitized, $kinds);
            }
            $sanitized = $report->text;
        } else {
            $sanitized = $this->sanitizer->sanitize($text);
        }

        // When the pii-redactor implements ReportingPiiRedaction, use redactReport() to capture
        // per-detector counts; otherwise fall back to plain redact() (no detector breakdown).
        if ($this->pii instanceof ReportingPiiRedaction) {
            $report = $this->pii->redactReport($sanitized);
            $redacted = $report->text;
            // Gate on actual text change so stats reflect real neutralisations (not detector reports
            // on text that is ultimately unchanged).
            if ($redacted !== $sanitized) {
                // pii_redaction is appended to $kinds exactly ONCE per text, regardless of how many
                // detectors fired. Per-detector store rows are written directly to skip the $kinds
                // accumulation path in recordStat().
                $kinds[] = OutputStatKind::PiiRedaction->value;
                if ($report->countsByDetector !== []) {
                    // One store row per detector.
                    foreach ($report->countsByDetector as $detector => $n) {
                        $this->recordStatDirect(OutputStatKind::PiiRedaction, $n, $detector);
                    }
                } else {
                    // Text changed but no detector attribution → one aggregate row with detector=null
                    // so pii_redaction total stays correct; by_detector silently omits it.
                    $this->recordStatDirect(OutputStatKind::PiiRedaction);
                }
            }
        } else {
            $redacted = $this->pii->redact($sanitized);
            if ($redacted !== $sanitized) {
                $this->recordStat(OutputStatKind::PiiRedaction, $kinds);
            }
        }

        return $apply ? $redacted : $text;
    }

    /**
     * Fire-and-forget stat write: a store failure (DB down, etc.) must never abort the sanitization
     * pass — that would leave model output un-neutralised. Log instead of propagating. The kind is
     * appended to the accumulator regardless of store success so the domain event stays accurate.
     *
     * @param  list<string>  $kinds  accumulator (by ref)
     */
    private function recordStat(OutputStatKind $kind, array &$kinds, int $count = 1, ?string $detector = null): void
    {
        $kinds[] = $kind->value;

        if ($this->stats === null) {
            return;
        }

        try {
            $this->stats->record($kind, $count, $detector);
        } catch (\Throwable $e) {
            Log::warning('laravel-ai-guardrails: failed to record an output stat.', [
                'kind' => $kind->value,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Write a stat row directly to the store WITHOUT touching the $kinds accumulator. Used for
     * per-detector PiiRedaction rows so that pii_redaction appears in $kinds exactly once per text
     * while the store still captures the per-detector breakdown.
     */
    private function recordStatDirect(OutputStatKind $kind, int $count = 1, ?string $detector = null): void
    {
        if ($this->stats === null) {
            return;
        }

        try {
            $this->stats->record($kind, $count, $detector);
        } catch (\Throwable $e) {
            Log::warning('laravel-ai-guardrails: failed to record an output stat.', [
                'kind' => $kind->value,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Sanitize the string leaves of each tool call's arguments. Each item is a ToolCall (production)
     * whose `$arguments` array is cleaned in place, or a plain array (lightweight callers) cleaned
     * structurally. Non-string/non-array leaves and unrecognised item shapes are left untouched.
     *
     * @param  Collection<array-key,mixed>  $toolCalls
     * @param  list<string>  $kinds  accumulator (by ref)
     * @return Collection<array-key,mixed>
     */
    private function cleanToolCalls(Collection $toolCalls, bool $apply, array &$kinds): Collection
    {
        return $toolCalls->map(function (mixed $call) use ($apply, &$kinds): mixed {
            if ($call instanceof ToolCall) {
                $cleaned = $this->cleanStructured($call->arguments, $apply, $kinds);
                // In monitor mode ($apply false) stats are recorded above but the object must not
                // be mutated — skip the assignment so the original arguments array is preserved.
                if ($apply) {
                    $call->arguments = $cleaned;
                }

                return $call;
            }

            if (is_array($call)) {
                return $this->cleanStructured($call, $apply, $kinds);
            }

            return $call;
        });
    }

    /**
     * @param  array<mixed>  $data
     * @param  list<string>  $kinds  accumulator (by ref)
     * @return array<mixed>
     */
    private function cleanStructured(array $data, bool $apply, array &$kinds): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->cleanStructured($value, $apply, $kinds);
            } elseif (is_string($value)) {
                $data[$key] = $this->clean($value, $apply, $kinds);
            }
        }

        return $data;
    }
}
