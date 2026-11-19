<?php

namespace App\Domains\Finance\Models;

use App\Shared\Audit\LogsCountryActivity;
use App\Shared\Database\UsesCountryDatabase;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A country's acquisition commission rule (settings catalogue
 * `acquisition_commission_rules`): `rate_percent` of a vendor's monthly
 * sales when they fall inside [min, max] and the month overlaps
 * [starts_on, ends_on]; for one acquirer or (null) for every acquirer.
 */
#[Fillable(['name', 'acquirer_user_id', 'min_monthly_sales', 'max_monthly_sales', 'rate_percent', 'starts_on', 'ends_on', 'is_active', 'created_by'])]
class AcquisitionCommissionRule extends Model
{
    use LogsCountryActivity, UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'ends_on' => 'immutable_date', 'is_active' => 'boolean'];
    }

    public function coversPeriod(CarbonInterface $start, CarbonInterface $end): bool
    {
        return $this->starts_on->lte($end) && ($this->ends_on === null || $this->ends_on->gte($start));
    }

    public function bandContains(string $sales): bool
    {
        return bccomp($sales, (string) $this->min_monthly_sales, 4) >= 0
            && ($this->max_monthly_sales === null || bccomp($sales, (string) $this->max_monthly_sales, 4) <= 0);
    }
}
