<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Contracts;

interface ArgumentScoper
{
    /**
     * Re-scope model-chosen arguments to the authenticated principal.
     *
     * @param  array<string,mixed>  $arguments
     * @param  array<string,string|array<int,string>|null>  $schemaTypes  Map of the tool's
     *                                                                    declared schema property names => declared JSON-schema type. When provided, owner-key
     *                                                                    scoping is restricted to keys the tool actually declares (so the firewall does not
     *                                                                    inject configured owner keys a given tool does not accept, which the schema validator
     *                                                                    would then reject as "unknown"), and the principal is coerced to the declared type.
     *                                                                    Empty = no schema context (scope all configured owner keys as strings).
     * @return array<string,mixed>
     */
    public function scope(array $arguments, int|string|null $principalId, array $schemaTypes = []): array;
}
