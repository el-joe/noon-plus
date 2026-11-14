<?php

namespace App\Domains\Listings\Exceptions;

use App\Domains\Listings\Enums\ListingStatus;
use RuntimeException;

class ListingTransitionRefused extends RuntimeException
{
    public static function notAllowed(ListingStatus $from, ListingStatus $to): self
    {
        return new self("A listing cannot move from {$from->value} to {$to->value}.");
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
