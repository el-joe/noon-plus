<?php

namespace App\Domains\RealEstate\Models;

use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\RealEstate\Enums\LeadStatus;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A broker lead (spec §5.7) in the pipeline with follow-ups.
 */
#[Fillable(['marketer_profile_id', 'classified_ad_id', 'customer_user_id', 'name', 'phone', 'email', 'source', 'source_id', 'budget', 'notes', 'lost_reason', 'next_follow_up_at'])]
class BrokerLead extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    protected $table = 'broker_leads';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'budget' => 'decimal:4',
            'next_follow_up_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ClassifiedAd, $this>
     */
    public function ad(): BelongsTo
    {
        return $this->belongsTo(ClassifiedAd::class, 'classified_ad_id');
    }

    /**
     * @return HasMany<BrokerLeadFollowUp, $this>
     */
    public function followUps(): HasMany
    {
        return $this->hasMany(BrokerLeadFollowUp::class);
    }
}
