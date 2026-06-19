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
     * Scalar tags: 'bool' | 'string' | 'int' | 'int_positive' (>0) | 'int_nonneg' (>=0)
     *              | 'string_list' (array of non-empty strings) | 'regex_map' (map id => /u-compilable regex).
     * A list of strings is an enum of allowed values.
     *
     * @var array<string, string|list<string>>
     */
    private const TYPES = [
        'tool_firewall.enabled' => 'bool',
        'tool_firewall.reject_unknown_arguments' => 'bool',
        'tool_firewall.owner_keys' => 'string_list',
        'input_screen.enabled' => 'bool',
        'input_screen.refusal_message' => 'string',
        'input_screen.patterns' => 'regex_map',
        'output_handler.enabled' => 'bool',
        'output_handler.sanitize_html' => 'bool',
        'output_handler.neutralize_markdown' => 'bool',
        'output_handler.redact_pii' => 'bool',
        'output_handler.html_mode' => ['escape', 'allowlist'],
        'hitl.enabled' => 'bool',
        'hitl.fallback' => ['deny', 'pass'],
        'hitl.destructive_tools' => 'string_list',
        'modes.tool_firewall' => ['enforce', 'monitor', 'off'],
        'modes.input_screen' => ['enforce', 'monitor', 'off'],
        'modes.output_handler' => ['enforce', 'monitor', 'off'],
        'modes.hitl' => ['enforce', 'monitor', 'off'],
        'normalization.enabled' => 'bool',
        'normalization.nfkc' => 'bool',
        'normalization.strip_zero_width' => 'bool',
        'normalization.casefold' => 'bool',
        'normalization.decode_base64_blobs' => 'bool',
        'normalization.fold_confusables' => 'bool',
        'normalization.max_prompt_length' => 'int_positive',
        'pattern_safety.on_match_error' => ['closed', 'open'],
        'tool_authorization.enabled' => 'bool',
        'tool_authorization.owner_key_depth' => ['top_level', 'recursive'],
        'tool_authorization.destructive_match' => ['exact', 'substring'],
        'audit_hygiene.prompt_storage' => ['redact', 'hash', 'truncate', 'raw'],
        'retention.days' => 'int_nonneg',
        'retention.strategy' => ['anonymize', 'purge', 'keep'],
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
        return ['settings' => ['required', 'array', static function (string $attribute, mixed $value, \Closure $fail): void {
            // Reject a NON-EMPTY JSON list (numeric keys): it passes `array` but sanitized() would
            // silently ignore it, masking a client bug. An empty body is already rejected by the
            // `required` rule above (PHP decodes both `{}` and `[]` to []), so only the non-empty
            // list case needs guarding here.
            if (is_array($value) && $value !== [] && array_is_list($value)) {
                $fail('The settings field must be an object of dotted-key => value, not a list.');
            }
        }]];
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
            'int_positive' => $this->coerceIntRange($value, 1),
            'int_nonneg' => $this->coerceIntRange($value, 0),
            'string' => is_string($value) && mb_check_encoding($value, 'UTF-8') && mb_strlen($value, 'UTF-8') <= self::REFUSAL_MESSAGE_MAX
                ? $value
                : new Invalid('must be valid UTF-8 of at most '.self::REFUSAL_MESSAGE_MAX.' characters'),
            'string_list' => $this->coerceStringList($value),
            'regex_map' => $this->coerceRegexMap($value),
            // Fail closed: an unknown/mistyped TYPES spec must reject the value, not accept it loosely.
            default => new Invalid('unsupported setting type'),
        };
    }

    /**
     * Coerce an integer with an inclusive lower bound. Accepts a PHP int or its string form. Booleans
     * are NOT integers here (is_int(true) === false) so `true` can never sneak in as `1`.
     */
    private function coerceIntRange(mixed $value, int $min): int|Invalid
    {
        if (is_string($value) && filter_var($value, FILTER_VALIDATE_INT) !== false) {
            $value = (int) $value;
        }

        if (! is_int($value)) {
            return new Invalid('must be an integer');
        }

        return $value >= $min ? $value : new Invalid('must be an integer >= '.$min);
    }

    /**
     * Coerce a JSON list of non-empty strings (e.g. owner_keys, destructive_tools). Rejects a non-array,
     * a map (string keys), or any non-string / empty-string element. An empty list is accepted (clears it).
     *
     * @return list<string>|Invalid
     */
    private function coerceStringList(mixed $value): array|Invalid
    {
        if (! is_array($value)) {
            return new Invalid('must be an array of non-empty strings');
        }

        if ($value !== [] && ! array_is_list($value)) {
            return new Invalid('must be a JSON list (array), not an object');
        }

        foreach ($value as $item) {
            if (! is_string($item) || $item === '') {
                return new Invalid('each element must be a non-empty string');
            }
        }

        return $value;
    }

    /**
     * Coerce a map of rule_id => regex where EACH pattern must compile under the /u (Unicode) flag.
     * Security-critical: an invalid regex must never be stored (it would fail-closed at match time but
     * silently break a rule). Rejects a non-object, a list, an empty/non-string rule_id, or any pattern
     * that does not compile. An empty map is accepted (clears the override).
     *
     * @return array<string,string>|Invalid
     */
    private function coerceRegexMap(mixed $value): array|Invalid
    {
        if (! is_array($value)) {
            return new Invalid('must be an object mapping rule_id => regex pattern');
        }

        if ($value !== [] && array_is_list($value)) {
            return new Invalid('must be an object (string keys), not a list');
        }

        $coerced = [];
        foreach ($value as $ruleId => $pattern) {
            $ruleId = (string) $ruleId;
            if ($ruleId === '') {
                return new Invalid('rule_id keys must be non-empty strings');
            }
            if (! is_string($pattern)) {
                return new Invalid("pattern for rule '{$ruleId}' must be a string");
            }
            // A PCRE compile error makes preg_match return false (vs 0/1 for a successful match).
            if (@preg_match('/'.$pattern.'/u', '') === false) {
                return new Invalid("pattern for rule '{$ruleId}' is not a valid PCRE regex under the /u flag");
            }
            $coerced[$ruleId] = $pattern;
        }

        return $coerced;
    }

    private function coerceBool(mixed $value): bool|Invalid
    {
        if (is_bool($value)) {
            return $value;
        }

        // Strict: accept only canonical representations. Crucially this rejects "" (which
        // FILTER_VALIDATE_BOOLEAN would treat as false) so an empty string can't disable a guardrail.
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['true', '1'], true)) {
                return true;
            }
            if (in_array($normalized, ['false', '0'], true)) {
                return false;
            }
        }

        // Accept the integers 1/0 a JSON client may send as numbers. Strict === so null/floats and
        // other ints are NOT coerced (only the canonical 1/0 map to true/false).
        if ($value === 1) {
            return true;
        }
        if ($value === 0) {
            return false;
        }

        return new Invalid('must be a boolean (true/false/1/0)');
    }

    /** @param list<string> $allowed */
    private function coerceEnum(mixed $value, array $allowed): string|Invalid
    {
        return is_string($value) && in_array($value, $allowed, true)
            ? $value
            : new Invalid('must be one of: '.implode(', ', $allowed));
    }
}
