<?php

namespace App\Domains\Content\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One saved state of a notification template (version history in Admin →
 * Content → Notification templates). `action` is `saved`, `restored` or
 * `reset` (back to the built-in text; subject and body empty).
 */
#[Fillable(['country_id', 'event', 'channel', 'locale', 'version', 'action', 'subject', 'body', 'is_active', 'created_by'])]
class NotificationTemplateVersion extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
