<?php

namespace App\Domains\Fulfilment\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Units of a listing stored in a warehouse at the daily snapshot (01:00),
 * with the unit volume used; the monthly storage invoice averages them.
 */
#[Fillable(['snapshot_on', 'vendor_id', 'warehouse_id', 'listing_id', 'units', 'damaged_units', 'long_term_units', 'unsellable_units', 'unit_cubic_feet'])]
class StorageSnapshot extends Model
{
    use UsesCountryDatabase;

    public const UPDATED_AT = null;

    protected $table = 'fbn_storage_snapshots';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['snapshot_on' => 'date', 'units' => 'integer', 'damaged_units' => 'integer', 'long_term_units' => 'integer', 'unsellable_units' => 'integer'];
    }
}
