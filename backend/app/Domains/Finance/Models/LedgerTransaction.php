<?php

namespace App\Domains\Finance\Models;

use App\Domains\Orders\Enums\ActorType;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One balanced group of ledger entries (SRS §9 "transaction group"),
 * unique per idempotency key. Immutable: corrections are new transactions.
 */
#[Fillable(['idempotency_key', 'type', 'currency', 'total', 'reference_type', 'reference_id', 'description', 'actor_type', 'actor_user_id', 'posted_at'])]
class LedgerTransaction extends Model
{
    use UsesCountryDatabase;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Ledger transactions are append-only.'));
        static::deleting(fn () => throw new LogicException('Ledger transactions are append-only.'));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['actor_type' => ActorType::class, 'posted_at' => 'immutable_datetime'];
    }

    /**
     * @return HasMany<LedgerEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class)->orderBy('id');
    }
}
