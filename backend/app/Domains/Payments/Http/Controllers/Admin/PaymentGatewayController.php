<?php

namespace App\Domains\Payments\Http\Controllers\Admin;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Payments\Enums\PaymentMethod;
use App\Domains\Payments\Http\Requests\UpdateGatewayConfigRequest;
use App\Domains\Payments\Models\CountryGatewayConfig;
use App\Domains\Payments\Support\GatewayConfigManager;
use App\Domains\Payments\Support\PaymentMethodCatalog;
use App\Http\Controllers\Controller;
use App\Shared\Money\MoneyFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Countries & gateways → Payment gateways (Figma 13:648): the
 * gateways of the selected country — enable, mode, write-only credentials,
 * currencies, min/max, order, fee rule and the one recommended gateway —
 * with the customer checkout preview. Super admin and Finance only
 * (role-permission matrix §3.2 `payment_gateways`); changes are audited.
 */
class PaymentGatewayController extends Controller
{
    public function __construct(private CountryContext $countryContext, private GatewayConfigManager $manager) {}

    public function index(Request $request, PaymentMethodCatalog $catalog, MoneyFactory $money): View
    {
        $country = $this->countryContext->current();
        $request->validate(['preview_amount' => ['nullable', 'decimal:0,3', 'min:0']]);
        $previewAmount = $money->of((string) ($request->query('preview_amount') ?: config("nawy.checkout.defaults.{$country->code}.free_shipping_threshold", '100')), $country->currency);
        $configs = CountryGatewayConfig::query()->where('country_id', $country->id)->orderByDesc('is_recommended')->orderBy('sort_order')->orderBy('id')->get();

        return view('panels.admin.settings.payment-gateways', [
            'country' => $country,
            'configs' => $configs,
            'missing' => array_values(array_filter(PaymentMethod::gateways(), fn (PaymentMethod $method): bool => ! $configs->contains('gateway', $method))),
            'preview' => $catalog->available($country, $previewAmount),
            'previewAmount' => $previewAmount,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['gateway' => ['required', Rule::in(array_column(PaymentMethod::gateways(), 'value'))]]);
        $this->manager->add($this->countryContext->current(), PaymentMethod::from($data['gateway']), $request->user());

        return back()->with('status', __('Gateway added (disabled, sandbox).'));
    }

    public function update(UpdateGatewayConfigRequest $request, int $config): RedirectResponse
    {
        $this->manager->update($this->ownConfig($config), $request->settings(), (array) $request->validated('credentials', []), $request->user());

        return back()->with('status', __('Gateway saved.'));
    }

    public function recommend(Request $request, int $config): RedirectResponse
    {
        $this->manager->makeRecommended($this->ownConfig($config), $request->user());

        return back()->with('status', __('Recommended gateway changed.'));
    }

    /**
     * A gateway of the selected country only (404 otherwise).
     */
    private function ownConfig(int $id): CountryGatewayConfig
    {
        return CountryGatewayConfig::query()->where('country_id', $this->countryContext->current()->id)->findOrFail($id);
    }
}
