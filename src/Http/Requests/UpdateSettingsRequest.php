<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Padosoft\AiGuardrails\Settings\OverridableSettings;

/**
 * Validates a settings mutation as UNTRUSTED input: the body `settings` must be an object of
 * dotted-path => value. Non-overridable keys are silently dropped (forward-compatible); a malformed
 * value for an overridable key is rejected (422). Only the sanitized allow-listed map reaches the store.
 */
final class UpdateSettingsRequest extends FormRequest
{
    private const REFUSAL_MESSAGE_MAX = 2000;

    /**
     * Type/enum spec per overridable key. 'bool' / 'string' / 'int', or a list of allowed enum values.
     *
     * @var array<string, string|list<string>>
     */
    private const TYPES = [
        'tool_firewall.enabled' => 'bool',
        'tool_firewall.reject_unknown_arguments' => 'bool',
        'input_screen.enabled' => 'bool',
        'input_screen.refusal_message' => 'string',
        'output_handler.enabled' => 'bool',
        'output_handler.sanitize_html' => 'bool',
        'output_handler.neutralize_markdown' => 'bool',
        'output_handler.redact_pii' => 'bool',
        'output_handler.html_mode' => ['escape', 'allowlist'],
        'hitl.enabled' => 'bool',
        'hitl.fallback' => ['deny', 'pass'],
        'modes.tool_firewall' => ['enforce', 'monitor', 'off'],
        'modes.input_screen' => ['enforce', 'monitor', 'off'],
        'modes.output_handler' => ['enforce', 'monitor', 'off'],
        'modes.hitl' => ['enforce', 'monitor', 'off'],
        'normalization.enabled' => 'bool',
        'pattern_safety.on_match_error' => ['closed', 'open'],
        'tool_authorization.enabled' => 'bool',
        'tool_authorization.owner_key_depth' => ['top_level', 'recursive'],
        'tool_authorization.destructive_match' => ['exact', 'substring'],
    ];

    public function authorize(): bool
    {
        // Authorization is enforced by the API route middleware group (ai-guardrails.api.middleware),
        // not here — this request only validates/shapes the body.
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['settings' => ['required', 'array']];
    }

    /**
     * The allow-list-filtered, type-coerced overrides. Throws a 422 ValidationException for a
     * malformed value on an overridable key.
     *
     * @return array<string,mixed>
     */
    public function sanitized(): array
    {
        /** @var array<mixed,mixed> $input */
        $input = (array) $this->input('settings', []);
        $overridable = OverridableSettings::keys();

        $clean = [];
        $errors = [];
        foreach ($input as $key => $value) {
            // Drop anything not on BOTH the config allow-list and the known-type map.
            if (! is_string($key) || ! in_array($key, $overridable, true) || ! isset(self::TYPES[$key])) {
                continue;
            }

            $spec = self::TYPES[$key];
            $coerced = is_array($spec) ? $this->coerceEnum($value, $spec) : $this->coerceScalar($value, $spec);

            if ($coerced instanceof Invalid) {
                $errors["settings.{$key}"] = $coerced->message;
            } else {
                $clean[$key] = $coerced;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    private function coerceScalar(mixed $value, string $type): mixed
    {
        return match ($type) {
            'bool' => $this->coerceBool($value),
            'int' => is_int($value) || (is_string($value) && filter_var($value, FILTER_VALIDATE_INT) !== false)
                ? (int) $value
                : new Invalid('must be an integer'),
            'string' => is_string($value) && mb_check_encoding($value, 'UTF-8') && mb_strlen($value, 'UTF-8') <= self::REFUSAL_MESSAGE_MAX
                ? $value
                : new Invalid('must be valid UTF-8 of at most '.self::REFUSAL_MESSAGE_MAX.' characters'),
            // Fail closed: an unknown/mistyped TYPES spec must reject the value, not accept it loosely.
            default => new Invalid('unsupported setting type'),
        };
    }

    private function coerceBool(mixed $value): bool|Invalid
    {
        $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $bool === null ? new Invalid('must be a boolean') : $bool;
    }

    /** @param list<string> $allowed */
    private function coerceEnum(mixed $value, array $allowed): string|Invalid
    {
        return is_string($value) && in_array($value, $allowed, true)
            ? $value
            : new Invalid('must be one of: '.implode(', ', $allowed));
    }
}
