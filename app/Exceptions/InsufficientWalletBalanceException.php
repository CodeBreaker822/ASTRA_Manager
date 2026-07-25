<?php

namespace App\Exceptions;

class InsufficientWalletBalanceException extends RuntimeException
{
    public function __construct(?string $message = null, ?Throwable $previous = null)
    {
        parent::__construct(
            $message ?? 'Insufficient wallet balance. Please add funds to continue.',
            402,
            $previous,
        );
    }
}