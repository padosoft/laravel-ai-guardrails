<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Provenance;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Padosoft\AiGuardrails\Contracts\GroundingProvenance;
use Padosoft\AiGuardrails\Events\UntrustedGroundingToolGated;
use Padosoft\AiGuardrails\Hitl\ApprovalGatedTool;
use Padosoft\AiGuardrails\Support\ControlMode;
use Stringable;

/**
 * Decorator that refuses a tool call made while the model was reading
 * material nobody here wrote.
 *
 * The threat is indirect prompt injection, and its shape is worth naming
 * exactly, because the familiar version understates it. Nobody has to
 * jailbreak the model. Somebody sends an email, or edits a shared doc, or
 * files a support ticket. It gets indexed. Later, unrelated to them, a user
 * asks a question, the retrieval layer pulls that paragraph in as
 * grounding, and the paragraph contains instructions. The model — which has
 * no way to distinguish the document it was asked to summarise from the
 * operator who asked — follows them.
 *
 * Control D ({@see ApprovalGatedTool}) already
 * parks tools an operator listed as destructive. This one is the other
 * axis: **not which tool, but what the model was reading when it decided to
 * call it.** A tool nobody thought was dangerous becomes dangerous when the
 * thing choosing its arguments is a stranger's text.
 *
 * Why this is not just a better injection screener: a screener reads the
 * text and guesses. Every pattern list is a list of the attacks somebody
 * already thought of, and the attacker gets to read it. Provenance is a
 * fact recorded at ingest — *this came from a mailbox* — with nothing to
 * evade. It also catches the case a screener cannot: perfectly innocuous
 * text that steers a decision without ever looking like an instruction.
 *
 * Modes:
 * - `enforce` — refuse the call and tell the model why, in terms it can
 *   relay to the user.
 * - `monitor` — run the delegate, emit the event. Shadow rollout: the
 *   event stream is identical to enforcement, so the impact can be sized
 *   before the switch is flipped.
 * - `off`     — never wrapped.
 *
 * @api
 */
final readonly class ProvenanceGatedTool implements Tool
{
    /**
     * @param  Closure():(int|string|null)  $principalResolver
     * @param  list<ProvenanceTier>  $gatingTiers  tiers that trigger the gate
     */
    public function __construct(
        private Tool $delegate,
        private GroundingProvenance $provenance,
        private Closure $principalResolver,
        private string $toolName,
        private array $gatingTiers,
        private ControlMode $mode = ControlMode::Enforce,
        private ?Dispatcher $events = null,
    ) {}

    public function description(): Stringable|string
    {
        return $this->delegate->description();
    }

    public function handle(Request $request): Stringable|string
    {
        $offending = $this->offendingTiers();

        if ($offending === []) {
            return $this->delegate->handle($request);
        }

        $blocked = $this->mode->enforces();

        $this->events?->dispatch(new UntrustedGroundingToolGated(
            $this->toolName,
            ($this->principalResolver)(),
            array_map(static fn (ProvenanceTier $tier): string => $tier->value, $offending),
            $blocked,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        ));

        if (! $blocked) {
            return $this->delegate->handle($request);
        }

        // Addressed to the model so it can relay a useful sentence, and
        // deliberately vague about WHICH source: naming the document would
        // let an attacker confirm their content is in the corpus, which is
        // a free oracle for tuning the next attempt.
        return "This action [{$this->toolName}] was not performed: it was requested while reading "
            .'content from outside this organisation, and actions grounded in external content '
            .'require a person to confirm them.';
    }

    /**
     * @return list<ProvenanceTier>
     */
    private function offendingTiers(): array
    {
        $present = $this->provenance->tiers();

        return array_values(array_filter(
            $present,
            fn (ProvenanceTier $tier): bool => in_array($tier, $this->gatingTiers, true),
        ));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->delegate->schema($schema);
    }
}
