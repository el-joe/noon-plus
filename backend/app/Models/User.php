<?php

namespace App\Models;

use App\Domains\Countries\Models\Country;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Identity\Models\LoginEvent;
use App\Domains\Identity\Models\RoleMembership;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * One central identity for every person (customer, vendor, marketer, staff…).
 * What a user may do in each country comes from their role memberships.
 */
#[Fillable(['name', 'email', 'password', 'two_factor_enabled', 'phone', 'preferred_locale', 'status', 'home_country_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_enabled' => 'boolean',
        ];
    }

    /**
     * @return HasMany<LoginEvent, $this>
     */
    public function loginEvents(): HasMany
    {
        return $this->hasMany(LoginEvent::class);
    }

    /**
     * @return HasMany<RoleMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(RoleMembership::class);
    }

    public function hasMembership(MembershipRole $role, ?Country $country = null): bool
    {
        return $this->memberships()
            ->where('role', $role)
            ->where('status', 'active')
            ->when($country !== null, fn ($query) => $query->where(
                fn ($scoped) => $scoped->where('country_id', $country->id)->orWhereNull('country_id')
            ))
            ->exists();
    }
}
