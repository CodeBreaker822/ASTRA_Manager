<?php

namespace App\Services\Billing;

use InvalidArgumentException;
use RuntimeException;

/**
 * USD-based money representation
 *
 * Money is always represented in cents (integer) for consistency.
 * USD is used for all pricing, transactions, and wallets.
 *
 * @property int $value The amount in cents (1 USD = 100 cents)
 * @property-read float $asFloat The amount in USD (float)
 * @property-read string $formatted The amount in USD with 2 decimal places
 */
class Money
{
    public function __construct(
        public readonly int $value // Cents (e.g., 100 = $1.00, 190000 = $1,900.00)
    ) {
        if ($value < 0) {
            throw new InvalidArgumentException('Money cannot be negative: ' . $value);
        }
    }

    /**
     * Create Money from USD dollars
     */
    public static function fromDollars(float $dollars): self
    {
        return new self((int) round($dollars * 100));
    }

    /**
     * Create Money from cents
     */
    public static function fromCents(int $cents): self
    {
        return new self((int) round($cents));
    }

    /**
     * Create Money from formatted dollar string (supports "$1.90", "1.90")
     */
    public static function fromString(string $input): self
    {
        // Remove currency symbol and commas
        $cleaned = preg_replace('/[^\d.]/', '', $input);
        $cleaned = str_replace(',', '', $cleaned);
        $dollars = (float) $cleaned;
        return self::fromDollars($dollars);
    }

    /**
     * Convert to USD dollars (returns float for display)
     */
    public function asDollars(): float
    {
        return $this->value / 100.0;
    }

    /**
     * Convert to formatted dollar string
     */
    public function formatted(): string
    {
        return '$' . number_format($this->asDollars(), 2);
    }

    /**
     * Add two Money values
     */
    public function add(Money $other): self
    {
        return new self($this->value + $other->value);
    }

    /**
     * Subtract two Money values
     */
    public function subtract(Money $other): self
    {
        return new self($this->value - $other->value);
    }

    /**
     * Multiply by a factor
     */
    public function multiply(int|float $factor, int $roundingMode = PHP_ROUND_HALF_UP): self
    {
        $result = ($this->value * $factor);
        return new self((int) round($result, $roundingMode));
    }

    /**
     * Divide by a divisor
     */
    public function divide(int $divisor, int $roundingMode = PHP_ROUND_HALF_UP): self
    {
        if ($divisor <= 0) {
            throw new InvalidArgumentException('Divisor must be positive: ' . $divisor);
        }

        $result = (int) round($this->value / $divisor, $roundingMode);
        return new self($result);
    }

    /**
     * Compare two Money values
     */
    public function compareTo(Money $other): int
    {
        return $this->value <=> $other->value;
    }

    /**
     * Check if this Money is greater than another
     */
    public function greaterThan(Money $other): bool
    {
        return $this->value > $other->value;
    }

    /**
     * Check if this Money is less than another
     */
    public function lessThan(Money $other): bool
    {
        return $this->value < $other->value;
    }

    /**
     * Check if this Money equals another
     */
    public function equals(Money $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Check if this Money is zero
     */
    public function isZero(): bool
    {
        return $this->value === 0;
    }

    /**
     * Get string representation
     */
    public function __toString(): string
    {
        return $this->formatted();
    }

    /**
     * Serialize for JSON (stores cents)
     */
    public function jsonSerialize(): int
    {
        return $this->value;
    }

    /**
     * Create Money from serialized value
     */
    public static function fromJson(int $value): self
    {
        return new self($value);
    }
}