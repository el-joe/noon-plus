<?php

namespace App\Domains\Advertising\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An impression or click of an ad campaign with its cost (invalid events are kept, not billed).
 */
#[Fillable(['ad_campaign_id', 'type', 'placement', 'visitor_key', 'cost', 'is_billable', 'invalid_reason'])]
class AdEvent extends Model
{
    use UsesCountryDatabase;

    public const UPDATED_AT = null;

    protected $table = 'ad_events';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cost' => 'decimal:4',
            'is_billable' => 'boolean',
        ];
    }
}
