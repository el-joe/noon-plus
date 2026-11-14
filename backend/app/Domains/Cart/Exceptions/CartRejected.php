<?php

namespace App\Domains\Cart\Exceptions;

use RuntimeException;

/**
 * A cart or checkout action was refused; `code` is returned to the client.
 */
class CartRejected extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
