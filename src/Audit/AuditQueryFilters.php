<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Audit;

use DateTimeImmutable;
use Illuminate\Http\Request;
use Padosoft\AiGuardrails\Support\IsoDateParser;

/**
 * Filters + keyset-pagination cursor for the audit list endpoint (GET /audit). The cursor is the
 * id of the last row seen on the previous page (rows are returned newest-first by id).
 */
final readonly class AuditQueryFilters
{
    public function __construct(
        public ?bool $blocked = null,
        public ?string $ruleId = null,
        public ?string $principalId = null,
        public ?string $search = null,
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
        public int $limit = 50,
        public ?int $cursor = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        // Scalar params are read as string-or-null: repeated params (e.g. `blocked[]=true`,
        // `limit[]=10`) make query() return an array, which must NOT reach filter_var()/casts and
        // turn this read-only endpoint into a 500. (from/to below are read raw but IsoDateParser
        // safely rejects any non-string/array input.)
        $blocked = self::str($request, 'blocked');
        $limit = self::str($request, 'limit');
        $cursor = self::str($request, 'cursor');

        return new self(
            blocked: $blocked === null ? null : filter_var($blocked, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ruleId: self::str($request, 'rule_id'),
            principalId: self::str($request, 'principal_id'),
            search: self::str($request, 'q'),
            from: IsoDateParser::parseUtc($request->query('from')),
            to: IsoDateParser::parseUtc($request->query('to')),
            limit: $limit !== null && ctype_digit($limit) ? max(1, min(200, (int) $limit)) : 50,
            // The cursor is a monotonic positive id; only accept a plain positive integer (rejects
            // "-1", "1e3", "0", arrays). ctype_digit also bounds the string to digits only.
            cursor: $cursor !== null && ctype_digit($cursor) && $cursor !== '0' ? (int) $cursor : null,
        );
    }

    private static function str(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
