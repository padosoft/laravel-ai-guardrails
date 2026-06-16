<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Doubles;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * A tool whose owner key is an INTEGER and which has a NULLABLE (union) field, used to exercise
 * principal type-preservation and union-type validation in the firewall.
 */
final class FakeTypedTool implements Tool
{
    /** @var array<string,mixed>|null */
    public ?array $received = null;

    public function description(): Stringable|string
    {
        return 'Typed tool with an integer owner key.';
    }

    public function handle(Request $request): Stringable|string
    {
        $this->received = $request->toArray();

        return 'ok';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->integer()->required(), // integer owner key
            'note' => $schema->string()->nullable(),        // union ['string','null']
        ];
    }
}
