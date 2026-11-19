<?php

namespace App\Domains\Finance\Models;

use App\Domains\Membership\Enums\MembershipInterval;
use App\Shared\Audit\LogsCountryActivity;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A vendor plan of the country (settings catalogue `subscription_plans`:
 * monthly or annual price in the country currency, trial days, the listed
 * features and an optional cap on active listings). Audited.
 */
#[Fillable(['code', 'name_en', 'name_ar', 'interval', 'price', 'trial_days', 'max_active_listings', 'features', 'sort_order', 'is_active'])]
class VendorSubscriptionPlan extends Model
{
    use LogsCountryActivity, UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['interval' => MembershipInterval::class, 'features' => 'array', 'is_active' => 'boolean'];
    }

    public function name(): string
    {
        return app()->getLocale() === 'ar' ? $this->name_ar : $this->name_en;
    }
}
