<?php

namespace App\Domains\Customers\Http\Controllers;

use App\Domains\Customers\Http\Requests\StoreAddressRequest;
use App\Domains\Customers\Http\Requests\UpdateAddressRequest;
use App\Domains\Customers\Http\Resources\AddressResource;
use App\Domains\Customers\Models\CustomerAddress;
use App\Domains\Customers\Support\AddressBook;
use App\Domains\Shipping\Models\ShippingZoneArea;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Address book API (G-W5): the signed-in customer's addresses in the
 * market's country, default first; `delivery-areas` lists the cities and
 * areas the country's shipping zones cover (for the city / area pickers).
 */
class AddressController extends Controller
{
    public function __construct(private AddressBook $book) {}

    public function index(Request $request): JsonResponse
    {
        $addresses = CustomerAddress::query()
            ->where('customer_user_id', $request->user()->id)
            ->with('zone')
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();

        return ApiResponse::success(AddressResource::collection($addresses)->resolve($request));
    }

    public function store(StoreAddressRequest $request): JsonResponse
    {
        try {
            $address = $this->book->create($request->user()->id, $request->validated());
        } catch (TransitionRefused $refused) {
            return ApiResponse::error('address_limit', $refused->getMessage(), 422);
        }

        return ApiResponse::success((new AddressResource($address->load('zone')))->resolve($request), status: 201);
    }

    public function show(Request $request, int $address): JsonResponse
    {
        return ApiResponse::success((new AddressResource($this->own($request, $address)->load('zone')))->resolve($request));
    }

    public function update(UpdateAddressRequest $request, int $address): JsonResponse
    {
        $address = $this->book->update($this->own($request, $address), $request->validated());

        return ApiResponse::success((new AddressResource($address->load('zone')))->resolve($request));
    }

    public function makeDefault(Request $request, int $address): JsonResponse
    {
        $address = $this->own($request, $address);
        $this->book->makeDefault($address);

        return ApiResponse::success((new AddressResource($address->refresh()->load('zone')))->resolve($request));
    }

    public function destroy(Request $request, int $address): JsonResponse
    {
        $this->book->delete($this->own($request, $address));

        return ApiResponse::success(['deleted' => true]);
    }

    /**
     * Cities with their areas covered by active shipping zones.
     */
    public function deliveryAreas(): JsonResponse
    {
        $areas = ShippingZoneArea::query()
            ->join('shipping_zones', 'shipping_zones.id', '=', 'shipping_zone_areas.shipping_zone_id')
            ->where('shipping_zones.is_active', true)
            ->orderBy('shipping_zone_areas.city')
            ->orderBy('shipping_zone_areas.area')
            ->get(['shipping_zone_areas.city', 'shipping_zone_areas.area', 'shipping_zones.id as zone_id', 'shipping_zones.name as zone_name']);

        return ApiResponse::success($areas->groupBy('city')->map(fn ($rows, string $city): array => [
            'city' => $city,
            'zone' => ($whole = $rows->firstWhere('area', null)) === null ? null : ['id' => (int) $whole->zone_id, 'name' => $whole->zone_name],
            'areas' => $rows->whereNotNull('area')->map(fn ($row): array => ['name' => $row->area, 'zone' => ['id' => (int) $row->zone_id, 'name' => $row->zone_name]])->values()->all(),
        ])->values());
    }

    private function own(Request $request, int $id): CustomerAddress
    {
        return CustomerAddress::query()->where('customer_user_id', $request->user()->id)->findOrFail($id);
    }
}
