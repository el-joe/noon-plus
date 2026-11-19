<?php

namespace App\Domains\Catalog\Enums;

use App\Domains\Catalog\Support\ProductStateMachine;

/**
 * Product status (lifecycle catalogue §1.2, SRS §5.1 FR-CAT-1; QC-016):
 * draft → active; active ⇄ restricted (legal / compliance, needs a
 * reason); active / restricted → discontinued (terminal). Only `active`
 * products are sold and shown. `products.status` stays a string column;
 * {@see ProductStateMachine} is the only writer of changes.
 */
enum ProductStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Restricted = 'restricted';
    case Discontinued = 'discontinued';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Active],
            self::Active => [self::Restricted, self::Discontinued],
            self::Restricted => [self::Active, self::Discontinued],
            self::Discontinued => [],
        };
    }
}
