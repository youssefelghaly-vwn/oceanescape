<?php

namespace App\Support;

/**
 * Redaction for anything we write about a booking or a payment.
 *
 * Extracted from BookingAuditor so that the audit table and the `payments` log channel
 * cannot drift apart: one list of sensitive keys, one set of rules about what a non-scalar
 * value becomes. A second copy of this logic would eventually redact one key in one place
 * and not the other, which is the failure mode that puts a secret in a log file.
 *
 * WHAT MUST NEVER GET THROUGH
 * Card data (we never see any — hosted Stripe Checkout), Stripe secrets, webhook signing
 * secrets, full webhook payloads, raw API keys. Enforced by redacting anything whose KEY
 * looks sensitive rather than by inspecting values, because a value-based check cannot
 * tell a harmless number from a PAN.
 */
final class ScrubbedContext
{
    /** Substrings that redact a key wherever it appears, at any depth. */
    public const REDACT = [
        'secret', 'password', 'token', 'api_key', 'apikey', 'authorization',
        'card', 'cvc', 'cvv', 'number', 'signature', 'client_secret',
    ];

    /** How deep to walk before giving up. */
    private const MAX_DEPTH = 4;

    /**
     * @param  array<mixed>  $context
     * @return array<mixed>
     */
    public static function make(array $context, int $depth = 0): array
    {
        if ($depth > self::MAX_DEPTH) {
            return ['_truncated' => 'nesting too deep for an audit record'];
        }

        $out = [];

        foreach ($context as $key => $value) {
            if (self::isSensitive((string) $key)) {
                $out[$key] = '[redacted]';

                continue;
            }

            $out[$key] = match (true) {
                is_array($value) => self::make($value, $depth + 1),
                is_scalar($value), is_null($value) => $value,
                $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
                $value instanceof \Stringable => (string) $value,
                // Objects are summarised rather than serialised: a log line should never
                // become a dumping ground for a whole API response.
                default => '['.get_debug_type($value).']',
            };
        }

        return $out;
    }

    public static function isSensitive(string $key): bool
    {
        $needle = strtolower($key);

        foreach (self::REDACT as $bad) {
            if (str_contains($needle, $bad)) {
                return true;
            }
        }

        return false;
    }
}
