<?php

namespace App\Domains\Finance\Models;

use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A ledger account (ADR 4). `balance` is the projection of its entries on
 * the account's normal side, kept in the posting transaction under a row
 * lock; wallets are these accounts per owner × currency × bucket.
 */
#[Fillable(['code', 'owner_type', 'owner_id', 'bucket', 'currency', 'normal_side'])]
class LedgerAccount extends Model
{
    use UsesCountryDatabase;

    public const OWNER_PLATFORM = 'platform';

    public const OWNER_CUSTOMER = 'customer';

    public const OWNER_VENDOR = 'vendor';

    public const OWNER_AGENT = 'agent';

    public const OWNER_MARKETER = 'marketer';

    public const OWNER_GATEWAY = 'gateway';

    public const OWNER_CARRIER = 'carrier';

    /** An admin staff member (acquisition commissions, FR-FIN-6). */
    public const OWNER_STAFF = 'staff';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['balance' => '0'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['code' => LedgerAccountCode::class];
    }

    /**
     * @return HasMany<LedgerEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class)->orderBy('id');
    }
}
