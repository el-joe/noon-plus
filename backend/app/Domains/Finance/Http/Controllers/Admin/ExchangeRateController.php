<?php

namespace App\Domains\Finance\Http\Controllers\Admin;

use App\Domains\Countries\Models\Country;
use App\Domains\Finance\Models\ExchangeRate;
use App\Domains\Finance\Support\ExchangeRates\ExchangeRates;
use App\Domains\Payments\Enums\PaymentMethod;
use App\Domains\Payments\Models\CountryGatewayConfig;
use App\Domains\Payments\Support\PaymentCurrencyConversion;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * Admin → Countries & gateways → Exchange rates (PayPal USD conversion).
 * Current rate per country currency → quote, which gateways depend on it
 * (PayPal is charged in USD at this rate), the audited history, a form to
 * add a rate from a time on (Oman time) and the provider sync.
 */
class ExchangeRateController extends Controller
{
    public function __construct(private ExchangeRates $rates) {}

    public function index(Request $request, PaymentCurrencyConversion $conversion): View
    {
        $filters = $request->validate([
            'base' => ['nullable', 'string', 'size:3', 'alpha'],
            'quote' => ['nullable', 'string', 'size:3', 'alpha'],
        ]);
        $countries = Country::query()->active()->get();
        $paypal = CountryGatewayConfig::query()->where('gateway', PaymentMethod::PayPal)->get()->keyBy('country_id');
        $current = [];

        foreach ($this->rates->quotes() as $quote) {
            foreach ($countries->groupBy('currency') as $currency => $group) {
                if ($currency === $quote) {
                    continue;
                }

                $rate = $this->rates->current($currency, $quote);
                $current[] = [
                    'base' => $currency,
                    'quote' => $quote,
                    'rate' => $rate,
                    'countries' => $group,
                    'paypal' => $group->map(fn (Country $country): array => [
                        'country' => $country,
                        'config' => $paypal->get($country->id),
                        'needs_rate' => $conversion->chargeCurrencyFor(PaymentMethod::PayPal, $currency) === $quote,
                    ]),
                ];
            }
        }

        $history = ExchangeRate::query()
            ->when($filters['base'] ?? null, fn ($query, string $base) => $query->where('base_currency', strtoupper($base)))
            ->when($filters['quote'] ?? null, fn ($query, string $quote) => $query->where('quote_currency', strtoupper($quote)))
            ->orderByDesc('effective_at')->orderByDesc('id')
            ->paginate(25)->withQueryString();

        return view('panels.admin.countries.exchange-rates', [
            'current' => $current,
            'history' => $history,
            'authors' => User::query()->whereIn('id', $history->pluck('created_by')->filter()->unique())->pluck('name', 'id'),
            'currencies' => $countries->pluck('currency')->merge($this->rates->quotes())->unique()->sort()->values(),
            'quotes' => $this->rates->quotes(),
            'provider' => $this->rates->providerName(),
            'filters' => $filters,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'base_currency' => ['required', 'string', 'size:3', 'alpha'],
            'quote_currency' => ['required', 'string', 'size:3', 'alpha', 'different:base_currency'],
            'rate' => ['required', 'decimal:0,10', 'gt:0', 'max:1000000'],
            'effective_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $effectiveAt = filled($data['effective_at'] ?? null)
            ? CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['effective_at'], config('nawy.admin_timezone'))->utc()
            : CarbonImmutable::now();

        $rate = $this->rates->record($data['base_currency'], $data['quote_currency'], (string) $data['rate'], $effectiveAt, ExchangeRate::SOURCE_MANUAL, $data['note'] ?? null, $request->user());

        return back()->with('status', __('Rate saved: 1 :base = :rate :quote.', ['base' => $rate->base_currency, 'rate' => rtrim(rtrim((string) $rate->rate, '0'), '.'), 'quote' => $rate->quote_currency]));
    }

    public function sync(): RedirectResponse
    {
        try {
            $stored = $this->rates->sync();
        } catch (Throwable $error) {
            report($error);

            return back()->withErrors(['sync' => __('The rate provider failed: :message', ['message' => $error->getMessage()])]);
        }

        return back()->with('status', $stored === []
            ? __('No rates fetched: the :driver driver does not fetch rates. Enter them below or configure a provider.', ['driver' => $this->rates->providerName()])
            : trans_choice(':count rate stored.|:count rates stored.', count($stored)));
    }
}
