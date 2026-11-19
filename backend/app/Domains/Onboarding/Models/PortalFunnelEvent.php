<?php

namespace App\Domains\Onboarding\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One portal funnel event (page view, wizard step reached, application submitted).
 */
#[Fillable(['event', 'page', 'type', 'step', 'visitor_hash', 'locale', 'created_at'])]
class PortalFunnelEvent extends Model
{
    use UsesCountryDatabase;

    public const EVENTS = ['view', 'step', 'submitted'];

    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
