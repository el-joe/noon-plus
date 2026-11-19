<?php

namespace App\Domains\Migration\Steps;

use App\Domains\Migration\Support\MigrationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Identity consolidation (plan §5, rule R7). Every legacy login (customer,
 * vendor admin, admin, marketer admin, delivery agent, travel agency member,
 * shipping supervisor, and the vendor / agency accounts themselves) becomes
 * one central `users` row per person — matched by lower-cased email — with
 * role memberships per country (and tenant), plus the country profile rows
 * (vendors, marketer_profiles, carriers, delivery_agents). bcrypt hashes are
 * copied, so people keep their passwords (D9: email is the customer login;
 * a phone-only customer gets a placeholder address and `requires_email`).
 * Two accounts with the same email but different verified phones are not
 * merged: the second one is quarantined for manual review.
 */
class IdentityStep extends Step
{
    /**
     * Legacy admin role → new admin role (role-permission matrix §3.2).
     * `super_admin` becomes a country-less admin membership.
     *
     * @var array<string, string>
     */
    public const ADMIN_ROLES = [
        'operations_admin' => 'operations',
        'finance_admin' => 'finance',
        'marketing_admin' => 'marketing',
        'vendor_relations_admin' => 'vendor_relations',
    ];

    /**
     * Legacy tenant roles → new staff roles (TenantPermissions::MATRIX); null = owner.
     *
     * @var array<string, string|null>
     */
    public const TENANT_ROLES = [
        'vendor_owner' => null,
        'vendor_manager' => 'manager',
        'vendor_staff' => 'viewer',
        'travel_agency_owner' => null,
        'travel_agency_manager' => 'operations',
        'travel_agency_staff' => 'viewer',
    ];

    /**
     * @var array<string, int>|null
     */
    private ?array $centralCountries = null;

    /**
     * Dry runs write no users: people "created" so far, by email, so merges and conflicts resolve as in a real run.
     *
     * @var array<string, object{id: int, phone: string|null}>
     */
    private array $dryRunUsers = [];

    public function name(): string
    {
        return 'identity';
    }

    protected function order(): array
    {
        return ['admins', 'customers', 'vendors', 'vendor_admins', 'travel_agencies', 'travel_agency_members', 'marketers', 'marketer_admins', 'marketer_profiles', 'shipping_companies', 'shipping_company_supervisors', 'delivery_agents', 'addresses'];
    }

