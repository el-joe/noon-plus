<?php

namespace App\Domains\Customers\Support;

use App\Domains\Customers\Models\CustomerAddress;
use App\Domains\Shipping\Support\ShippingDestination;
use App\Domains\Shipping\Support\ZoneResolver;
use App\Shared\StateMachine\TransitionRefused;
use App\Shared\Validation\CountryPhone;
use Illuminate\Support\Facades\DB;

/**
 * The customer address book (G-W5): at most {@see self::MAX_ADDRESSES}
 * per country, exactly one default while any exist (the first address
 * becomes the default; deleting the default promotes the newest other).
 */
class AddressBook
{
    public const MAX_ADDRESSES = 20;

    public function __construct(private ZoneResolver $zones) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(int $customerUserId, array $data): CustomerAddress
    {
        return DB::connection((new CustomerAddress)->getConnectionName())->transaction(function () use ($customerUserId, $data): CustomerAddress {
            $existing = CustomerAddress::query()->where('customer_user_id', $customerUserId)->lockForUpdate()->count();

            if ($existing >= self::MAX_ADDRESSES) {
                throw new TransitionRefused(__('You can save up to :count addresses.', ['count' => self::MAX_ADDRESSES]));
            }

            $makeDefault = $existing === 0 || (bool) ($data['is_default'] ?? false);
            $address = CustomerAddress::query()->create($this->attributes($data) + ['customer_user_id' => $customerUserId, 'is_default' => false]);

            if ($makeDefault) {
                $this->makeDefault($address);
            }

            return $address->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CustomerAddress $address, array $data): CustomerAddress
    {
        return DB::connection($address->getConnectionName())->transaction(function () use ($address, $data): CustomerAddress {
            $address->fill($this->attributes($data + $address->only(['city', 'area', 'phone'])))->save();

            if ((bool) ($data['is_default'] ?? false)) {
                $this->makeDefault($address);
            }

            return $address->refresh();
        });
    }

    public function makeDefault(CustomerAddress $address): void
    {
        DB::connection($address->getConnectionName())->transaction(function () use ($address): void {
            CustomerAddress::query()->where('customer_user_id', $address->customer_user_id)->whereKeyNot($address->id)->update(['is_default' => false]);
            CustomerAddress::query()->whereKey($address->id)->update(['is_default' => true]);
        });
    }

    public function delete(CustomerAddress $address): void
    {
        DB::connection($address->getConnectionName())->transaction(function () use ($address): void {
            $address->delete();

            if ($address->is_default) {
                $next = CustomerAddress::query()->where('customer_user_id', $address->customer_user_id)->latest('id')->first();

                if ($next !== null) {
                    $this->makeDefault($next);
                }
            }
        });
    }

    /**
     * Normalised columns: phone in E.164, zone resolved from city + area.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $attributes = array_intersect_key($data, array_flip([
            'label', 'name', 'phone', 'city', 'area', 'street', 'building', 'apartment', 'landmark', 'latitude', 'longitude',
        ]));

        if (isset($attributes['phone'])) {
            $attributes['phone'] = CountryPhone::normalize((string) $attributes['phone']) ?? $attributes['phone'];
        }

        if (array_key_exists('city', $data) || array_key_exists('area', $data)) {
            $attributes['shipping_zone_id'] = $this->zones->resolve(new ShippingDestination($data['city'] ?? null, $data['area'] ?? null))?->id;
        }

        return $attributes;
    }
}
