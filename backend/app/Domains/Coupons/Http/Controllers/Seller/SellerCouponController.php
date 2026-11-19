<?php

namespace App\Domains\Coupons\Http\Controllers\Seller;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Coupons\Enums\CouponTarget;
use App\Domains\Coupons\Enums\CouponType;
use App\Domains\Coupons\Enums\CustomerSegment;
use App\Domains\Coupons\Http\Requests\SellerCouponRequest;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Coupons\Models\CouponRedemption;
use App\Domains\Coupons\Support\SellerCoupons;
use App\Domains\Orders\Models\Order;
use App\Domains\Vendors\Support\VendorWorkspace;
use App\Http\Controllers\Controller;
use App\Shared\Http\LikeSearch;
use App\Shared\Money\MoneyFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Seller Center → Growth → Coupons: the store's own vendor-funded coupons
 * (list, create, edit, detail with redemptions, switch on/off). Every query
 * is scoped to the workspace store; the discount comes off the store's
 * settlement. See {@see SellerCoupons} for the rules.
 */
class SellerCouponController extends Controller
{
    public function __construct(private VendorWorkspace $workspace, private CountryContext $countryContext, private SellerCoupons $coupons) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:40'],
            'state' => ['nullable', Rule::in(['live', 'scheduled', 'ended', 'off'])],
        ]);
        $vendor = $this->workspace->vendor();

        $coupons = $this->coupons->query($vendor)
            ->withCount('redemptions')->withSum('redemptions', 'discount_amount')
            ->when($filters['q'] ?? null, fn ($query, string $search) => $query->where('code', 'like', LikeSearch::contains(mb_strtoupper($search))))
            ->when($filters['state'] ?? null, fn ($query, string $state) => match ($state) {
                'off' => $query->where('is_active', false),
                'scheduled' => $query->where('is_active', true)->where('starts_at', '>', now()),
                'ended' => $query->where('ends_at', '<=', now()),
                default => $query->where('is_active', true)->where(fn ($inner) => $inner->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                    ->where(fn ($inner) => $inner->whereNull('ends_at')->orWhere('ends_at', '>', now())),
            })
            ->latest('id')->paginate(25)->withQueryString();

        return view('panels.partner.coupons.index', [
            'coupons' => $coupons,
            'filters' => $filters,
            'country' => $this->countryContext->current(),
            'liveCount' => $this->coupons->liveCount($vendor),
            'maxLive' => $this->coupons->maxLive(),
        ]);
    }

    public function create(): View
    {
        return $this->form(new Coupon([
            'is_active' => true,
            'type' => CouponType::Percentage,
            'target_type' => CouponTarget::All,
            'customer_segment' => CustomerSegment::Everyone,
        ]));
    }

    public function store(SellerCouponRequest $request): RedirectResponse
    {
        $vendor = $this->workspace->vendor();
        $coupon = Coupon::query()->create($request->attributesFor($vendor) + ['created_by_user_id' => $request->user()->id]);

        return redirect()->route('partner.coupons.show', $coupon->id)->with('status', __('Coupon created.'));
    }

    public function show(int $coupon, MoneyFactory $money): View
    {
        $vendor = $this->workspace->vendor();
        $coupon = $this->coupons->query($vendor)->withCount('redemptions')->findOrFail($coupon);
        $currency = $this->countryContext->current()->currency;
        $redemptions = $coupon->redemptions()->latest('id')->paginate(25);
        $orders = Order::query()->whereIn('id', $redemptions->pluck('order_id'))->pluck('number', 'id');
        $given = $money->zero($currency);

        foreach ($coupon->redemptions()->get(['discount_amount', 'vendor_shares']) as $redemption) {
            $given = $given->plus($money->of($this->storeShare($redemption, $vendor->id), $currency));
        }

        return view('panels.partner.coupons.show', [
            'coupon' => $coupon,
            'country' => $this->countryContext->current(),
            'targets' => $this->coupons->targetNames($coupon),
            'redemptions' => $redemptions,
            'orders' => $orders,
            'given' => $given,
            'customers' => $coupon->redemptions()->distinct('customer_user_id')->count('customer_user_id'),
            'shareOf' => fn (CouponRedemption $redemption): string => $this->storeShare($redemption, $vendor->id),
        ]);
    }

    public function edit(int $coupon): View
    {
        return $this->form($this->coupons->query($this->workspace->vendor())->findOrFail($coupon));
    }

    public function update(SellerCouponRequest $request, int $coupon): RedirectResponse
    {
        $request->coupon->update($request->attributesFor($this->workspace->vendor()));

        return redirect()->route('partner.coupons.show', $coupon)->with('status', __('Coupon saved.'));
    }

    /**
     * Switch a coupon on or off from the list or the detail page.
     */
    public function toggle(int $coupon): RedirectResponse
    {
        $vendor = $this->workspace->vendor();
        $coupon = $this->coupons->query($vendor)->findOrFail($coupon);

        if (! $coupon->is_active) {
            $error = match (true) {
                $coupon->admin_disabled_at !== null => __('Nawy switched this coupon off. Contact support to switch it on again.'),
                $coupon->ends_at !== null && $coupon->ends_at->isPast() => __('This coupon has ended. Edit its end date first.'),
                $this->coupons->liveCount($vendor, $coupon->id) >= $this->coupons->maxLive() => __('You can have at most :max coupons switched on. Switch one off first.', ['max' => $this->coupons->maxLive()]),
                default => null,
            };

            if ($error !== null) {
                throw ValidationException::withMessages(['is_active' => $error]);
            }
        }

        $coupon->update(['is_active' => ! $coupon->is_active]);

        return back()->with('status', $coupon->is_active ? __('Coupon switched on.') : __('Coupon switched off.'));
    }

    private function form(Coupon $coupon): View
    {
        $vendor = $this->workspace->vendor();

        return view('panels.partner.coupons.form', [
            'coupon' => $coupon,
            'country' => $this->countryContext->current(),
            'products' => $this->coupons->productOptions($vendor),
            'categories' => $this->coupons->categoryOptions($vendor),
            'brands' => $this->coupons->brandOptions($vendor),
            'maxPercent' => $this->coupons->maxPercent(),
            'maxDays' => $this->coupons->maxDays(),
            'locked' => $coupon->exists && $coupon->redemptions()->exists(),
        ]);
    }

    /**
     * What the store paid for one redemption: its `vendor_shares` entry, or
     * the whole discount for redemptions recorded before shares were kept.
     */
    private function storeShare(CouponRedemption $redemption, int $vendorId): string
    {
        $shares = $redemption->vendor_shares;

        if (is_array($shares) && $shares !== []) {
            return (string) ($shares[$vendorId] ?? $shares[(string) $vendorId] ?? '0');
        }

        return (string) $redemption->discount_amount;
    }
}
