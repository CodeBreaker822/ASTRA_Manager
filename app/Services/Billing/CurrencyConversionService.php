<?php

namespace App\Services\Billing;

/**
 * Currency Conversion Service
 *
 * Handles conversion between USD (stored in the system) and PHP (local currency).
 * Used for PayMongo transactions and any PHP-specific calculations.
 *
 * @property float $phpExchangeRate Current PHP to USD exchange rate
 * @property string $lastUpdated Timestamp when rate was last fetched
 */
class CurrencyConversionService
{
    public function __construct(
        private float $phpExchangeRate = 56.50, // Default rate: 1 USD = 56.50 PHP
        private string $lastUpdated = '2026-01-26 00:00:00'
    ) {}

    /**
     * Set the PHP to USD exchange rate
     */
    public function setRate(float $rate, ?string $date = null): void
    {
        $this->phpExchangeRate = $rate;
        $this->lastUpdated = $date ?? now()->toDateTimeString();
    }

    /**
     * Get current PHP to USD exchange rate
     */
    public function getPHPToUSDRate(): float
    {
        return $this->phpExchangeRate;
    }

    /**
     * Convert USD cents to PHP (pesos)
     * Used for PayMongo webhook processing
     */
    public function USD_Cents_To_PHP(float $usdCents): float
    {
        $usdDollars = $usdCents / 100.0;
        return $usdDollars * $this->phpExchangeRate;
    }

    /**
     * Convert PHP (pesos) to USD cents
     * For internal calculations when working in PHP
     */
    public function PHP_To_USD_Cents(float $phpAmount): int
    {
        $usdDollars = $phpAmount / $this->phpExchangeRate;
        return (int) round($usdDollars * 100);
    }

    /**
     * Convert USD cents to PHP pesos (formatted)
     */
    public function USD_Cents_To_PHP_Formatted(float $usdCents): string
    {
        $phpAmount = $this->USD_Cents_To_PHP($usdCents);
        return '₱' . number_format($phpAmount, 2);
    }

    /**
     * Convert PHP pesos to USD cents (formatted)
     */
    public function PHP_To_USD_Cents_Formatted(float $phpAmount): string
    {
        $usdCents = $this->PHP_To_USD_Cents($phpAmount);
        $usdDollars = $usdCents / 100.0;
        return '$' . number_format($usdDollars, 2);
    }

    /**
     * Get PHP amount from PayMongo webhook (PayMongo sends PHP amounts)
     */
    public function fromPayMongoAmount(float $paymongoAmountPHP): int
    {
        // PayMongo webhook amounts are in PHP (local currency)
        return $this->PHP_To_USD_Cents($paymongoAmountPHP);
    }

    /**
     * Convert USD cents to PayMongo PHP amount (what PayMongo expects)
     * Used when creating charges in PayMongo
     */
    public function toPayMongoAmount(int $usdCents): float
    {
        $usdDollars = $usdCents / 100.0;
        $phpAmount = $usdDollars * $this->phpExchangeRate;
        return (float) round($phpAmount, 2); // PayMongo expects float
    }

    /**
     * Get updated exchange rate from external API (optional)
     */
    public function fetchUpdatedRate(?string $apiKey = null): void
    {
        // Example: Fetch from free currency API
        // This would require a real API key
        //
        // $response = Http::get("https://api.exchangerate-api.com/v4/latest/PHP");
        // $rate = $response['rates']['USD'];
        // $this->setRate($rate);
    }

    /**
     * Get rate with context
     */
    public function getRateContext(): array
    {
        return [
            'php_to_usd_rate' => $this->phpExchangeRate,
            'usd_to_php_rate' => 1 / $this->phpExchangeRate,
            'last_updated' => $this->lastUpdated,
        ];
    }
}