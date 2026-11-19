<?php

namespace App\Domains\Advertising\Http\Controllers\Admin;

use App\Domains\Advertising\Enums\TravelPromotionStatus;
use App\Domains\Advertising\Models\TravelPromotion;
use App\Domains\Advertising\Models\TravelPromotionProduct;
use App\Domains\Advertising\Support\TravelPromotions;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Payments\Exceptions\RefundRefused;
use App\Http\Controllers\Controller;
use App\Shared\Money\MoneyFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin › Growth › Travel promotions (D14), in the selected country: the
 * travel placement (on sale, how many promoted trips lead a list), the
 * promotion options (duration and price, VAT included, in the country
 * currency) and every promotion with cancel (no money back) and refund.
 * Viewing needs `travel_promotions.view`; changes `travel_promotions.manage`.
 */
class TravelPromotionAdminController extends Controller
{
    public function __construct(private TravelPromotions $promotions, private CountryContext $countryContext, private MoneyFactory $money) {}

    public function index(Request $request): View
    {
        $status = $request->query('status');

        return view('panels.admin.marketing.travel-promotions', [
            'slot' => $this->promotions->slot(),
            'products' => TravelPromotionProduct::query()->orderBy('duration_days')->get(),
            'promotions' => TravelPromotion::query()->with(['package', 'vendor', 'product'])
                ->when(is_string($status) && TravelPromotionStatus::tryFrom($status) !== null, fn ($query) => $query->where('status', $status))
                ->latest('id')->paginate(25)->withQueryString(),
            'statuses' => TravelPromotionStatus::cases(),
            'currency' => $this->countryContext->current()->currency,
        ]);
    }

    public function updateSlot(Request $request): RedirectResponse
    {
        $data = $request->validate(['max_concurrent' => ['required', 'integer', 'min:1', 'max:12'], 'is_active' => ['sometimes', 'boolean']]);
        $this->promotions->slot()->forceFill(['max_concurrent' => $data['max_concurrent'], 'is_active' => $request->boolean('is_active')])->save();

        return back()->with('status', __('Travel placement saved.'));
    }

    public function storeProduct(Request $request): RedirectResponse
    {
        $data = $this->productData($request);
        TravelPromotionProduct::query()->create($data + ['ad_slot_id' => $this->promotions->slot()->id, 'code' => $this->code($data['duration_days'])]);

        return back()->with('status', __('Promotion option added.'));
    }

    public function updateProduct(Request $request, int $product): RedirectResponse
    {
        TravelPromotionProduct::query()->findOrFail($product)->update($this->productData($request));

        return back()->with('status', __('Promotion option saved.'));
    }

    public function cancel(Request $request, int $promotion): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason'];
        $this->promotions->cancel(TravelPromotion::query()->findOrFail($promotion), $request->user()->id, $reason);

        return back()->with('status', __('Promotion cancelled.'));
    }

    public function refund(Request $request, int $promotion): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason'];

        try {
            $this->promotions->refund(TravelPromotion::query()->findOrFail($promotion), $request->user()->id, $reason);
        } catch (RefundRefused $refused) {
            return back()->withErrors(['action' => $refused->getMessage()]);
        }

        return back()->with('status', __('Promotion refunded; the money goes back to the agency card.'));
    }

    /**
     * @return array{name_en: string, name_ar: string|null, duration_days: int, price: string, is_active: bool}
     */
    private function productData(Request $request): array
    {
        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:120'],
            'name_ar' => ['nullable', 'string', 'max:120'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:365'],
            'price' => ['required', 'numeric', 'gt:0', 'max:1000000', 'decimal:0,'.$this->scale()],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return [
            'name_en' => $data['name_en'],
            'name_ar' => $data['name_ar'] ?? null,
            'duration_days' => (int) $data['duration_days'],
            'price' => $this->money->toStorage($this->money->of((string) $data['price'], $this->countryContext->current()->currency)),
            'is_active' => $request->boolean('is_active'),
        ];
    }

    private function scale(): int
    {
        return $this->money->of('0', $this->countryContext->current()->currency)->getAmount()->getScale();
    }

    private function code(int $days): string
    {
        $base = "travel-{$days}d";
        $code = $base;

        for ($n = 2; TravelPromotionProduct::query()->where('code', $code)->exists(); $n++) {
            $code = "{$base}-{$n}";
        }

        return $code;
    }
}
