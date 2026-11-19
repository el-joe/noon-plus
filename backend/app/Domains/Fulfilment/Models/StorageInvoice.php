<?php

namespace App\Domains\Fulfilment\Models;

use App\Domains\Vendors\Models\Vendor;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A seller's monthly FBN storage bill (storage, long-term storage and
 * unsellable storage), charged once on the store's next statement.
 */
class StorageInvoice extends Model
{
    use UsesCountryDatabase;

    protected $table = 'fbn_storage_invoices';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date', 'period_end' => 'date', 'lines' => 'array', 'charged_at' => 'datetime',
            'storage_fee' => 'decimal:4', 'long_term_fee' => 'decimal:4', 'unsellable_fee' => 'decimal:4', 'total' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
