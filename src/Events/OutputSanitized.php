<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Events;

use DateTimeImmutable;
use Padosoft\AiGuardrails\Output\OutputStatKind;

/**
 * Control C — the output handler neutralised something in a model response (HTML, markdown,
 * structured-field, or PII). Dispatched once per response when at least one neutralisation occurred,
 * from the same path that records the per-kind output stats. `$enforced=true` means the output
 * text was rewritten (enforce mode); `$enforced=false` means monitor mode — what WOULD have been
 * neutralised is recorded but the original text was returned unchanged.
 *
 * @see OutputStatKind for the possible kind values.
 */
final readonly class OutputSanitized
{
    /** @param  list<string>  $kinds  OutputStatKind values that were (or would have been) neutralised. */
    public function __construct(
        public array $kinds,
        public DateTimeImmutable $occurredAt,
        /** true = output was rewritten (enforce); false = monitor mode (output unchanged). */
        public bool $enforced,
    ) {}
}
