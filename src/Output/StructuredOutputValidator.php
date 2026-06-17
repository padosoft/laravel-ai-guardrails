<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Output;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;

/**
 * Validates a model's structured output (a decoded key/value array) against an expected schema
 * BEFORE the application acts on it — the model response is untrusted. Deterministic; no LLM.
 * Mirrors the firewall's schema validation (required keys, declared types, union/nullable types).
 */
final readonly class StructuredOutputValidator
{
    /**
     * @param  array<string,mixed>  $output
     * @param  array<string,Type>  $schema  laravel/ai Type map (argument-name => Type)
     * @return array<string,string> field => violation message (empty = valid)
     */
    public function validate(array $output, array $schema): array
    {
        $factory = new JsonSchemaTypeFactory;
        $shape = $factory->object($schema)->toArray();

        /** @var array<string,array<string,mixed>> $properties */
        $properties = is_array($shape['properties'] ?? null) ? $shape['properties'] : [];
        /** @var list<string> $required */
        $required = is_array($shape['required'] ?? null) ? array_values($shape['required']) : [];

        $errors = [];

        foreach ($required as $key) {
            if (! array_key_exists($key, $output)) {
                $errors[$key] = "Missing required field [{$key}].";
            }
        }

        foreach ($properties as $key => $definition) {
            if (! array_key_exists($key, $output)) {
                continue;
            }

            $expected = $definition['type'] ?? null;
            $value = $output[$key];

            if (is_string($expected)) {
                if (! $this->matchesType($expected, $value)) {
                    $errors[$key] = "Field [{$key}] must be of type [{$expected}].";
                }
            } elseif (is_array($expected) && $expected !== []) {
                $matchesAny = false;
                foreach ($expected as $member) {
                    if (is_string($member) && $this->matchesType($member, $value)) {
                        $matchesAny = true;
                        break;
                    }
                }
                if (! $matchesAny) {
                    $errors[$key] = "Field [{$key}] must be one of types [".implode('|', array_map('strval', $expected)).'].';
                }
            }
        }

        return $errors;
    }

    private function matchesType(string $expected, mixed $value): bool
    {
        return match ($expected) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
            'null' => $value === null,
            default => false,
        };
    }
}
