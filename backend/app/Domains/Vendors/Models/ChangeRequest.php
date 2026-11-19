<?php

namespace App\Domains\Vendors\Models;

use App\Domains\Vendors\Enums\ChangeRequestSection;
use App\Domains\Vendors\Enums\ChangeRequestStatus;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change to a locked profile section of a store or travel agency
 * (lifecycle catalogue §7.3). `current_values` is the snapshot when it was
 * asked, `applied_diff` the before / after written on approval.
 *
 * @property ChangeRequestStatus $status
 * @property ChangeRequestSection $section
 */
#[Fillable([
    'number', 'requester_type', 'vendor_id', 'requested_by_user_id', 'section', 'current_values', 'requested_values', 'note', 'status',
    'bank_verified', 'applied_diff', 'rejection_reason', 'reviewed_by', 'reviewed_at',
])]
class ChangeRequest extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ChangeRequestStatus::class,
            'section' => ChangeRequestSection::class,
            'current_values' => 'array',
            'requested_values' => 'array',
            'applied_diff' => 'array',
            'bank_verified' => 'boolean',
            'reviewed_at' => 'immutable_datetime',
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
