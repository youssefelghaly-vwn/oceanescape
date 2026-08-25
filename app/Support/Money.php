<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Money, in minor units (cents).
 *
 * WHY THIS EXISTS
 * Lodgify hands back prices as JSON floats (`"total_including_vat": 600.0`) and Stripe
 * requires integer minor units. Converting between them ad hoc is how a booking ends up
 * charged $599.99 instead of $600.00 — `(int) (6.0 * 100)` is 599 on some inputs because
 * 6.0 is not exactly representable. Every conversion in this feature goes through
 * fromFloat(), which rounds explicitly instead of truncating.
 *
 * Immutable by construction. Arithmetic returns new instances, so an amount cannot be
 * mutated after it has been validated against a Lodgify quote.
 *
 * ZERO-DECIMAL CURRENCIES ARE NOT HANDLED. This property prices in CAD; JPY-style
 * currencies have no minor unit and would need a different exponent. assertSupported()
 * fails loudly rather than silently charging 100x.
 */
final readonly class Money
{
    /**
     * Currencies Stripe treats as zero-decimal. Listed to REJECT them, not support them:
     * every amount in this codebase assumes a 2-decimal currency.
     */
    private const ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF',
        'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    public int $cents;

    public string $currency;

    /**
     * Not promoted: the currency is normalised before assignment, and a promoted
     * readonly property cannot be reassigned in the constructor body.
     */
    public function __construct(int $cents, string $currency)
    {
        $normalised = strtoupper(trim($currency));

        if ($cents < 0) {
            throw new InvalidArgumentException("Money cannot be negative: {$cents}");
        }

        if (strlen($normalised) !== 3 || ! ctype_alpha($normalised)) {
            throw new InvalidArgumentException("Currency must be a 3-letter code, got: {$currency}");
        }

        $this->cents = $cents;
        $this->currency = $normalised;
    }

    /**
     * Build from a float, as Lodgify supplies it.
     *
     * round() before casting is the whole point: (int) (19.99 * 100) is 1998.
     */
    public static function fromFloat(float|int|string $amount, string $currency): self
    {
        self::assertSupported($currency);

        if (! is_numeric($amount)) {
            throw new InvalidArgumentException('Non-numeric money amount: '.var_export($amount, true));
        }

        return new self((int) round(((float) $amount) * 100), $currency);
    }

    public static function fromCents(int $cents, string $currency): self
    {
        self::assertSupported($currency);

        return new self($cents, $currency);
    }

    public static function zero(string $currency): self
    {
        return new self(0, $currency);
    }

    /**
     * Guard against zero-decimal currencies reaching Stripe with a 2-decimal assumption.
     */
    public static function assertSupported(string $currency): void
    {
        if (in_array(strtoupper($currency), self::ZERO_DECIMAL, true)) {
            throw new InvalidArgumentException(
                "Currency {$currency} has no minor unit; this codebase assumes 2 decimals. "
                .'Add explicit exponent handling before accepting it.'
            );
        }
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents + $other->cents, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        if ($other->cents > $this->cents) {
            throw new InvalidArgumentException(
                "Subtracting {$other->cents} from {$this->cents} would be negative."
            );
        }

        return new self($this->cents - $other->cents, $this->currency);
    }

    /** Percentage of this amount, rounded half-up to the cent. */
    public function percent(float $percent): self
    {
        return new self((int) round($this->cents * ($percent / 100)), $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents && $this->currency === $other->currency;
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function isPositive(): bool
    {
        return $this->cents > 0;
    }

    public function toFloat(): float
    {
        return $this->cents / 100;
    }

    /** "CA$1,234.56"-ish, for guest-facing copy. */
    public function format(): string
    {
        return number_format($this->cents / 100, 2).' '.$this->currency;
    }

    /** Lowercase, as the Stripe API expects it. */
    public function stripeCurrency(): string
    {
        return strtolower($this->currency);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Currency mismatch: {$this->currency} vs {$other->currency}"
            );
        }
    }
}
