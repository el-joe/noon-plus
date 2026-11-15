<?php

namespace App\Domains\Membership\Models;

use App\Domains\Membership\Enums\MembershipInterval;
use App\Shared\Audit\LogsCountryActivity;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A Nawy One plan of the country (settings catalogue §9: price monthly /
 * annual per country, trial days, free-delivery benefit).
 */
#[Fillable(['code', 'name_en', 'name_ar', 'interval', 'price', 'trial_days', 'free_shipping', 'is_active'])]
class MembershipPlan extends Model
{
    use LogsCountryActivity, UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['interval' => MembershipInterval::class, 'free_shipping' => 'boolean', 'is_active' => 'boolean'];
    }

    public function name(): string
    {
        return app()->getLocale() === 'ar' ? $this->name_ar : $this->name_en;
    }
}
