<?php

namespace App\Models;

use App\Domains\Countries\Models\Country;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Identity\Models\LoginEvent;
use App\Domains\Identity\Models\RoleMembership;
use App\Domains\Identity\Models\SocialAccount;
use App\Domains\Identity\Support\SecurityEmails;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * One central identity for every person (customer, vendor, marketer, staff…).
 * What a user may do in each country comes from their role memberships.
 */
#[Fillable(['name', 'email', 'password', 'two_factor_enabled', 'phone', 'preferred_locale', 'status', 'home_country_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements HasLocalePreference
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
            'password_set_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_enabled' => 'boolean',
            'requires_email' => 'boolean',
            'marketing_preferences' => 'array',
        ];
    }

    /**
     * D9: a password the person chose (not the random one an email-code or
     * social account starts with) is stamped `password_set_at`; whoever sets
     * a random password passes `password_set_at => null` explicitly.
     */
    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if ($user->isDirty('password') && ! $user->isDirty('password_set_at')) {
                $user->password_set_at = now();
            }
        });

        // Two-step sign-in turned on / off (security page or an admin reset): tell the account holder.
        static::updated(function (User $user): void {
            if ($user->wasChanged('two_factor_enabled')) {
                app(SecurityEmails::class)->twoFactorChanged($user);
            }
        });
    }

    /**
     * A customer account opened by email code or social sign-in: random
     * password, none chosen yet (D9).
     */
    public static function createPasswordless(string $email, string $name, bool $verified = false): self
    {
        return static::query()->forceCreate([
            'email' => $email,
            'name' => $name,
            'password' => Str::random(40),
            'password_set_at' => null,
            'email_verified_at' => $verified ? now() : null,
        ]);
    }

    public function hasChosenPassword(): bool
    {
        return $this->password_set_at !== null;
    }

    /**
     * @return HasMany<SocialAccount, $this>
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * Locale for mail and notifications (QC-028): Laravel reads this through
     * HasLocalePreference when it sends to the user.
     */
    public function preferredLocale(): string
    {
        return in_array($this->preferred_locale, ['en', 'ar'], true) ? $this->preferred_locale : config('app.locale');
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
