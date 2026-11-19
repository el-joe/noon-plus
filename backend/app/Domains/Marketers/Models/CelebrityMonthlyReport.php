<?php

namespace App\Domains\Marketers\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One celebrity's (influencer's) monthly report (FR-ANL-2, FR-MKT-18):
 * headline figures in columns, the detail (conversions by status, top
 * products, campaigns, cross-promotion standing) in `metrics`.
 *
 * @property array{conversions_by_status: array<string, int>, top_products: array<int, array{listing_id: int, title: string, units: int, sales: string}>, campaigns: array{invitations: int, accepted: int, active: int, content_approved: int}, cross_promotion: array<int, array{policy: string, required: int, completed: int, status: string}>, reversed_commission: string} $metrics
 */
#[Fillable([
    'marketer_profile_id', 'period', 'currency', 'orders_count', 'units_sold', 'attributed_sales', 'conversions_count', 'commission_net', 'bonus_total',
    'fixed_fees', 'total_earnings', 'referral_clicks', 'metrics', 'generated_by_user_id', 'generated_at', 'emailed_at',
])]
class CelebrityMonthlyReport extends Model
{
    use UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['metrics' => 'array', 'generated_at' => 'immutable_datetime', 'emailed_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<MarketerProfile, $this>
     */
    public function marketer(): BelongsTo
    {
        return $this->belongsTo(MarketerProfile::class, 'marketer_profile_id');
    }

    public function fileName(): string
    {
        return "nawy-creator-report-{$this->period}-{$this->marketer_profile_id}.pdf";
    }
}
