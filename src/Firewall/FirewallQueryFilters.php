<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Firewall;

use DateTimeImmutable;
use Illuminate\Http\Request;
use Padosoft\AiGuardrails\Support\IsoDateParser;

/**
 * Filters + keyset-pagination cursor for the firewall rejection list endpoint (GET /firewall). The
 * cursor is the id of the last row seen on the previous page (rows are returned newest-first by id).
 */
final readonly class FirewallQueryFilters
{
    public function __construct(
        public ?string $principalId = null,
        public ?string $search = null,
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
        public int $limit = 50,
        public ?int $cursor = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        // Scalar params read as string-or-null so repeated/array params can't reach casts and 500
        // the read-only endpoint; from/to go through IsoDateParser which safely rejects non-strings.
        $limit = self::str($request, 'limit');
        $cursor = self::str($request, 'cursor');

        return new self(
            principalId: self::str($request, 'principal_id'),
            search: self::str($request, 'q'),
            from: IsoDateParser::parseUtc($request->query('from')),
            to: IsoDateParser::parseUtc($request->query('to')),
            limit: $limit !== null && ctype_digit($limit) ? max(1, min(200, (int) $limit)) : 50,
            // Cast then require strictly positive so "0"/"00" (both cast to 0 → empty page) are rejected.
            cursor: $cursor !== null && ctype_digit($cursor) && (int) $cursor > 0 ? (int) $cursor : null,
        );
    }

    private static function str(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
