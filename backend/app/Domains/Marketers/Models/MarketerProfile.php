<?php

namespace App\Domains\Marketers\Models;

use App\Domains\Campaigns\Models\CampaignInvitation;
use App\Domains\Marketers\Enums\MarketerJobType;
use App\Domains\Marketers\Enums\MarketerStatus;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A marketer in one country (SRS §11.1–11.2): job types, global status,
 * public boutique data, an optional managing agency and an optional own
 * store (`vendor_id`, used for peer promotion of the influencer's own FBN
 * listings). The user is central.
 *
 * @property MarketerStatus $global_status
 * @property array<int, string> $job_types
 */
#[Fillable(['user_id', 'agency_id', 'vendor_id', 'slug', 'display_name', 'bio', 'avatar_path', 'cover_path', 'job_types', 'global_status', 'followers', 'is_verified', 'whatsapp_phone', 'socials', 'category_ids', 'details', 'status_reason', 'approved_at'])]
class MarketerProfile extends Model
{
    use UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'job_types' => 'array',
            'global_status' => MarketerStatus::class,
            'is_verified' => 'boolean',
            'socials' => 'array',
            'category_ids' => 'array',
            'details' => 'array',
            'approved_at' => 'immutable_datetime',
        ];
    }

    public function user(): ?User
    {
        return User::query()->find($this->user_id);
    }

    /**
     * @return BelongsTo<MarketingAgency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(MarketingAgency::class);
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return HasMany<CampaignInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(CampaignInvitation::class, 'marketer_profile_id');
    }

    public function hasJobType(MarketerJobType $type): bool
    {
        return in_array($type->value, $this->job_types ?? [], true);
    }

    public function isActive(): bool
    {
        return $this->global_status === MarketerStatus::Active;
    }

    /**
     * Effective type when not picked as an influencer (SRS §11.1): broker
     * wins over affiliate; influencer-only acts as influencer.
     */
    public function perSaleType(): ?MarketerJobType
    {
        return match (true) {
            $this->hasJobType(MarketerJobType::Broker) => MarketerJobType::Broker,
            $this->hasJobType(MarketerJobType::Affiliate) => MarketerJobType::Affiliate,
            $this->hasJobType(MarketerJobType::Influencer) => MarketerJobType::Influencer,
            default => null,
        };
    }

    /**
     * Share of profile fields filled (cross-promotion eligibility).
     */
    public function profileCompletion(): int
    {
        $fields = [$this->display_name, $this->bio, $this->avatar_path, $this->whatsapp_phone, $this->socials, $this->category_ids];

        return (int) round(100 * count(array_filter($fields, fn ($value): bool => ! empty($value))) / count($fields));
    }
}
