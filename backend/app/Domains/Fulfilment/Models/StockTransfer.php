<?php

namespace App\Domains\Fulfilment\Models;

use App\Domains\Fulfilment\Enums\TransferStatus;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stock moved between two FBN warehouses of the country.
 */
#[Fillable(['from_warehouse_id', 'to_warehouse_id', 'note'])]
class StockTransfer extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    protected $table = 'fbn_transfers';

    /**
     * @var array<string, string>
     */
    protected $attributes = ['status' => 'requested'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => TransferStatus::class, 'dispatched_at' => 'datetime', 'received_at' => 'datetime'];
    }

    public function transitionSubject(): string
    {
        return 'fbn_transfer';
    }

    /**
     * @return HasMany<StockTransferLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class, 'fbn_transfer_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function from(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function to(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }
}
