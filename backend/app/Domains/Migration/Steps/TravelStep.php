<?php

namespace App\Domains\Migration\Steps;

use App\Domains\Migration\Support\MigrationContext;
use Illuminate\Support\Str;

/**
 * Travel (MG7, rule R2 via the agency; plan §6). A legacy package had one
 * departure and one per-person price (`price × travellers`, whatever the
 * rooming), so it becomes a package with one departure (its dates, seat
 * capacity and seats booked) offering that per-person price on every adult
 * room type. Packages priced by group-size tiers have no per-person
 * equivalent and are quarantined (`pricing_model_unsupported`), as are
 * packages with unlimited seats (new departures need a capacity). Bookings
 * keep their number, travellers and exact total on a single `per_person`
 * room line; a booking whose total is not price × travellers (a bookable
 * unit was added) is quarantined. Passport files and contract signatures
 * stay in `legacy_records` next to the booking.
 */
class TravelStep extends Step
{
    /**
     * Room types a legacy per-person price applies to.
     *
     * @var array<int, string>
     */
    public const ADULT_ROOMS = ['single', 'double', 'triple', 'quad'];

    /**
     * Legacy booking status → new booking status.
     *
     * @var array<string, string>
     */
    public const BOOKING_STATUSES = ['pending_documents' => 'requested', 'confirmed' => 'confirmed', 'cancelled' => 'cancelled', 'completed' => 'completed'];

    public function name(): string
    {
        return 'travel';
    }

    protected function order(): array
    {
        return ['travel_packages', 'travel_bookings'];
    }

