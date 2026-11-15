<?php

namespace App\Domains\GiftCards\Http\Controllers\Admin;

use App\Domains\Countries\Support\CountryClock;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\GiftCards\Enums\GiftCardStatus;
use App\Domains\GiftCards\Enums\VoucherStatus;
use App\Domains\GiftCards\Models\GiftCard;
use App\Domains\GiftCards\Models\Voucher;
use App\Domains\GiftCards\Models\VoucherRedemption;
use App\Domains\GiftCards\Support\GiftCards;
use App\Domains\GiftCards\Support\GiftCodes;
use App\Domains\GiftCards\Support\Vouchers;
use App\Http\Controllers\Controller;
use App\Shared\Http\LikeSearch;
use App\Shared\Money\MoneyFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Marketing → Gift cards & vouchers (QC-003): the country's gift
 * cards (bought and admin-issued) with their state history, issuing a
 * promotional card, voiding; vouchers worth promotional Nawy Credits with
 * their redemptions. Codes are never shown — only the masked last four.
 */
class GiftCardAdminController extends Controller
{
    public function __construct(private CountryContext $countryContext, private MoneyFactory $money) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::enum(GiftCardStatus::class)],
            'source' => ['nullable', Rule::in(['purchase', 'admin'])],
        ]);

        return view('panels.admin.gift-cards.index', [
            'cards' => GiftCard::query()
                ->when($filters['q'] ?? null, fn ($query, string $search) => $query->where(fn ($inner) => $inner
                    ->where('number', 'like', LikeSearch::contains($search))
                    ->orWhere('recipient_email', 'like', LikeSearch::contains($search))
                    ->orWhere('code_last4', $search)))
                ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
                ->when($filters['source'] ?? null, fn ($query, string $source) => $query->where('source', $source))
                ->latest('id')->paginate(25)->withQueryString(),
            'filters' => $filters,
            'country' => $this->countryContext->current(),
        ]);
    }

    public function show(int $card): View
    {
        return view('panels.admin.gift-cards.show', [
            'card' => GiftCard::query()->with('transitions')->findOrFail($card),
            'country' => $this->countryContext->current(),
        ]);
    }

    public function issue(Request $request, GiftCards $giftCards): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'decimal:0,3', 'gt:0', 'max:100000'],
            'recipient_email' => ['required', 'email', 'max:255'],
            'recipient_name' => ['nullable', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);
        $card = $giftCards->issue($request->user()->id, $this->money->of((string) $data['amount'], $this->countryContext->current()->currency),
            $data['recipient_email'], $data['recipient_name'] ?? null, $data['message'] ?? null);

        return redirect()->route('admin.gift-cards.show', $card->id)->with('status', __('Gift card :number issued and emailed.', ['number' => $card->number]));
    }

    public function void(Request $request, int $card, GiftCards $giftCards): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason'];
        $giftCards->void(GiftCard::query()->findOrFail($card), $request->user()->id, $reason);

        return back()->with('status', __('Gift card voided.'));
    }

    public function vouchers(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:80'], 'status' => ['nullable', Rule::enum(VoucherStatus::class)]]);

        return view('panels.admin.gift-cards.vouchers', [
            'vouchers' => Voucher::query()
                ->when($filters['q'] ?? null, fn ($query, string $search) => $query->where('label', 'like', LikeSearch::contains($search)))
                ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
                ->latest('id')->paginate(25)->withQueryString(),
            'filters' => $filters,
            'country' => $this->countryContext->current(),
        ]);
    }

    public function voucher(int $voucher): View
    {
        $voucher = Voucher::query()->with('transitions')->findOrFail($voucher);

        return view('panels.admin.gift-cards.voucher', [
            'voucher' => $voucher,
            'redemptions' => VoucherRedemption::query()->where('voucher_id', $voucher->id)->latest('id')->paginate(25),
            'country' => $this->countryContext->current(),
        ]);
    }

    public function storeVoucher(Request $request, Vouchers $vouchers, CountryClock $clock): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'alpha_dash', 'min:6', 'max:32'],
            'label' => ['required', 'string', 'max:120'],
            'value' => ['required', 'decimal:0,3', 'gt:0', 'max:10000'],
            'max_redemptions' => ['required', 'integer', 'min:1', 'max:1000000'],
            'credit_expiry_days' => ['required', 'integer', 'min:1', 'max:730'],
            'expires_at' => ['required', 'date_format:Y-m-d\TH:i'],
        ]);
        $country = $this->countryContext->current();
        $expiresAt = $clock->localToUtc($country, $data['expires_at']);

        if (Voucher::query()->where('code_hash', app(GiftCodes::class)->hash($data['code']))->exists()) {
            return back()->withInput()->withErrors(['code' => __('This voucher code is already used.')]);
        }

        if ($expiresAt->isPast()) {
            return back()->withInput()->withErrors(['expires_at' => __('The voucher must end in the future.')]);
        }

        $voucher = $vouchers->create($request->user()->id, $data['code'], $data['label'], $this->money->of((string) $data['value'], $country->currency),
            (int) $data['max_redemptions'], (int) $data['credit_expiry_days'], $expiresAt);

        return redirect()->route('admin.gift-cards.vouchers.show', $voucher->id)->with('status', __('Voucher created. Share the code now — it is stored only as a hash.'));
    }

    public function voidVoucher(Request $request, int $voucher, Vouchers $vouchers): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason'];
        $vouchers->void(Voucher::query()->findOrFail($voucher), $request->user()->id, $reason);

        return back()->with('status', __('Voucher voided.'));
    }
}
