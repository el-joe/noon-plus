<?php

namespace App\Domains\Customers\Http\Resources;

use App\Domains\Customers\Models\CustomerAddress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerAddress
 */
class AddressResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'name' => $this->name,
            'phone' => $this->phone,
            'city' => $this->city,
            'area' => $this->area,
            'street' => $this->street,
            'building' => $this->building,
            'apartment' => $this->apartment,
            'landmark' => $this->landmark,
            'zone' => $this->zone === null ? null : ['id' => $this->zone->id, 'name' => $this->zone->name],
            'location' => $this->latitude === null ? null : ['latitude' => (float) $this->latitude, 'longitude' => (float) $this->longitude],
            'is_default' => $this->is_default,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
