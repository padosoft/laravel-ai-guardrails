<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Http\Requests;

/**
 * Sentinel returned by the settings coercion helpers when a value is malformed — distinguishable from
 * any valid coerced value (bool/string/int) so the request can collect per-key validation errors.
 */
final readonly class Invalid
{
    public function __construct(public string $message) {}
}
