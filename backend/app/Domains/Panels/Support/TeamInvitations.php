<?php

namespace App\Domains\Panels\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Delivery\Models\Carrier;
use App\Domains\Delivery\Support\DeliveryAgents;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Identity\Support\AdminRoleLabels;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Panels\Models\TeamInvitation;
use App\Domains\Panels\Notifications\TeamInvitationNotification;
use App\Models\User;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Team invitations (QA-003, QC-020). A carrier supervisor adding a driver
 * or supervisor, or a store / travel agency owner adding staff, only sends
 * an invitation by email: the answer never says whether the address has a
 * Nawy account and never shows its stored name. On acceptance through the
 * emailed link an existing account keeps its password and gains the
 * membership; a new account is created with the password the invitee
 * chooses (the inbox is proven, so the email is verified).
 */
class TeamInvitations
{
    public const VALID_DAYS = 7;

    /**
     * Tenant of Nawy's own staff and fleet (wave 2): admin staff invited by
     * an admin, and drivers an admin adds without a shipping company.
     */
    public const PLATFORM_TENANT = 'platform';

    public function __construct(private CountryContext $countryContext, private DeliveryAgents $agents) {}

    /**
     * @param  array<string, mixed>  $details  e.g. phone and vehicle of a driver
     */
    public function invite(string $panelKey, string $tenantType, int $tenantId, string $teamName, MembershipRole $role, ?string $tenantRole, string $email, string $name, array $details, int $invitedByUserId): TeamInvitation
    {
        $email = Str::lower(trim($email));
        $pending = TeamInvitation::query()->where('tenant_type', $tenantType)->where('tenant_id', $tenantId)->where('email', $email)
            ->where('role', $role)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->exists();

        if ($pending) {
            throw new TransitionRefused(__('An invitation to this email is already waiting to be accepted.'));
        }

        $token = Str::random(48);
        $invitation = TeamInvitation::query()->create([
            'tenant_type' => $tenantType,
            'tenant_id' => $tenantId,
            'role' => $role,
            'tenant_role' => $tenantRole,
            'email' => $email,
            'name' => mb_substr(trim($name), 0, 120),
            'details' => $details === [] ? null : $details,
            'token_hash' => hash('sha256', $token),
            'invited_by_user_id' => $invitedByUserId,
            'expires_at' => now()->addDays(self::VALID_DAYS),
        ]);

        Notification::route('mail', $email)->notify(new TeamInvitationNotification(
            $teamName,
            $this->roleLabel($role, $tenantRole),
            route($panelKey.'.team-invitations.show', ['country' => $this->countryContext->current()->code, 'token' => $token]),
            self::VALID_DAYS,
            $tenantType === self::PLATFORM_TENANT ? $panelKey : null,
        ));

        return $invitation;
    }

    public function findPending(string $token): ?TeamInvitation
    {
        $invitation = TeamInvitation::query()->where('token_hash', hash('sha256', $token))->first();

        return $invitation !== null && $invitation->isPending() ? $invitation : null;
    }

    public function accountExists(TeamInvitation $invitation): bool
    {
        return User::query()->where('email', $invitation->email)->exists();
    }

    /**
     * Accept: a new account needs a password (min 8, confirmed upstream).
     */
    public function accept(TeamInvitation $invitation, ?string $password): User
    {
        if (! $invitation->isPending()) {
            throw new TransitionRefused(__('This invitation is no longer valid.'));
        }

        $country = $this->countryContext->current();

        return DB::transaction(function () use ($invitation, $password, $country): User {
            $user = User::query()->where('email', $invitation->email)->lockForUpdate()->first();

            if ($user === null) {
                if ($password === null || mb_strlen($password) < 8) {
                    throw ValidationException::withMessages(['password' => __('Choose a password of at least 8 characters.')]);
                }

                $user = User::query()->create([
                    'name' => $invitation->name,
                    'email' => $invitation->email,
                    'password' => Hash::make($password),
                    'home_country_id' => $country->id,
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            match ($invitation->role) {
                MembershipRole::DeliveryAgent => $this->agents->create(
                    ['email' => $invitation->email, 'name' => $invitation->name, 'carrier_id' => $invitation->tenant_type === Carrier::TENANT ? $invitation->tenant_id : null]
                        + array_diff_key((array) $invitation->details, ['carrier_id' => true, 'invited_by' => true]),
                    ($invitation->details['invited_by'] ?? null) === 'admin' ? ActorType::Admin : ActorType::Carrier,
                    $invitation->invited_by_user_id,
                    $user,
                ),
                MembershipRole::Admin => $this->joinAdmin($invitation, $user),
                default => $this->joinTeam($invitation, $user),
            };

            $invitation->forceFill(['accepted_at' => now(), 'accepted_user_id' => $user->id])->save();

            return $user;
        });
    }

    public function revoke(TeamInvitation $invitation): void
    {
        $invitation->forceFill(['revoked_at' => now()])->save();
    }

    public function roleLabel(MembershipRole $role, ?string $tenantRole): string
    {
        return match ($role) {
            MembershipRole::DeliveryAgent => __('delivery driver'),
            MembershipRole::CarrierSupervisor => __('shipping supervisor'),
            MembershipRole::Admin => __('Nawy admin (:role)', ['role' => app(AdminRoleLabels::class)->label($tenantRole)]),
            default => $tenantRole === null ? __('team member') : TenantPermissions::roleLabel($tenantRole),
        };
    }

    /**
     * Admin staff (QC-004): an admin membership in the invitation's country
     * and the invited role there (Spatie team = country).
     */
    private function joinAdmin(TeamInvitation $invitation, User $user): void
    {
        $country = $this->countryContext->current();
        $user->memberships()->updateOrCreate(
            ['role' => MembershipRole::Admin, 'country_id' => $country->id, 'tenant_type' => null, 'tenant_id' => null],
            ['status' => 'active'],
        );

        if ($invitation->tenant_role !== null) {
            setPermissionsTeamId($country->id);
            $user->unsetRelation('roles')->assignRole($invitation->tenant_role);
        }
    }

    private function joinTeam(TeamInvitation $invitation, User $user): void
    {
        $country = $this->countryContext->current();

        if ($invitation->role === MembershipRole::CarrierSupervisor
            && $user->memberships()->where('role', MembershipRole::CarrierSupervisor)->where('country_id', $country->id)->exists()) {
            throw new TransitionRefused(__('Your account already belongs to a shipping company in this country.'));
        }

        if (in_array($invitation->role, [MembershipRole::ProductVendor, MembershipRole::ClassifiedSeller, MembershipRole::TravelAgency], true)
            && $user->memberships()->where('role', $invitation->role)->where('country_id', $country->id)->where('tenant_id', '!=', $invitation->tenant_id)->exists()) {
            throw new TransitionRefused(__('Your account already works for another team of this kind in this country.'));
        }

        $user->memberships()->updateOrCreate(
            ['role' => $invitation->role, 'country_id' => $country->id, 'tenant_type' => $invitation->tenant_type, 'tenant_id' => $invitation->tenant_id],
            ['status' => 'active', 'tenant_role' => $invitation->tenant_role],
        );
    }
}
