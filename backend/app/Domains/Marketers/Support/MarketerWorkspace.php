<?php

namespace App\Domains\Marketers\Support;

use App\Domains\Marketers\Models\MarketerProfile;

/**
 * The marketer a Creator Studio request works for: the user's own profile
 * in the current country, or the managed marketer an agency user switched
 * to (role-permission matrix §7). Every Studio query is scoped to it.
 */
class MarketerWorkspace
{
    private ?MarketerProfile $marketer = null;

    public function set(?MarketerProfile $marketer): void
    {
        $this->marketer = $marketer;
    }

    public function has(): bool
    {
        return $this->marketer !== null;
    }

    public function marketer(): MarketerProfile
    {
        abort_if($this->marketer === null, 403, __('Your account has no marketer profile in this country.'));

        return $this->marketer;
    }
}