    protected function migrateTravelPackages(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $id = (string) $row['id'];
            $code = $this->singleCountry($table, $row);

            if ($code === null) {
                continue;
            }

            $plan = $this->packagePlan($row, $code);

            if (is_string($plan)) {
                $this->quarantine->put($table, $id, strtok($plan, ':') ?: 'unresolved', $plan, $code, $row);

                continue;
            }

            $write = function () use ($table, $id, $code, $plan, $row): void {
                $packageId = $this->writer->upsert($table, $id, $code, 'travel_packages', $plan);
                $departureId = $this->writer->upsert($table, $id.':departure', $code, 'travel_departures', [
                    'travel_package_id' => $packageId,
                    'departs_on' => $row['departure_date'],
                    'returns_on' => $row['return_date'],
                    'seat_capacity' => (int) $row['available_seats'],
                    'seats_reserved' => (int) $row['seats_booked'],
                    'status' => $row['status'] === 'active' ? 'open' : 'closed',
                    ...$this->timestamps($row),
                ], countMetrics: false);

                foreach (self::ADULT_ROOMS as $roomType) {
                    $this->writer->upsert($table, $id.':room:'.$roomType, $code, 'travel_departure_rooms', [
                        'travel_departure_id' => $departureId,
                        'room_type' => $roomType,
                        'price_per_person' => $row['price'],
                        ...$this->timestamps($row),
                    ], countMetrics: false);
                }
            };

            $this->context->dryRun ? $write() : MigrationContext::db($code)->transaction($write);

            if ($row['contract_file_path'] !== null) {
                $this->keepLegacyRow($table, $id, $row, $code);
            }
        }
    }

    protected function migrateTravelBookings(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $id = (string) $row['id'];
            $legacyPackageId = (string) $row['travel_package_id'];
            $targets = $this->ids->targets('travel_packages', $legacyPackageId, 'travel_packages');

            if ($targets === []) {
                $reason = $this->quarantine->has('travel_packages', $legacyPackageId) ? 'parent_quarantined' : 'parent_unmigrated';
                $this->quarantine->put($table, $id, $reason, "package {$legacyPackageId} not migrated", null, $row);

                continue;
            }

            $code = (string) array_key_first($targets);

            if (! $this->context->allows($code)) {
                $this->context->count($table, 'skipped_other_country');

                continue;
            }

            $plan = $this->bookingPlan($row, $code, $targets[$code]);

            if (is_string($plan)) {
                $this->quarantine->put($table, $id, strtok($plan, ':') ?: 'unresolved', $plan, $code, $row);

                continue;
            }

            $this->writer->upsert($table, $id, $code, 'travel_bookings', $plan);

            if ($row['passport_file_path'] !== null || $row['contract_signature_data'] !== null || $row['cancellation_reason'] !== null) {
                $this->keepLegacyRow($table, $id, $row, $code);
            }

            $this->context->sum($table, $plan['currency'], (string) $row['total_price']);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|string
     */
    private function packagePlan(array $row, string $code): array|string
    {
        if ((bool) $row['pricing_tiers_enabled']) {
            return 'pricing_model_unsupported: priced by group-size tiers (new trips price per person per room)';
        }

        if ($row['available_seats'] === null) {
            return 'shape_unsupported: unlimited seats (a new departure needs a seat capacity)';
        }

        if (strtoupper((string) $row['currency']) !== $this->countries->currencyOf($code)) {
            return "currency_mismatch: package in {$row['currency']}";
        }

        $vendorId = $this->ids->find('travel_agencies', (string) $row['travel_agency_id'], $code, 'vendors');

        if ($vendorId === null) {
            return "parent_unmigrated: travel agency not migrated to {$code}";
        }

        $city = $row['destination_city'] ?: ($row['destination_travel_city_id'] !== null && $this->source->hasTable('travel_cities') ? ($this->source->find('travel_cities', (string) $row['destination_travel_city_id'])['name_en'] ?? null) : null);
        $country = $row['destination_country'] ?: ($row['destination_travel_country_id'] !== null && $this->source->hasTable('travel_countries') ? ($this->source->find('travel_countries', (string) $row['destination_travel_country_id'])['name_en'] ?? null) : null);

        if ($city === null || $city === '') {
            return 'shape_unsupported: no destination city';
        }

        $own = $this->ids->find('travel_packages', (string) $row['id'], $code, 'travel_packages');

        return [
            'vendor_id' => $vendorId,
            'slug' => $this->writer->unique($code, 'travel_packages', 'slug', Str::slug((string) $row['slug']) ?: Str::slug((string) $row['title_en']), $own, [], 160),
            'title_en' => (string) ($row['title_en'] ?: $row['title_ar']),
            'title_ar' => (string) ($row['title_ar'] ?: $row['title_en']),
            'description_en' => $row['description_en'],
            'description_ar' => $row['description_ar'],
            'trip_type' => 'holiday',
            'destination_city' => mb_substr((string) $city, 0, 80),
            'destination_country' => $country === null ? null : mb_substr((string) $country, 0, 80),
            'nights' => (int) $row['duration_nights'],
            'status' => $row['status'],
            'rejection_reason' => $row['rejection_reason'] === null ? null : mb_substr((string) $row['rejection_reason'], 0, 255),
            'approved_at' => $row['approved_at'],
            ...$this->timestamps($row),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|string
     */
    private function bookingPlan(array $row, string $code, int $packageId): array|string
    {
        if ($row['bookable_unit_id'] !== null) {
            return 'shape_unsupported: booking of a bookable unit (chalet / room nights have no new equivalent)';
        }

        $package = $this->source->find('travel_packages', (string) $row['travel_package_id']);
        $travellers = (int) $row['travelers_count'];
        $expected = bcmul(self::decimal($package['price'] ?? null), (string) $travellers, 4);

        if ($travellers < 1 || bccomp($expected, (string) $row['total_price'], 4) !== 0) {
            return "totals_mismatch: price × travellers = {$expected}, legacy total = {$row['total_price']}";
        }

        $userId = $this->userOf('customers', (string) $row['customer_id']);
        $user = $userId === null ? null : MigrationContext::db(MigrationContext::CENTRAL)->table('users')->where('id', $userId)->first(['name', 'email', 'phone', 'preferred_locale']);

        if ($userId === null || ($user === null && ! $this->context->dryRun)) {
            return 'owner_unresolved: customer not migrated';
        }

        $marketerProfileId = $row['marketer_id'] === null ? null : $this->ids->find('marketers', (string) $row['marketer_id'], $code, 'marketer_profiles');
        $own = $this->ids->find('travel_bookings', (string) $row['id'], $code, 'travel_bookings');
        $status = self::BOOKING_STATUSES[$row['status']];

        return [
            'number' => $this->writer->unique($code, 'travel_bookings', 'number', (string) $row['booking_number'], $own, [], 32),
            'customer_user_id' => $userId,
            'contact_name' => mb_substr((string) ($user->name ?? ''), 0, 120),
            'contact_email' => (string) ($user->email ?? ''),
            'contact_phone' => mb_substr((string) ($user->phone ?? ''), 0, 24),
            'locale' => $user->preferred_locale ?? null,
            'message' => null,
            'access_token' => hash_hmac('sha1', 'legacy-travel-booking:'.$row['id'], (string) config('app.key')),
            'vendor_id' => (int) ($packageId > 0 ? MigrationContext::db($code)->table('travel_packages')->where('id', $packageId)->value('vendor_id') : 0),
            'travel_package_id' => $packageId,
            'travel_departure_id' => $this->ids->find('travel_packages', $row['travel_package_id'].':departure', $code, 'travel_departures'),
            'rooms' => [['room_type' => 'per_person', 'travellers' => $travellers, 'unit_price' => (string) $package['price'], 'line_total' => (string) $row['total_price']]],
            'seats' => $travellers,
            'currency' => strtoupper((string) $package['currency']),
            'total' => $row['total_price'],
            'status' => $status,
            'hold_expires_at' => null,
            'marketer_profile_id' => $marketerProfileId,
            'referral_code' => $row['referral_code'] !== null && strlen((string) $row['referral_code']) <= 16 ? $row['referral_code'] : null,
            'confirmation_number' => null,
            'decision_reason' => $row['cancellation_reason'] === null ? null : mb_substr((string) $row['cancellation_reason'], 0, 255),
            'requested_at' => $row['created_at'],
            'confirmed_at' => in_array($status, ['confirmed', 'completed'], true) ? ($row['contract_signed_at'] ?? $row['updated_at']) : null,
            'completed_at' => $status === 'completed' ? ($row['completed_at'] ?? $row['updated_at']) : null,
            'cancelled_at' => $status === 'cancelled' ? $row['updated_at'] : null,
            ...$this->timestamps($row),
        ];
    }
}
