<?php

namespace App\Services\Billing;

use RuntimeException;
use Throwable;
use Worksome\Exchange\Facades\Exchange;

class CurrencyExchangeRateService
{
    public function usdToPhpRate(): float
    {
        try {
            $rates = Exchange::rates('USD', ['PHP']);
        } catch (Throwable $exception) {
            throw new RuntimeException('Unable to retrieve live USD to PHP exchange rate.', 0, $exception);
        }

        $rate = $rates->rates['PHP'] ?? null;

        if (! is_numeric($rate) || (float) $rate <= 0) {
            throw new RuntimeException('Live USD to PHP exchange rate must be greater than 0.');
        }

        return round((float) $rate, 6);
    }
}
