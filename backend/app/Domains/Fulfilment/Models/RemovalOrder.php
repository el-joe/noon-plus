<?php

namespace App\Domains\Fulfilment\Models;

use App\Domains\Fulfilment\Enums\RemovalStatus;
use App\Domains\Fulfilment\Enums\RemovalType;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A seller's request to take FBN stock back (or have it disposed of).
 */
#[Fillable(['vendor_id', 'warehouse_id', 'type', 'address', 'currency', 'reason'])]
class RemovalOrder extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    protected $table = 'fbn_removal_orders';

    /**
     * @var array<string, string>
     */
    protected $attributes = ['status' => 'requested'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RemovalStatus::class,
            'type' => RemovalType::class,
            'address' => 'array',
            'fee' => 'decimal:4',
            'processed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function transitionSubject(): string
    {
        return 'fbn_removal_order';
    }

    /**
     * @return HasMany<RemovalLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(RemovalLine::class, 'fbn_removal_order_id');
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
