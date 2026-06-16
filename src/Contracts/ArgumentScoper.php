<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Contracts;

interface ArgumentScoper
{
    /**
     * Re-scope model-chosen arguments to the authenticated principal.
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function scope(array $arguments, int|string|null $principalId): array;
}
