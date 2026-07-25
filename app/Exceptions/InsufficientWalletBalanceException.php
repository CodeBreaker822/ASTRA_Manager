<?php

namespace App\Exceptions;

class InsufficientWalletBalanceException extends \RuntimeException
{
    public function __construct(?string $message = null, ?\Throwable $previous = null)
    {
        $message = $message ?? 'Insufficient wallet balance. Please add funds to continue.';
        parent::__construct($message, 422, $previous);
    }
}