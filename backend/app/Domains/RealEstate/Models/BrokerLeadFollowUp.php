<?php

namespace App\Domains\RealEstate\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A scheduled follow-up of a lead (call, WhatsApp, meeting, email).
 */
#[Fillable(['broker_lead_id', 'channel', 'due_at', 'note', 'done_at'])]
class BrokerLeadFollowUp extends Model
{
    use UsesCountryDatabase;

    protected $table = 'broker_lead_follow_ups';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_at' => 'immutable_datetime',
            'done_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<BrokerLead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(BrokerLead::class, 'broker_lead_id');
    }
}
