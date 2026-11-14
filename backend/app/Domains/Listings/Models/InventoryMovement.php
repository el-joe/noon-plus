<?php

namespace App\Domains\Listings\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only stock movement (SRS §5.2). Available stock is the sum of all
 * movements for a listing; rows are never updated or deleted.
 */
#[Fillable(['listing_id', 'quantity', 'reason', 'reference_type', 'reference_id', 'actor_user_id'])]
class InventoryMovement extends Model
{
    use UsesCountryDatabase;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Inventory movements are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Inventory movements are append-only.'));
    }
}
