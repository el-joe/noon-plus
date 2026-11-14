<?php

namespace App\Domains\FlashSales\Exceptions;

use RuntimeException;

class FlashSaleTransitionRefused extends RuntimeException
{
    public static function notAllowed(string $from, string $to): self
    {
        return new self("A flash sale cannot move from {$from} to {$to}.");
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
