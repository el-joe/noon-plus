<?php

namespace App\Domains\Identity\Exceptions;

use RuntimeException;

/**
 * A sign-in code request or verification was refused.
 */
class EmailLoginCodeRejected extends RuntimeException
{
    public const INVALID = 'code_invalid';

    public const EXPIRED = 'code_expired';

    public const LOCKED = 'code_locked';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->reason === self::LOCKED ? 429 : 422;
    }
}
