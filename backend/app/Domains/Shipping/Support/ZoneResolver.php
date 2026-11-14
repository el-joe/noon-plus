<?php

namespace App\Domains\Shipping\Support;

use App\Domains\Shipping\Models\ShippingZone;
use App\Domains\Shipping\Models\ShippingZoneArea;

/**
 * Finds the shipping zone for a destination: an exact city + area match
 * first, then a whole-city match, then the country's default zone.
 */
class ZoneResolver
{
    public function resolve(ShippingDestination $destination): ?ShippingZone
    {
        $zoneId = null;

        if (filled($destination->city)) {
            $zoneId = ShippingZoneArea::query()
                ->join('shipping_zones', 'shipping_zones.id', '=', 'shipping_zone_areas.shipping_zone_id')
                ->where('shipping_zones.is_active', true)
                ->where('shipping_zone_areas.city', $destination->city)
                ->where(fn ($query) => $query
                    ->when(filled($destination->area), fn ($area) => $area->where('shipping_zone_areas.area', $destination->area))
                    ->orWhereNull('shipping_zone_areas.area'))
                ->orderByRaw('shipping_zone_areas.area is null')
                ->orderBy('shipping_zone_areas.id')
                ->value('shipping_zone_areas.shipping_zone_id');
        }

        $query = ShippingZone::query()->where('is_active', true)
            ->with(['rates' => fn ($rates) => $rates->where('is_active', true)->with('slabs')]);

        return $zoneId !== null
            ? $query->find($zoneId)
            : $query->where('is_default', true)->orderBy('id')->first();
    }
}
