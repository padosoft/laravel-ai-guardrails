<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Firewall;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Contracts\Tool;
use Padosoft\AiGuardrails\Contracts\ToolArgumentValidator;

/**
 * Deterministic, offline validation of model-chosen tool arguments against the tool's own
 * JSON schema. Untrusted-input posture: required keys must be present, declared types must
 * match, and (optionally) keys not declared in the schema are rejected.
 *
 * The schema shape is read via the public laravel/ai API: `Tool::schema()` returns a map of
 * argument-name => Type; wrapping that map in `object()->toArray()` yields a JSON-schema
 * fragment whose `properties` carry each leaf `type` and whose top-level `required` lists the
 * required argument names (the leaf `toArray()` does NOT carry `required` in laravel/ai v0.8).
 */
final readonly class SchemaToolArgumentValidator implements ToolArgumentValidator
{
    public function __construct(private bool $rejectUnknown = true) {}

    public function validate(Tool $tool, array $arguments): array
    {
        $factory = new JsonSchemaTypeFactory;
        $schema = $factory->object($tool->schema($factory))->toArray();

        /** @var array<string,array<string,mixed>> $properties */
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        /** @var list<string> $required */
        $required = is_array($schema['required'] ?? null) ? array_values($schema['required']) : [];

        $errors = [];

        foreach ($required as $key) {
            if (! array_key_exists($key, $arguments)) {
                $errors[$key] = "Missing required argument [{$key}].";
            }
        }

        foreach ($properties as $key => $definition) {
            if (! array_key_exists($key, $arguments)) {
                continue;
            }

            $expected = is_string($definition['type'] ?? null) ? $definition['type'] : null;
            if ($expected !== null && ! $this->matchesType($expected, $arguments[$key])) {
                $errors[$key] = "Argument [{$key}] must be of type [{$expected}].";
            }
        }

        if ($this->rejectUnknown) {
            foreach (array_keys($arguments) as $key) {
                if (! array_key_exists($key, $properties)) {
                    $errors[$key] = "Unknown argument [{$key}] is not declared in the tool schema.";
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
            'array' => is_array($value),
            'object' => is_array($value),
            default => false, // unknown schema type → reject (fail-closed; prevents bypass on schema extensions)
        };
    }
}
