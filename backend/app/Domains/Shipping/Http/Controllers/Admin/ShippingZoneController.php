<?php

namespace App\Domains\Shipping\Http\Controllers\Admin;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Shipping\Enums\ShippingMethod;
use App\Domains\Shipping\Models\ShippingRate;
use App\Domains\Shipping\Models\ShippingZone;
use App\Domains\Shipping\Models\ShippingZoneArea;
use App\Http\Controllers\Controller;
use Brick\Money\Currency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Delivery & logistics → Shipping zones & rates (SRS §8, WP1 engine):
 * zones of the selected country, their cities/areas and a rate per method.
 */
class ShippingZoneController extends Controller
{
    public function __construct(private CountryContext $countryContext) {}

    public function index(): View
    {
        return view('panels.admin.settings.shipping-zones', [
            'zones' => ShippingZone::query()->withCount(['areas', 'rates'])->orderByDesc('is_default')->orderBy('name')->get(),
            'country' => $this->countryContext->current(),
        ]);
    }

    public function show(int $zone): View
    {
        return view('panels.admin.settings.shipping-zone', [
            'zone' => ShippingZone::query()->with(['areas' => fn ($query) => $query->orderBy('city')->orderBy('area'), 'rates'])->findOrFail($zone),
            'country' => $this->countryContext->current(),
            'methods' => ShippingMethod::cases(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $zone = DB::connection($this->countryContext->connectionName())->transaction(function () use ($request): ShippingZone {
            $data = $this->validatedZone($request);
            $this->clearOtherDefaults($data);

            return ShippingZone::query()->create($data);
        });

        return redirect()->route('admin.delivery.zones.show', $zone->id)->with('status', __('Zone created.'));
    }

    public function update(Request $request, int $zone): RedirectResponse
    {
        $zone = ShippingZone::query()->findOrFail($zone);

        DB::connection($zone->getConnectionName())->transaction(function () use ($request, $zone): void {
            $data = $this->validatedZone($request);
            $this->clearOtherDefaults($data, $zone->id);
            $zone->update($data);
        });

        return back()->with('status', __('Zone updated.'));
    }

    public function storeArea(Request $request, int $zone): RedirectResponse
    {
        $zone = ShippingZone::query()->findOrFail($zone);
        $data = $request->validate([
            'city' => ['required', 'string', 'max:120'],
            'area' => [
                'nullable', 'string', 'max:120',
                Rule::unique(ShippingZoneArea::class, 'area')->where('shipping_zone_id', $zone->id)->where('city', (string) $request->input('city')),
            ],
        ]);

        $zone->areas()->create($data);

        return back()->with('status', __('Area added.'));
    }

    public function destroyArea(int $area): RedirectResponse
    {
        ShippingZoneArea::query()->findOrFail($area)->delete();

        return back()->with('status', __('Area removed.'));
    }

    public function storeRate(Request $request, int $zone): RedirectResponse
    {
        $zone = ShippingZone::query()->findOrFail($zone);
        $data = $this->validatedRate($request, $zone->id);

        $zone->rates()->create($data);

        return back()->with('status', __('Rate added.'));
    }

    public function updateRate(Request $request, int $rate): RedirectResponse
    {
        $rate = ShippingRate::query()->findOrFail($rate);
        $rate->update($this->validatedRate($request, $rate->shipping_zone_id, $rate->id));

        return back()->with('status', __('Rate updated.'));
    }

    public function destroyRate(int $rate): RedirectResponse
    {
        ShippingRate::query()->findOrFail($rate)->delete();

        return back()->with('status', __('Rate removed.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedZone(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'is_default' => ['boolean'],
            'is_exceptional' => ['boolean'],
            'vendor_subsidy_share_percent' => ['required', 'decimal:0,2', 'between:0,100'],
            'is_active' => ['boolean'],
        ]);

        return [
            'name' => $data['name'],
            'is_default' => $request->boolean('is_default'),
            'is_exceptional' => $request->boolean('is_exceptional'),
            'vendor_subsidy_share_percent' => $data['vendor_subsidy_share_percent'],
            'is_active' => $request->boolean('is_active'),
        ];
    }

    /**
     * Only one default zone per country.
     *
     * @param  array<string, mixed>  $data
     */
    private function clearOtherDefaults(array $data, ?int $exceptId = null): void
    {
        if ($data['is_default']) {
            ShippingZone::query()->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))->update(['is_default' => false]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedRate(Request $request, int $zoneId, ?int $ignoreId = null): array
    {
        $decimals = Currency::of($this->countryContext->current()->currency)->getDefaultFractionDigits();

        $data = $request->validate([
            'method' => ['required', Rule::enum(ShippingMethod::class), Rule::unique(ShippingRate::class, 'method')->where('shipping_zone_id', $zoneId)->ignore($ignoreId)],
            'base_fee' => ['required', 'decimal:0,'.$decimals, 'min:0'],
            'base_weight_kg' => ['required', 'decimal:0,3', 'min:0', 'max:1000'],
            'per_kg_fee' => ['required', 'decimal:0,'.$decimals, 'min:0'],
            'transit_min_days' => ['required', 'integer', 'between:0,60'],
            'transit_max_days' => ['required', 'integer', 'between:0,60', 'gte:transit_min_days'],
            'cutoff_time' => ['nullable', 'date_format:H:i'],
        ]);

        return $data + ['is_active' => $request->boolean('is_active')];
    }
}
