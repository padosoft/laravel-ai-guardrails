<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Padosoft\AiGuardrails\Contracts\ApprovalRouter;
use Padosoft\AiGuardrails\Contracts\ArgumentScoper;
use Padosoft\AiGuardrails\Contracts\FirewallRejectionStore;
use Padosoft\AiGuardrails\Contracts\InjectionScreener;
use Padosoft\AiGuardrails\Contracts\OutputSanitizer;
use Padosoft\AiGuardrails\Contracts\OutputStatStore;
use Padosoft\AiGuardrails\Contracts\PiiRedaction;
use Padosoft\AiGuardrails\Contracts\ToolArgumentValidator;
use Padosoft\AiGuardrails\Firewall\FirewalledTool;
use Padosoft\AiGuardrails\Hitl\ApprovalGatedTool;
use Padosoft\AiGuardrails\Output\OutputStatKind;
use Padosoft\AiGuardrails\Output\StructuredOutputValidator;
use Padosoft\AiGuardrails\Screening\ScreenVerdict;
use Padosoft\AiGuardrails\Support\ControlMode;

/**
 * The package's single PHP entry point (Facade-backed). Composes the four controls: screen() &
 * sanitize() are deterministic; guard() wraps a tool with the firewall (Control A); routeForApproval()
 * wraps a destructive tool with the HITL bridge (Control D); validateStructured() validates structured
 * model output (Control C). When the master kill-switch is off, the wrappers return the tool untouched.
 */
final readonly class AiGuardrails
{
    /**
     * @param  list<string>  $destructiveTools
     * @param  (Closure():(int|string|null))|null  $principalResolver
     */
    public function __construct(
        private InjectionScreener $screener,
        private OutputSanitizer $sanitizer,
        private PiiRedaction $pii,
        private ArgumentScoper $scoper,
        private ToolArgumentValidator $validator,
        private ApprovalRouter $router,
        private bool $enabled = true,
        private bool $hitlEnabled = false,
        private array $destructiveTools = [],
        private string $hitlFallback = 'deny',
        private string $destructiveMatch = 'exact',
        private ?Closure $principalResolver = null,
        private ?FirewallRejectionStore $firewallRejectionStore = null,
        private ?OutputStatStore $outputStatStore = null,
        private ?ControlMode $firewallMode = null,
        private ?ControlMode $hitlMode = null,
        private ?Dispatcher $events = null,
    ) {}

    public function screen(string $prompt): ScreenVerdict
    {
        return $this->screener->screen($prompt);
    }

    public function sanitize(string $text): string
    {
        return $this->pii->redact($this->sanitizer->sanitize($text));
    }

    /**
     * Wrap a tool with the firewall (re-scope owner keys + validate args). No-op when the control is
     * off; in `monitor` the wrapper re-scopes + records rejections but does not throw (shadow rollout).
     *
     * @param  (Closure():(int|string|null))|null  $principalResolver
     */
    public function guard(Tool $tool, ?Closure $principalResolver = null): Tool
    {
        if (! $this->enabled) {
            return $tool;
        }

        // Back-compat: when no explicit mode was wired (direct construction), enforce.
        $mode = $this->firewallMode ?? ControlMode::Enforce;
        if (! $mode->isActive()) {
            return $tool;
        }

        return new FirewalledTool($tool, $this->scoper, $this->validator, $this->resolver($principalResolver), $this->firewallRejectionStore, $mode, $this->events);
    }

    /**
     * Wrap a destructive tool with the HITL approval bridge. No-op when the control is off; in
     * `monitor` the wrapper runs the delegate directly (observe, do not park).
     *
     * @param  (Closure():(int|string|null))|null  $principalResolver
     */
    public function routeForApproval(Tool $tool, string $toolName, ?Closure $principalResolver = null): Tool
    {
        if (! $this->enabled) {
            return $tool;
        }

        // Explicit mode wins; otherwise derive from the legacy hitl.enabled flag (true→enforce). No
        // gating when off (otherwise a 'deny' fallback would wrongly block an un-gated tool).
        $mode = $this->hitlMode ?? ($this->hitlEnabled ? ControlMode::Enforce : ControlMode::Off);
        if (! $mode->isActive()) {
            return $tool;
        }

        // Narrow to the literal union; any value other than 'pass' fails safe to 'deny'.
        $fallback = $this->hitlFallback === 'pass' ? 'pass' : 'deny';

        return new ApprovalGatedTool($tool, $this->router, $this->resolver($principalResolver), $toolName, $fallback, $mode, $this->events);
    }

    /**
     * Whether a tool name is treated as destructive per config (exact or substring match).
     */
    public function isDestructive(string $toolName): bool
    {
        foreach ($this->destructiveTools as $destructive) {
            $matches = $this->destructiveMatch === 'substring'
                ? str_contains($toolName, $destructive)
                : $toolName === $destructive;

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $output
     * @param  array<string,Type>  $schema
     * @return array<string,string> field => violation message (empty = valid)
     */
    public function validateStructured(array $output, array $schema, bool $rejectUnknown = false): array
    {
        if (! $this->enabled) {
            return []; // master kill-switch off → no validation (pass-through)
        }

        $violations = (new StructuredOutputValidator($rejectUnknown))->validate($output, $schema);
        if ($violations !== [] && $this->outputStatStore !== null) {
            // Fire-and-forget: a stat-store failure must not break validation for the caller.
            try {
                $this->outputStatStore->record(OutputStatKind::StructuredValidationFailure);
            } catch (\Throwable $e) {
                Log::warning('laravel-ai-guardrails: failed to record a structured-validation stat.', [
                    'exception' => $e,
                ]);
            }
        }

        return $violations;
    }

    /**
     * @param  (Closure():(int|string|null))|null  $override
     * @return Closure():(int|string|null)
     */
    private function resolver(?Closure $override): Closure
    {
        return $override ?? $this->principalResolver ?? static fn (): null => null;
    }
}
