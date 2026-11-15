<?php

namespace App\Domains\Content\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A notification text for one event × channel × language (ADR 10), per
 * country or global (country_id null). Variables are written `{{ name }}`.
 */
#[Fillable(['country_id', 'event', 'channel', 'locale', 'subject', 'body', 'is_active', 'updated_by'])]
class NotificationTemplate extends Model
{
    use LogsActivity;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->useLogName('notification_templates');
    }
}
