<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Events;

use DateTimeImmutable;
use Padosoft\AiGuardrails\Output\OutputStatKind;

/**
 * Control C — the output handler neutralised something in a model response (HTML, markdown,
 * structured-field, or PII). Dispatched once per response when at least one neutralisation occurred,
 * from the same path that records the per-kind output stats. In monitor mode the kinds reflect what
 * enforcement WOULD have neutralised (the returned text is left unchanged).
 *
 * @see OutputStatKind for the possible kind values.
 */
final readonly class OutputSanitized
{
    /** @param  list<string>  $kinds  OutputStatKind values that were neutralised in this response. */
    public function __construct(
        public array $kinds,
        public DateTimeImmutable $occurredAt,
    ) {}
}
