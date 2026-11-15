<?php

namespace App\Shared\Audit;

use App\Domains\Countries\Support\CountryContext;
use Illuminate\Support\Str;
use Spatie\Activitylog\Contracts\Activity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Spatie activitylog for admin-editable models that live in a country
 * database: the activity row (central `activity_log`) also records the
 * country code, so the activity log viewer filters per country.
 * Override {@see self::auditLogName()} to group entries.
 */
trait LogsCountryActivity
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName($this->auditLogName())
            ->logFillable()
            ->logExcept($this->auditHidden())
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function tapActivity(Activity $activity, string $eventName): void
    {
        $context = app(CountryContext::class);

        if ($context->has()) {
            $activity->properties = $activity->properties->put('country', $context->current()->code);
        }
    }

    protected function auditLogName(): string
    {
        return Str::snake(Str::pluralStudly(class_basename($this)));
    }

    /**
     * @return array<int, string>
     */
    protected function auditHidden(): array
    {
        return [];
    }
}
