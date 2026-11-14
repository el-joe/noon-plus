<?php

namespace App\Domains\Listings\Exceptions;

use RuntimeException;

class InsufficientStock extends RuntimeException
{
    public function __construct(public readonly int $available, public readonly int $requested)
    {
        parent::__construct("Only {$available} in stock; {$requested} requested.");
    }
}