    protected function migrateAdmins(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $userId = $this->person($table, $row, (string) $row['name'], $row['email'], $row['phone'], $row['password'], null, 'active', null, null);

            if ($userId === null) {
                continue;
            }

            $status = $row['status'] === 'active' ? 'active' : 'suspended';
            $roles = $this->legacyRoles('App\\Models\\Admin', (string) $row['id']);

            if (in_array('super_admin', $roles, true)) {
                $this->membership($table, (string) $row['id'], $userId, 'admin', null, $status);

                continue;
            }

            $own = $this->countries->code($row['country_id'] === null ? null : (string) $row['country_id']);

            foreach ($own === null ? $this->context->targetCountries() : array_filter([$own], fn (string $code): bool => $this->context->allows($code)) as $code) {
                $this->membership($table, (string) $row['id'], $userId, 'admin', $code, $status);

                foreach ($roles as $role) {
                    $this->assignAdminRole($table, $userId, $role, $code);
                }
            }
        }
    }

    protected function migrateCustomers(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $status = match ($row['status']) {
                'active', 'suspended', 'banned' => $row['status'],
                default => null,
            };

            if ($status === null) {
                $this->quarantine->put($table, (string) $row['id'], 'status_unmapped', "customer status {$row['status']} has no new equivalent", null, $this->masked($row));

                continue;
            }

            $home = $this->countries->code($row['country_id'] === null ? null : (string) $row['country_id']);
            $userId = $this->person($table, $row, (string) $row['name'], $row['email'], $row['phone'], $row['password'], $row['email_verified_at'], $status, $row['locale'], $home, [
                'email' => (bool) $row['marketing_email_enabled'],
                'sms' => (bool) $row['marketing_sms_enabled'],
                'whatsapp' => (bool) $row['marketing_whatsapp_enabled'],
            ]);

            if ($userId === null) {
                continue;
            }

            $codes = $home === null ? [] : [$home];

            foreach ($this->source->connection()->table('orders')->where('customer_id', $row['id'])->whereNotNull('country_id')->distinct()->pluck('country_id') as $countryId) {
                $code = $this->countries->code((string) $countryId);

                if ($code !== null && ! in_array($code, $codes, true)) {
                    $codes[] = $code;
                }
            }

            if ($codes === []) {
                $this->quarantine->put($table, (string) $row['id'], 'country_unresolved', 'customer has no country and no order in a launched country (user created, no country profile)', null, $this->masked($row));
            }

            foreach ($codes as $code) {
                if ($this->context->allows($code)) {
                    $this->membership($table, (string) $row['id'], $userId, 'customer', $code, $status === 'active' ? 'active' : $status);
                }
            }
        }
    }

    /**
     * Product and classified vendors: one `vendors` row per country they sell in.
     */
    protected function migrateVendors(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $id = (string) $row['id'];
            $status = match ($row['global_status']) {
                'active' => 'active',
                'pending', 'under_review', 'inactive' => 'on_hold',
                'suspended' => 'suspended',
                'blacklisted' => 'blacklisted',
                default => null,
            };

            if ($status === null) {
                $this->quarantine->put($table, $id, 'status_unmapped', "vendor status {$row['global_status']} (an application, not a store)", null, $this->masked($row));

                continue;
            }

            $resolution = $this->countries->resolveRow($table, $row);

            if ($resolution['codes'] === []) {
                $this->quarantine->put($table, $id, $this->reasonCode($resolution['reason']), $resolution['reason'], null, $this->masked($row));

                continue;
            }

            $ownerAdmin = $this->source->connection()->table('vendor_admins')->where('vendor_id', $id)->where('is_owner', 1)->orderBy('created_at')->first();
            $ownerUserId = $ownerAdmin !== null
                ? $this->person('vendor_admins', (array) $ownerAdmin, (string) $ownerAdmin->name, $ownerAdmin->email, null, $ownerAdmin->password, $ownerAdmin->email_verified_at, 'active', null, $resolution['codes'][0])
                : null;
            $accountUserId = $this->person($table, $row, (string) $row['name'], $row['email'], $row['phone'], $row['password'], $row['email_verified_at'], 'active', null, $resolution['codes'][0]);
            $ownerUserId ??= $accountUserId;

            if ($ownerUserId === null) {
                $this->quarantine->put($table, $id, 'owner_unresolved', 'vendor has no owner account', null, $this->masked($row));

                continue;
            }

            $type = $row['vendor_type'] === 'classified_vendor' ? 'classified' : 'product';
            $address = $row['business_address_id'] ? $this->source->find('addresses', (string) $row['business_address_id']) : null;

            foreach ($resolution['codes'] as $code) {
                if (! $this->context->allows($code)) {
                    $this->context->count($table, 'skipped_other_country');

                    continue;
                }

                $vendorId = $this->writer->upsert($table, $id, $code, 'vendors', [
                    'owner_user_id' => $ownerUserId,
                    'type' => $type,
                    'slug' => $this->writer->unique($code, 'vendors', 'slug', (string) ($row['store_slug'] ?: Str::slug((string) $row['store_name'])), $this->ids->find($table, $id, $code, 'vendors')),
                    'store_name' => (string) ($row['store_name'] ?: $row['name']),
                    'contact_phone' => $this->phone($row['contact_phone'] ?? $row['phone']),
                    'logo_path' => null,
                    'business_details' => array_filter([
                        'business_name' => $row['business_name'],
                        'business_type' => $row['business_type'],
                        'registration_number' => $row['business_registration_number'],
                        'tax_id' => $row['tax_id'],
                        'contact_email' => $row['contact_email'],
                        'whatsapp' => $row['whatsapp_number'],
                        'description_en' => $row['store_description'],
                        'description_ar' => $row['store_description_ar'],
                        'address' => $address === null ? null : array_filter(array_intersect_key($address, array_flip(['label', 'area', 'street_address', 'building', 'floor', 'apartment', 'postal_code', 'landmark']))),
                        'legacy_commission_rate' => $row['commission_rate'],
                    ], fn ($value): bool => $value !== null && $value !== ''),
                    'status' => $status,
                    'rating' => $row['store_rating_avg'],
                    'rating_count' => (int) ($row['store_rating_count'] ?? 0),
                    'verified_at' => $status === 'active' ? $row['approved_at'] : null,
                    'payout_schedule' => $row['payout_schedule'] ?? 'weekly',
                    ...$this->timestamps($row),
                ]);

                $role = $type === 'classified' ? 'vendor:classified' : 'vendor:product';
                $this->membership($table, $id, $ownerUserId, $role, $code, 'active', 'vendor', $vendorId, null);

                if ($accountUserId !== null && $accountUserId !== $ownerUserId) {
                    $this->membership($table, $id.':account', $accountUserId, $role, $code, 'active', 'vendor', $vendorId, 'manager');
                }
            }
        }
    }

    protected function migrateVendorAdmins(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $vendor = $this->source->find('vendors', (string) $row['vendor_id']);
            $vendorTargets = $this->ids->targets('vendors', (string) $row['vendor_id'], 'vendors');

            if ($vendor === null || $vendorTargets === []) {
                $this->quarantine->put($table, (string) $row['id'], 'parent_unmigrated', 'vendor not migrated (quarantined or missing)', null, $this->masked($row));

                continue;
            }

            $userId = $this->person($table, $row, (string) $row['name'], $row['email'], null, $row['password'], $row['email_verified_at'], 'active', null, array_key_first($vendorTargets));

            if ($userId === null) {
                continue;
            }

            $legacyRole = $this->legacyRoles('App\\Models\\VendorAdmin', (string) $row['id'])[0] ?? $row['role'];
            $tenantRole = array_key_exists((string) $legacyRole, self::TENANT_ROLES) ? self::TENANT_ROLES[$legacyRole] : 'viewer';
            $role = $vendor['vendor_type'] === 'classified_vendor' ? 'vendor:classified' : 'vendor:product';

            foreach ($vendorTargets as $code => $vendorId) {
                if ($this->context->allows($code)) {
                    $this->membership($table, (string) $row['id'], $userId, $role, $code, $row['is_active'] ? 'active' : 'suspended', 'vendor', $vendorId, $row['is_owner'] ? null : $tenantRole);
                }
            }
        }
    }

    /**
     * Travel agencies are vendors of type travel (V10) with travel_agency memberships.
     */
    protected function migrateTravelAgencies(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $id = (string) $row['id'];
            $code = $this->singleCountry($table, $row);

            if ($code === null) {
                continue;
            }

            $owner = $this->source->connection()->table('travel_agency_members')->where('travel_agency_id', $id)->where('is_owner', 1)->first();
            $accountUserId = $this->person($table, $row, (string) $row['name'], $row['email'], $row['phone'], $row['password'], null, 'active', null, $code);
            $ownerUserId = $owner !== null ? $this->person('travel_agency_members', (array) $owner, (string) $owner->name, $owner->email, $owner->phone, $owner->password, $owner->email_verified_at, 'active', null, $code) : $accountUserId;

            if ($ownerUserId === null) {
                continue;
            }

            $vendorId = $this->writer->upsert($table, $id, $code, 'vendors', [
                'owner_user_id' => $ownerUserId,
                'type' => 'travel',
                'slug' => $this->writer->unique($code, 'vendors', 'slug', Str::slug((string) $row['name']), $this->ids->find($table, $id, $code, 'vendors')),
                'store_name' => (string) $row['name'],
                'contact_phone' => $this->phone($row['phone']),
                'business_details' => array_filter(['licence_number' => $row['license_number'], 'contact_email' => $row['email']]),
                'status' => match ($row['status']) {
                    'active' => 'active',
                    'suspended' => 'suspended',
                    default => 'on_hold',
                },
                'verified_at' => $row['status'] === 'active' ? $row['approved_at'] : null,
                'payout_schedule' => 'weekly',
                ...$this->timestamps($row),
            ]);

            $this->membership($table, $id, $ownerUserId, 'travel_agency', $code, 'active', 'vendor', $vendorId, null);
        }
    }

    protected function migrateTravelAgencyMembers(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $code = $this->singleCountry($table, $row);
            $vendorId = $code === null ? null : $this->ids->find('travel_agencies', (string) $row['travel_agency_id'], $code, 'vendors');

            if ($code === null) {
                continue;
            }

            if ($vendorId === null) {
                $this->quarantine->put($table, (string) $row['id'], 'parent_unmigrated', 'travel agency not migrated', $code, $this->masked($row));

                continue;
            }

            $userId = $this->person($table, $row, (string) $row['name'], $row['email'], $row['phone'], $row['password'], $row['email_verified_at'], 'active', null, $code);

            if ($userId === null) {
                continue;
            }

            $legacyRole = $this->legacyRoles('App\\Models\\TravelAgencyMember', (string) $row['id'])[0] ?? $row['role'];
            $tenantRole = array_key_exists((string) $legacyRole, self::TENANT_ROLES) ? self::TENANT_ROLES[$legacyRole] : 'viewer';
            $this->membership($table, (string) $row['id'], $userId, 'travel_agency', $code, $row['is_active'] ? 'active' : 'suspended', 'vendor', $vendorId, $row['is_owner'] ? null : $tenantRole);
        }
    }

    /**
     * Marketers: one person (their owner login), a marketer profile in their
     * country, and one membership per job type.
     */
    protected function migrateMarketers(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $id = (string) $row['id'];
            $code = $this->singleCountry($table, $row);

            if ($code === null) {
                continue;
            }

            $owner = $this->source->connection()->table('marketer_admins')->where('marketer_id', $id)->where('is_owner', 1)->first();
            $userId = $owner !== null
                ? $this->person('marketer_admins', (array) $owner, (string) $owner->name, $owner->email, $row['phone'], $owner->password, $owner->email_verified_at ?? $row['email_verified_at'], 'active', null, $code)
                : $this->person($table, $row, (string) $row['name'], $row['email'], $row['phone'], null, $row['email_verified_at'], 'active', null, $code);

            if ($userId === null) {
                continue;
            }

            if ($owner !== null) {
                $this->writer->link($table, $id, MigrationContext::CENTRAL, 'users', $userId);
            }

            $jobs = $this->source->connection()->table('marketer_marketer_job')
                ->join('marketer_jobs', 'marketer_jobs.id', '=', 'marketer_marketer_job.marketer_job_id')
                ->where('marketer_marketer_job.marketer_id', $id)->orderBy('marketer_jobs.sort_order')->pluck('marketer_jobs.key')
                ->filter(fn (string $key): bool => in_array($key, ['influencer', 'affiliate', 'broker'], true))->values()->all();
            $jobs = $jobs === [] ? ['affiliate'] : $jobs;
            $status = match ($row['global_status']) {
                'active' => 'active',
                'pending', 'under_review' => 'pending',
                'rejected' => 'rejected',
                default => 'suspended',
            };
            $profile = $this->source->connection()->table('marketer_profiles')->where('marketer_id', $id)->first();
            $profile = $profile === null ? [] : (array) $profile;
            $slug = (string) (($profile['profile_slug'] ?? null) ?: Str::slug((string) $row['name']));

            $this->writer->upsert($table, $id, $code, 'marketer_profiles', [
                'user_id' => $userId,
                'slug' => $this->writer->unique($code, 'marketer_profiles', 'slug', $slug, $this->ids->find($table, $id, $code, 'marketer_profiles')),
                'display_name' => (string) $row['name'],
                'bio' => $profile['bio_en'] ?? $profile['bio_ar'] ?? null,
                'job_types' => $jobs,
                'global_status' => $status,
                'whatsapp_phone' => $this->phone($row['whatsapp_for_campaigns']),
                'socials' => json_decode((string) ($profile['social_links'] ?? 'null'), true),
                'details' => array_filter([
                    'bio_ar' => $profile['bio_ar'] ?? null,
                    'gender' => $profile['gender'] ?? null,
                    'video_url' => $profile['video_url'] ?? null,
                    'contact_details' => json_decode((string) ($profile['contact_details'] ?? 'null'), true),
                    'legacy_total_earnings' => $profile['total_earnings'] ?? $row['total_earnings'],
                    'legacy_earnings_currency' => $profile['earnings_currency'] ?? null,
                ], fn ($value): bool => $value !== null),
                'status_reason' => in_array($row['global_status'], ['inactive', 'blacklisted'], true) ? 'Legacy status: '.$row['global_status'] : $row['rejection_reason'],
                'approved_at' => $row['approved_at'],
                ...$this->timestamps($row),
            ]);

            foreach ($jobs as $job) {
                $this->membership($table, $id, $userId, 'marketer:'.$job, $code, $status === 'active' ? 'active' : ($status === 'pending' ? 'pending' : 'suspended'));
            }
        }
    }

    /**
     * Owners were consolidated with their marketer; other staff logins have
     * no equivalent in the new Creator Studio and are reviewed by hand.
     */
    protected function migrateMarketerAdmins(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            if ($row['is_owner']) {
                $this->context->count($table, 'consolidated');

                continue;
            }

            $this->quarantine->put($table, (string) $row['id'], 'membership_unsupported', 'non-owner marketer staff login has no Creator Studio equivalent', null, $this->masked($row));
        }
    }

    protected function migrateMarketerProfiles(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $targets = $this->ids->targets('marketers', (string) $row['marketer_id'], 'marketer_profiles');

            if ($targets === []) {
                $this->quarantine->put($table, (string) $row['id'], 'parent_unmigrated', 'marketer not migrated', null, $row);

                continue;
            }

            foreach ($targets as $code => $profileId) {
                $this->writer->link($table, (string) $row['id'], $code, 'marketer_profiles', $profileId, 'folded');
            }
        }
    }

    /**
     * Shipping companies become carriers in their country; the first supervisor owns it.
     */
    protected function migrateShippingCompanies(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $id = (string) $row['id'];
            $home = $this->singleCountry($table, $row);

            if ($home === null) {
                continue;
            }

            $served = array_filter(array_map(fn ($countryId): ?string => $this->countries->code((string) $countryId), (array) json_decode((string) $row['served_countries'], true)));

            foreach (array_values(array_unique([$home, ...$served])) as $code) {
                if ($this->context->allows($code)) {
                    $this->carrier($table, $row, $code);
                }
            }
        }
    }

    /**
     * A shipping company's carrier in one of the countries it serves.
     *
     * @param  array<string, mixed>  $row
     */
    private function carrier(string $table, array $row, string $code): void
    {
        $id = (string) $row['id'];

        $supervisor = $this->source->connection()->table('shipping_company_supervisors')->where('shipping_company_id', $id)->orderBy('created_at')->first();
        $ownerUserId = $supervisor === null ? null : $this->person('shipping_company_supervisors', (array) $supervisor, (string) $supervisor->name, $supervisor->email, $supervisor->phone, $supervisor->password, null, 'active', null, $code);

        $this->writer->upsert($table, $id, $code, 'carriers', [
            'owner_user_id' => $ownerUserId,
            'code' => $this->writer->unique($code, 'carriers', 'code', Str::upper(Str::limit(Str::slug((string) $row['name'], '_'), 28, '')), $this->ids->find($table, $id, $code, 'carriers'), [], 32),
            'name' => (string) $row['name'],
            'contact_email' => $row['contact_email'],
            'contact_phone' => $this->phone($row['contact_phone']),
            'is_internal' => false,
            'status' => match ($row['status']) {
                'active' => 'active',
                'suspended' => 'suspended',
                default => 'unavailable',
            },
            'details' => array_filter(['legal_name' => $row['legal_name'], 'served_countries' => json_decode((string) $row['served_countries'], true), 'served_cities' => json_decode((string) $row['served_cities'], true)]),
            ...$this->timestamps($row),
        ]);
    }

    protected function migrateShippingCompanySupervisors(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $carriers = $this->ids->targets('shipping_companies', (string) $row['shipping_company_id'], 'carriers');
            $code = $this->countries->code($row['country_id'] === null ? null : (string) $row['country_id']);
            $code = isset($carriers[$code]) ? $code : array_key_first($carriers);
            $carrierId = $code === null ? null : $carriers[$code];

            if ($carrierId === null) {
                $this->quarantine->put($table, (string) $row['id'], 'parent_unmigrated', 'shipping company not migrated', $code, $this->masked($row));

                continue;
            }

            $userId = $this->person($table, $row, (string) $row['name'], $row['email'], $row['phone'], $row['password'], null, 'active', null, $code);

            if ($userId !== null) {
                $this->membership($table, (string) $row['id'], $userId, 'carrier_supervisor', $code, $row['is_active'] ? 'active' : 'suspended', 'carrier', $carrierId, null);
            }
        }
    }

    protected function migrateDeliveryAgents(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $id = (string) $row['id'];
            $code = $this->singleCountry($table, $row);

            if ($code === null) {
                continue;
            }

            $carrierId = $row['shipping_company_id'] ? $this->ids->find('shipping_companies', (string) $row['shipping_company_id'], $code, 'carriers') : null;

            if ($row['shipping_company_id'] && $carrierId === null) {
                $this->quarantine->put($table, $id, 'parent_unmigrated', 'agent of a shipping company that was not migrated to '.$code, $code, $this->masked($row));

                continue;
            }

            $userId = $this->person($table, $row, (string) $row['name'], $row['email'], $row['phone'], $row['password'], null, 'active', null, $code);

            if ($userId === null) {
                continue;
            }

            $status = match ($row['status']) {
                'active', 'on_shift' => 'active',
                default => 'suspended',
            };

            $this->writer->upsert($table, $id, $code, 'delivery_agents', [
                'user_id' => $userId,
                'carrier_id' => $carrierId,
                'name' => (string) $row['name'],
                'phone' => $this->phone($row['phone']),
                'vehicle_type' => $row['vehicle_type'],
                'vehicle_plate' => $row['vehicle_plate'],
                'zone_ids' => [],
                'status' => $status,
                'last_lat' => $row['current_latitude'],
                'last_lng' => $row['current_longitude'],
                'last_seen_at' => $row['last_location_at'],
                ...$this->timestamps($row),
            ]);

            $this->membership($table, $id, $userId, 'delivery_agent', $code, $status, $carrierId === null ? null : 'carrier', $carrierId, null);
        }
    }

    /**
     * Vendor business addresses are folded into vendors.business_details;
     * every address is also archived in its country (warehouses have no table yet).
     */
    protected function migrateAddresses(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            if ($row['addressable_type'] === 'App\\Models\\Vendor') {
                $this->context->count($table, 'folded');
            }

            $code = $this->countries->code($row['country_id'] === null ? null : (string) $row['country_id']);
            $this->archiveRow($table, $row, 'R3', $code === null ? $this->countries->resolveRow($table, $row) : ['codes' => [$code], 'reason' => null], 'address_archive');
        }
    }

    /**
     * The central user of a legacy login: created, or merged by email.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, bool>|null  $marketing
     */
    private function person(string $table, array $row, string $name, ?string $email, ?string $phone, ?string $password, mixed $emailVerifiedAt, string $status, ?string $locale, ?string $homeCountry, ?array $marketing = null): ?int
    {
        $sourceId = (string) $row['id'];
        $mapped = $this->ids->find($table, $sourceId, MigrationContext::CENTRAL, 'users');

        if ($mapped !== null) {
            $this->context->count($table, 'unchanged');

            return $mapped;
        }

        $email = $email === null ? null : Str::lower(trim($email));
        $phone = $this->phone($phone);
        $requiresEmail = false;

        if ($email === null || $email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            if ($phone === null) {
                $this->quarantine->put($table, $sourceId, 'identity_unresolved', 'no email and no phone', $homeCountry, $this->masked($row));

                return null;
            }

            $email = 'phone-'.ltrim($phone, '+').'@legacy.nawy.invalid';
            $requiresEmail = true;
        }

        $existing = $this->dryRunUsers[$email] ?? DB::table('users')->where('email', $email)->first();

        if ($existing !== null) {
            if ($phone !== null && $existing->phone !== null && $this->phone($existing->phone) !== $phone) {
                $this->quarantine->put($table, $sourceId, 'identity_conflict', 'same email as user #'.$existing->id.' but a different phone — manual review', $homeCountry, $this->masked($row));

                return null;
            }

            $this->writer->link($table, $sourceId, MigrationContext::CENTRAL, 'users', (int) $existing->id);

            return (int) $existing->id;
        }

        $hash = is_string($password) && preg_match('/^\$2[aby]\$\d{2}\$/', $password) === 1 ? $password : null;

        $userId = $this->writer->upsert($table, $sourceId, MigrationContext::CENTRAL, 'users', [
            'legacy_id' => $sourceId,
            'name' => $name !== '' ? $name : Str::before($email, '@'),
            'email' => $email,
            'requires_email' => $requiresEmail,
            'phone' => $phone,
            'preferred_locale' => in_array($locale, ['en', 'ar'], true) ? $locale : ($homeCountry === null ? 'en' : config("nawy.countries.{$homeCountry}.default_locale", 'en')),
            'marketing_preferences' => $marketing,
            'status' => $status,
            'home_country_id' => $homeCountry === null ? null : $this->centralCountryId($homeCountry),
            'email_verified_at' => $requiresEmail ? null : $emailVerifiedAt,
            'password' => $hash ?? Hash::make(Str::random(40)),
            'password_set_at' => $hash !== null ? ($row['created_at'] ?? now()) : null,
            'last_login_at' => $row['last_login_at'] ?? null,
            ...$this->timestamps($row),
        ]);

        if ($this->context->dryRun) {
            $this->dryRunUsers[$email] = (object) ['id' => $userId, 'phone' => $phone];
        }

        return $userId;
    }

    private function membership(string $table, string $sourceKey, int $userId, string $role, ?string $countryCode, string $status, ?string $tenantType = null, ?int $tenantId = null, ?string $tenantRole = null): void
    {
        $countryId = $countryCode === null ? null : $this->centralCountryId($countryCode);
        $key = $sourceKey.'|'.$role.'|'.($countryCode ?? 'global');

        $this->writer->upsert($table, $key, MigrationContext::CENTRAL, 'role_memberships', [
            'user_id' => $userId,
            'role' => $role,
            'country_id' => $countryId,
            'status' => $status,
            'tenant_type' => $tenantType,
            'tenant_id' => $tenantId,
            'tenant_role' => $tenantRole,
            'created_at' => now()->startOfDay(),
            'updated_at' => now()->startOfDay(),
        ], ['user_id' => $userId, 'role' => $role, 'country_id' => $countryId, 'tenant_type' => $tenantType, 'tenant_id' => $tenantId], countMetrics: false);
        $this->context->count($table, 'memberships');
    }

    private function assignAdminRole(string $table, int $userId, string $legacyRole, string $countryCode): void
    {
        $roleName = self::ADMIN_ROLES[$legacyRole] ?? null;

        if ($roleName === null) {
            $this->context->count($table, 'admin_role_unmapped');

            return;
        }

        $roleId = DB::table('roles')->where('name', $roleName)->where('guard_name', 'web')->whereNull('country_id')->value('id');

        if ($roleId === null || $this->context->dryRun) {
            return;
        }

        DB::table('model_has_roles')->insertOrIgnore([
            'role_id' => $roleId,
            'model_type' => 'App\\Models\\User',
            'model_id' => $userId,
            'country_id' => $this->centralCountryId($countryCode),
        ]);
    }

    /**
     * Legacy Spatie role names of a login.
     *
     * @return array<int, string>
     */
    private function legacyRoles(string $modelType, string $id): array
    {
        if (! $this->source->hasTable('model_has_roles')) {
            return [];
        }

        return $this->source->connection()->table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', $modelType)->where('model_has_roles.model_uuid', $id)
            ->orderBy('roles.id')->pluck('roles.name')->all();
    }

    private function centralCountryId(string $code): int
    {
        $this->centralCountries ??= DB::table('countries')->pluck('id', 'code')->map(fn ($id): int => (int) $id)->all();

        return $this->centralCountries[$code];
    }

    /**
     * E.164-ish: "+" and digits; "00" prefixes become "+".
     */
    private function phone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with(trim($phone), '+')) {
            return mb_substr('+'.$digits, 0, 32);
        }

        return mb_substr(str_starts_with($digits, '00') ? '+'.substr($digits, 2) : $digits, 0, 32);
    }

    /**
     * A quarantine payload without secrets (PII is masked in reports, not here).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function masked(array $row): array
    {
        return array_diff_key($row, array_flip(['password', 'remember_token']));
    }
}
