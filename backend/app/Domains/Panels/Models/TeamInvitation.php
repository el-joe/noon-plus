<?php

namespace App\Domains\Panels\Models;

use App\Domains\Identity\Enums\MembershipRole;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * An invitation to join a tenant's team (QA-003, QC-020): a shipping
 * company's driver or supervisor, or a store / travel agency staff member.
 * Nothing is attached to any account until the invitee opens the emailed
 * link and accepts it. Only the token's SHA-256 is stored.
 *
 * @property MembershipRole $role
 */
#[Fillable([
    'tenant_type', 'tenant_id', 'role', 'tenant_role', 'email', 'name', 'details', 'token_hash', 'invited_by_user_id',
    'expires_at', 'accepted_at', 'accepted_user_id', 'revoked_at',
])]
#[Hidden(['token_hash'])]
class TeamInvitation extends Model
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
            'role' => MembershipRole::class,
            'details' => 'array',
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
