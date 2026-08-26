<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Provenance;

use DateTimeImmutable;
use Illuminate\Http\Request;
use Padosoft\AiGuardrails\Firewall\FirewallQueryFilters;
use Padosoft\AiGuardrails\Support\IsoDateParser;

/**
 * Filters + keyset cursor for GET /provenance. Mirrors
 * {@see FirewallQueryFilters} — same
 * hardening (scalars read as string-or-null so a repeated/array param
 * cannot 500 a read-only endpoint, cursor strictly positive so "0" is
 * rejected rather than silently returning an empty page).
 *
 * `blocked` is the filter this endpoint adds and the one operators
 * actually reach for: during a monitor rollout the interesting rows are
 * the ones that would have been refused.
 */
final readonly class GatedToolCallFilters
{
    public function __construct(
        public ?string $principalId = null,
        public ?string $search = null,
        public ?bool $blocked = null,
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
        public int $limit = 50,
        public ?int $cursor = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $limit = self::str($request, 'limit');
        $cursor = self::str($request, 'cursor');
        $blocked = self::str($request, 'blocked');

        return new self(
            principalId: self::str($request, 'principal_id'),
            search: self::str($request, 'q'),
            // Only the two literal spellings mean anything; anything else
            // is "no filter" rather than a silent false, which would hide
            // exactly the rows an operator opened this page to see.
            blocked: match ($blocked) {
                '1', 'true' => true,
                '0', 'false' => false,
                default => null,
            },
            from: IsoDateParser::parseUtc($request->query('from')),
            to: IsoDateParser::parseUtc($request->query('to')),
            limit: $limit !== null && ctype_digit($limit) ? max(1, min(200, (int) $limit)) : 50,
            cursor: $cursor !== null && ctype_digit($cursor) && (int) $cursor > 0 ? (int) $cursor : null,
        );
    }

    private static function str(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
