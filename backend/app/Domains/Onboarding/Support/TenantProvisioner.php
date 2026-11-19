<?php

namespace App\Domains\Onboarding\Support;

use App\Domains\Contracts\Enums\SignerType;
use App\Domains\Contracts\Models\ContractSignature;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Delivery\Enums\CarrierStatus;
use App\Domains\Delivery\Models\Carrier;
use App\Domains\Documents\Models\Document;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Marketers\Enums\MarketerStatus;
use App\Domains\Marketers\Support\MarketerProfiles;
use App\Domains\Onboarding\Enums\ApplicationType;
use App\Domains\Onboarding\Models\PartnerApplication;
use App\Domains\Onboarding\Notifications\PartnerWelcomeNotification;
use App\Domains\Panels\Support\Panel;
use App\Domains\Panels\Support\PanelCountries;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Approval side effects (WP8): the account (an existing user with the
 * email — only after the applicant proved that inbox with the application
 * code, QA-002, and never with the wizard password — else a new one with
 * the wizard password and the verified email), the tenant — vendor
 * (product / classified / travel), marketer or broker profile, or shipping
 * company — its membership in the country, the documents and agreement
 * signature moved from the application to the tenant, and the welcome mail.
 */
class TenantProvisioner
{
    public function __construct(private CountryContext $countryContext, private MarketerProfiles $marketers) {}

    /**
     * @return array{user: User, tenant_type: string, tenant_id: int}
     */
    public function provision(PartnerApplication $application, int $adminUserId): array
    {
        $country = $this->countryContext->current();
        $temporaryPassword = null;
        $user = User::query()->where('email', $application->email)->first();
        $existing = $user !== null;

        if ($user === null) {
            if ($application->password_hash === null) {
                $temporaryPassword = Str::password(14, symbols: false);
            }

            $user = User::query()->create([
                'name' => $application->name,
                'email' => $application->email,
                'phone' => $application->phone,
                'password' => $application->password_hash ?? Hash::make($temporaryPassword),
                'preferred_locale' => $application->locale,
                'home_country_id' => $country->id,
            ]);
            $user->forceFill(['email_verified_at' => $application->email_verified_at])->save();
        } elseif ($user->email_verified_at === null) {
            // QA-002: the applicant proved this inbox with the application code.
            $user->forceFill(['email_verified_at' => $application->email_verified_at])->save();
        }

        [$tenantType, $tenantId, $signerType, $documentOwnerType] = match ($application->type) {
            ApplicationType::Vendor, ApplicationType::Classified => $this->vendor($application, $user, $application->type === ApplicationType::Vendor ? 'product' : 'classified', $application->type->membershipRole()),
            ApplicationType::TravelAgency => $this->travelAgency($application, $user),
            ApplicationType::Marketer, ApplicationType::Broker => $this->marketer($application, $user, $adminUserId),
            ApplicationType::Carrier => $this->carrier($application, $user),
        };

        Document::query()->where('owner_type', PartnerApplication::DOCUMENT_OWNER)->where('owner_id', $application->id)
            ->update(['owner_type' => $documentOwnerType, 'owner_id' => $tenantId]);
        ContractSignature::query()->where('signer_type', SignerType::Applicant)->where('signer_id', $application->id)
            ->update(['signer_type' => $signerType, 'signer_id' => $tenantId, 'signer_user_id' => $user->id]);

        $user->notify(new PartnerWelcomeNotification(
            $application->type->label(),
            $country->name_en,
            Panel::find($application->type->panel())->url('/login'),
            $existing,
            $temporaryPassword,
            $existing ? URL::temporarySignedRoute(Panel::find($application->type->panel())->key.'.password.setup', now()->addDays(3), ['user' => $user->id]) : null,
        ));

        return ['user' => $user, 'tenant_type' => $tenantType, 'tenant_id' => $tenantId];
    }

    /**
     * @return array{0: string, 1: int, 2: SignerType, 3: string}
     */
    private function vendor(PartnerApplication $application, User $user, string $vendorType, MembershipRole $role): array
    {
        $store = $application->step('store');
        $vendor = Vendor::query()->create([
            'owner_user_id' => $user->id,
            'type' => $vendorType,
            'slug' => $this->uniqueSlug($store['slug'] ?? $store['store_name']),
            'store_name' => $store['store_name'],
            'contact_phone' => $application->phone,
            'status' => Vendor::STATUS_ACTIVE,
            'seller_agreement_accepted_at' => now(),
            'business_details' => ['store' => $store, 'address' => $application->step('address'), 'application' => $application->number],
        ]);
        $this->membership($user, $role, PanelCountries::VENDOR_TENANT, $vendor->id);

        return [PanelCountries::VENDOR_TENANT, $vendor->id, SignerType::Vendor, 'vendor'];
    }

