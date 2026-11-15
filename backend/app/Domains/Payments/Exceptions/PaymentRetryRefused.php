<?php

namespace App\Domains\Payments\Exceptions;

use RuntimeException;

/**
 * A new payment attempt is not allowed (already paid, hold window over,
 * too many attempts, cancelled order, or a method not allowed for retry).
 */
class PaymentRetryRefused extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
