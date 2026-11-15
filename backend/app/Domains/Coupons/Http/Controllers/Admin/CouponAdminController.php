<?php

namespace App\Domains\Coupons\Http\Controllers\Admin;

use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Countries\Support\CountryClock;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Coupons\Enums\CouponFunding;
use App\Domains\Coupons\Enums\CouponScope;
use App\Domains\Coupons\Enums\CouponTarget;
use App\Domains\Coupons\Enums\CouponType;
use App\Domains\Coupons\Enums\CustomerSegment;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Identity\Support\AdminVendorScope;
use App\Domains\Vendors\Models\Vendor;
use App\Http\Controllers\Controller;
use App\Shared\Http\LikeSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Illuminate\View\View;

/**
 * Admin → Marketing → Coupons (QC-003, QC-014, SRS §10): platform and
 * seller coupons of the country — percent, fixed, free shipping and BOGO;
 * targets (categories, products, brands, sellers), first order and customer
 * segments; usage limits and dates (country time, Oman shown); platform- or
 * vendor-funded. Audited. Account managers see their vendors' coupons only.
 */
class CouponAdminController extends Controller
{
    private const LOCAL_FORMAT = 'Y-m-d\TH:i';

    public function __construct(private CountryContext $countryContext, private CountryClock $clock, private AdminVendorScope $scope) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:40'],
            'scope' => ['nullable', Rule::enum(CouponScope::class)],
            'type' => ['nullable', Rule::enum(CouponType::class)],
            'state' => ['nullable', Rule::in(['live', 'scheduled', 'ended', 'inactive'])],
        ]);

        $coupons = $this->scoped(Coupon::query())
            ->withCount('redemptions')
            ->when($filters['q'] ?? null, fn ($query, string $search) => $query->where('code', 'like', LikeSearch::contains(mb_strtoupper($search))))
            ->when($filters['scope'] ?? null, fn ($query, string $scope) => $query->where('scope', $scope))
            ->when($filters['type'] ?? null, fn ($query, string $type) => $query->where('type', $type))
            ->when($filters['state'] ?? null, fn ($query, string $state) => match ($state) {
                'inactive' => $query->where('is_active', false),
                'scheduled' => $query->where('is_active', true)->where('starts_at', '>', now()),
                'ended' => $query->where('ends_at', '<=', now()),
                default => $query->where('is_active', true)->where(fn ($inner) => $inner->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                    ->where(fn ($inner) => $inner->whereNull('ends_at')->orWhere('ends_at', '>', now())),
            })
            ->latest('id')->paginate(25)->withQueryString();

        return view('panels.admin.coupons.index', [
            'coupons' => $coupons,
            'filters' => $filters,
            'country' => $this->countryContext->current(),
        ]);
    }

    public function show(int $coupon): View
    {
        $coupon = $this->scoped(Coupon::query())->withCount('redemptions')->withSum('redemptions', 'discount_amount')->findOrFail($coupon);

        return view('panels.admin.coupons.show', [
            'coupon' => $coupon,
            'country' => $this->countryContext->current(),
            'vendor' => $coupon->vendor_id === null ? null : Vendor::query()->find($coupon->vendor_id),
            'targets' => $this->targetNames($coupon),
            'redemptions' => $coupon->redemptions()->latest('id')->limit(25)->get(),
        ]);
    }

    public function create(): View
    {
        return $this->form(new Coupon(['is_active' => true, 'scope' => CouponScope::Platform, 'type' => CouponType::Percentage]));
    }

    public function store(Request $request): RedirectResponse
    {
        $coupon = Coupon::query()->create($this->validated($request, null) + ['created_by_user_id' => $request->user()->id]);

        return redirect()->route('admin.coupons.show', $coupon->id)->with('status', __('Coupon created.'));
    }

    public function edit(int $coupon): View
    {
        return $this->form(Coupon::query()->findOrFail($coupon));
    }

    public function update(Request $request, int $coupon): RedirectResponse
    {
        $coupon = Coupon::query()->findOrFail($coupon);
        $coupon->update($this->validated($request, $coupon));

        return redirect()->route('admin.coupons.show', $coupon->id)->with('status', __('Coupon saved.'));
    }

    private function form(Coupon $coupon): View
    {
        return view('panels.admin.coupons.form', [
            'coupon' => $coupon,
            'country' => $this->countryContext->current(),
            'vendors' => Vendor::query()->orderBy('store_name')->pluck('store_name', 'id')->all(),
            'categories' => Category::query()->orderBy('name_en')->pluck('name_en', 'id')->all(),
            'brands' => Brand::query()->orderBy('name')->pluck('name', 'id')->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Coupon $coupon): array
    {
        $request->merge(['code' => mb_strtoupper(trim((string) $request->input('code')))]);
        $validator = validator($request->all(), [
            'code' => ['required', 'alpha_dash', 'min:3', 'max:32', Rule::unique(Coupon::class, 'code')->ignore($coupon?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'scope' => ['required', Rule::enum(CouponScope::class)],
            'vendor_id' => ['nullable', 'required_if:scope,vendor', 'integer', Rule::exists(Vendor::class, 'id')],
            'funded_by' => ['required', Rule::enum(CouponFunding::class)],
            'type' => ['required', Rule::enum(CouponType::class)],
            'value' => ['nullable', 'required_if:type,percentage,fixed_amount', 'decimal:0,3', 'min:0'],
            'buy_quantity' => ['nullable', 'required_if:type,bogo', 'integer', 'min:1', 'max:20'],
            'get_quantity' => ['nullable', 'required_if:type,bogo', 'integer', 'min:1', 'max:20'],
            'target_type' => ['required', Rule::enum(CouponTarget::class)],
            'target_category_ids' => ['nullable', 'array'],
            'target_category_ids.*' => ['integer', Rule::exists(Category::class, 'id')],
            'target_brand_ids' => ['nullable', 'array'],
            'target_brand_ids.*' => ['integer', Rule::exists(Brand::class, 'id')],
            'target_product_ids' => ['nullable', 'string', 'max:500', 'regex:/^[0-9,\s]*$/'],
            'target_vendor_ids' => ['nullable', 'string', 'max:500', 'regex:/^[0-9,\s]*$/'],
            'first_order_only' => ['boolean'],
            'customer_segment' => ['required', Rule::enum(CustomerSegment::class)],
            'min_order_amount' => ['nullable', 'decimal:0,3', 'min:0'],
            'max_discount_amount' => ['nullable', 'decimal:0,3', 'gt:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date_format:'.self::LOCAL_FORMAT],
            'ends_at' => ['nullable', 'date_format:'.self::LOCAL_FORMAT],
            'is_active' => ['boolean'],
        ]);

        $validator->after(function (Validator $validator) use ($request): void {
            $data = $request->all();

            if (($data['type'] ?? null) === CouponType::Percentage->value && is_numeric($data['value'] ?? null) && (float) $data['value'] > 100) {
                $validator->errors()->add('value', __('A percentage cannot be more than 100.'));
            }

            if (($data['type'] ?? null) === CouponType::Bogo->value && is_numeric($data['value'] ?? null) && ((float) $data['value'] <= 0 || (float) $data['value'] > 100)) {
                $validator->errors()->add('value', __('The BOGO discount on the free items is 1 to 100 %.'));
            }

            if (($data['scope'] ?? null) === CouponScope::Vendor->value && ($data['funded_by'] ?? null) !== CouponFunding::Vendor->value) {
                $validator->errors()->add('funded_by', __('Seller coupons are funded by the seller.'));
            }

            if (filled($data['starts_at'] ?? null) && filled($data['ends_at'] ?? null) && strcmp((string) $data['ends_at'], (string) $data['starts_at']) <= 0) {
                $validator->errors()->add('ends_at', __('The end must be after the start.'));
            }

            $target = CouponTarget::tryFrom((string) ($data['target_type'] ?? ''));

            if ($target !== null && $target !== CouponTarget::All && $this->targetIds($request, $target) === []) {
                $validator->errors()->add('target_type', __('Choose at least one :target.', ['target' => Str::lower($target->label())]));
            }

            if ($target === CouponTarget::Product && ! $validator->errors()->has('target_type')) {
                $ids = $this->targetIds($request, $target);

                if (Product::query()->whereIn('id', $ids)->count() !== count($ids)) {
                    $validator->errors()->add('target_product_ids', __('Some product ids do not exist in this country.'));
                }
            }

            if ($target === CouponTarget::Vendor && ! $validator->errors()->has('target_type')) {
                $ids = $this->targetIds($request, $target);

                if (Vendor::query()->whereIn('id', $ids)->count() !== count($ids)) {
                    $validator->errors()->add('target_vendor_ids', __('Some seller ids do not exist in this country.'));
                }
            }
        });

        $data = $validator->validate();
        $country = $this->countryContext->current();
        $target = CouponTarget::from($data['target_type']);
        $type = CouponType::from($data['type']);

        return [
            'code' => $data['code'],
            'description' => $data['description'] ?? null,
            'scope' => $data['scope'],
            'vendor_id' => $data['scope'] === CouponScope::Vendor->value ? (int) $data['vendor_id'] : null,
            'funded_by' => $data['funded_by'],
            'type' => $type,
            'value' => match ($type) {
                CouponType::FreeShipping => '0',
                CouponType::Bogo => (string) ($data['value'] ?? '100') ?: '100',
                default => (string) $data['value'],
            },
            'buy_quantity' => $type === CouponType::Bogo ? (int) $data['buy_quantity'] : null,
            'get_quantity' => $type === CouponType::Bogo ? (int) $data['get_quantity'] : null,
            'target_type' => $target,
            'target_ids' => $target === CouponTarget::All ? null : $this->targetIds($request, $target),
            'first_order_only' => (bool) ($data['first_order_only'] ?? false),
            'customer_segment' => $data['customer_segment'],
            'min_order_amount' => $data['min_order_amount'] ?? null,
            'max_discount_amount' => $data['max_discount_amount'] ?? null,
            'usage_limit' => $data['usage_limit'] ?? null,
            'per_customer_limit' => $data['per_customer_limit'] ?? null,
            'starts_at' => filled($data['starts_at'] ?? null) ? $this->clock->localToUtc($country, $data['starts_at']) : null,
            'ends_at' => filled($data['ends_at'] ?? null) ? $this->clock->localToUtc($country, $data['ends_at']) : null,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ];
    }

    /**
     * @return array<int, int>
     */
    private function targetIds(Request $request, CouponTarget $target): array
    {
        $raw = match ($target) {
            CouponTarget::Category => (array) $request->input('target_category_ids', []),
            CouponTarget::Brand => (array) $request->input('target_brand_ids', []),
            CouponTarget::Product => preg_split('/[\s,]+/', (string) $request->input('target_product_ids', '')),
            CouponTarget::Vendor => preg_split('/[\s,]+/', (string) $request->input('target_vendor_ids', '')),
            CouponTarget::All => [],
        };

        return array_values(array_unique(array_map('intval', array_filter((array) $raw, fn ($id): bool => is_scalar($id) && ctype_digit((string) $id) && (int) $id > 0))));
    }

    /**
     * @return array<int, string>
     */
    private function targetNames(Coupon $coupon): array
    {
        $ids = $coupon->targetIds();

        return match ($coupon->target_type) {
            CouponTarget::Category => Category::query()->whereIn('id', $ids)->pluck('name_en')->all(),
            CouponTarget::Brand => Brand::query()->whereIn('id', $ids)->pluck('name')->all(),
            CouponTarget::Product => Product::query()->whereIn('id', $ids)->pluck('title_en')->all(),
            CouponTarget::Vendor => Vendor::query()->whereIn('id', $ids)->pluck('store_name')->all(),
            default => [],
        };
    }

    /**
     * Account managers: their vendors' seller coupons only (matrix §3.3).
     *
     * @param  Builder<Coupon>  $query
     * @return Builder<Coupon>
     */
    private function scoped($query)
    {
        $vendorIds = $this->scope->vendorIds();

        return $vendorIds === null ? $query : $query->where('scope', CouponScope::Vendor)->whereIn('vendor_id', $vendorIds);
    }
}