    /**
     * @return array{0: string, 1: int, 2: SignerType, 3: string}
     */
    private function travelAgency(PartnerApplication $application, User $user): array
    {
        $agency = $application->step('agency');
        $vendor = Vendor::query()->create([
            'owner_user_id' => $user->id,
            'type' => 'travel',
            'slug' => $this->uniqueSlug($agency['agency_name']),
            'store_name' => $agency['agency_name'],
            'contact_phone' => $application->phone,
            'logo_path' => $application->logo_path,
            'status' => Vendor::STATUS_ACTIVE,
            'business_details' => ['agency' => $agency, 'application' => $application->number],
        ]);
        $this->membership($user, MembershipRole::TravelAgency, PanelCountries::VENDOR_TENANT, $vendor->id);

        return [PanelCountries::VENDOR_TENANT, $vendor->id, SignerType::Agency, 'vendor'];
    }

    /**
     * @return array{0: string, 1: int, 2: SignerType, 3: string}
     */
    private function marketer(PartnerApplication $application, User $user, int $adminUserId): array
    {
        $profile = $application->step('profile');
        $jobTypes = $application->type === ApplicationType::Broker ? ['broker'] : $profile['job_types'];
        $marketer = $this->marketers->register($user, $jobTypes, [
            'display_name' => $profile['display_name'],
            'bio' => $profile['bio'] ?? null,
            'whatsapp_phone' => $profile['whatsapp_phone'] ?? null,
            'socials' => $profile['socials'] ?? null,
            'followers' => $profile['followers'] ?? 0,
        ]);
        $marketer->forceFill(['details' => collect($profile)->except(['display_name', 'bio', 'whatsapp_phone', 'socials', 'followers', 'job_types'])->all() + ['application' => $application->number]])->save();
        $this->marketers->transition($marketer, MarketerStatus::Active, $adminUserId, 'Application '.$application->number.' approved');

        return ['marketer', $marketer->id, SignerType::Marketer, 'marketer'];
    }

    /**
     * @return array{0: string, 1: int, 2: SignerType, 3: string}
     */
    private function carrier(PartnerApplication $application, User $user): array
    {
        $company = $application->step('company');
        $carrier = Carrier::query()->create([
            'owner_user_id' => $user->id,
            'code' => $this->carrierCode($company['legal_name']),
            'name' => $company['legal_name'],
            'licence_number' => $company['licence_number'],
            'contact_name' => $application->name,
            'contact_email' => $application->email,
            'contact_phone' => $application->phone,
            'status' => CarrierStatus::Active,
            'details' => collect($company)->except(['legal_name', 'licence_number'])->all() + ['application' => $application->number],
        ]);
        $this->membership($user, MembershipRole::CarrierSupervisor, Carrier::TENANT, $carrier->id);

        return [Carrier::TENANT, $carrier->id, SignerType::Agency, 'carrier'];
    }

    private function membership(User $user, MembershipRole $role, string $tenantType, int $tenantId): void
    {
        $user->memberships()->firstOrCreate(
            ['role' => $role, 'country_id' => $this->countryContext->current()->id, 'tenant_type' => $tenantType, 'tenant_id' => $tenantId],
            ['status' => 'active'],
        );
    }

    private function uniqueSlug(string $base): string
    {
        $slug = Str::slug($base) ?: 'store';
        $candidate = $slug;

        while (Vendor::query()->where('slug', $candidate)->exists()) {
            $candidate = $slug.'-'.Str::lower(Str::random(4));
        }

        return $candidate;
    }

    private function carrierCode(string $name): string
    {
        $base = Str::upper(Str::limit(Str::slug($name, ''), 20, '')) ?: 'CARRIER';
        $code = $base;

        while (Carrier::query()->where('code', $code)->exists()) {
            $code = $base.'-'.Str::upper(Str::random(4));
        }

        return $code;
    }
}
