<?php

namespace App\Domains\Coupons\Http\Requests;

use App\Domains\Countries\Support\CountryClock;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Coupons\Enums\CouponTarget;
use App\Domains\Coupons\Enums\CouponType;
use App\Domains\Coupons\Enums\CustomerSegment;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Coupons\Support\SellerCoupons;
use App\Domains\Vendors\Models\Vendor;
use App\Domains\Vendors\Support\VendorWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create / edit a Seller Center coupon. Targets must be the store's own
 * products, categories or brands; percent off is capped by
 * `coupons.seller_max_percent`, the run by `coupons.seller_max_days`, and
 * switched-on coupons per store by `coupons.seller_max_live`. Once a coupon
 * has been used, its code and discount can no longer change.
 */
class SellerCouponRequest extends FormRequest
{
    private const LOCAL_FORMAT = 'Y-m-d\TH:i';

    public ?Coupon $coupon = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The coupon being edited, found within the store only (another store's id is a 404).
     */
    protected function prepareForValidation(): void
    {
        $couponId = $this->route('coupon');

        if ($couponId !== null) {
            $this->coupon = app(SellerCoupons::class)->query($this->vendor())->findOrFail((int) $couponId);
        }

        $this->merge(['code' => mb_strtoupper(trim((string) $this->input('code')))]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'alpha_dash', 'min:3', 'max:32', Rule::unique(Coupon::class, 'code')->ignore($this->coupon?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::in(array_map(fn (CouponType $type): string => $type->value, SellerCoupons::TYPES))],
            'value' => ['nullable', 'required_if:type,percentage,fixed_amount', 'decimal:0,3', 'gt:0', 'max:1000000'],
            'buy_quantity' => ['nullable', 'required_if:type,bogo', 'integer', 'min:1', 'max:20'],
            'get_quantity' => ['nullable', 'required_if:type,bogo', 'integer', 'min:1', 'max:20'],
            'target_type' => ['required', Rule::in(array_map(fn (CouponTarget $target): string => $target->value, SellerCoupons::TARGETS))],
            'target_product_ids' => ['nullable', 'array', 'max:500'],
            'target_product_ids.*' => ['integer'],
            'target_category_ids' => ['nullable', 'array', 'max:200'],
            'target_category_ids.*' => ['integer'],
            'target_brand_ids' => ['nullable', 'array', 'max:200'],
            'target_brand_ids.*' => ['integer'],
            'first_order_only' => ['boolean'],
            'customer_segment' => ['required', Rule::enum(CustomerSegment::class)],
            'min_order_amount' => ['nullable', 'decimal:0,3', 'min:0', 'max:1000000'],
            'max_discount_amount' => ['nullable', 'decimal:0,3', 'gt:0', 'max:1000000'],
            'usage_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'starts_at' => ['nullable', 'date_format:'.self::LOCAL_FORMAT],
            'ends_at' => ['required', 'date_format:'.self::LOCAL_FORMAT],
            'is_active' => ['boolean'],
            'is_public' => ['boolean'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $coupons = app(SellerCoupons::class);
            $vendor = $this->vendor();
            $type = CouponType::from((string) $this->input('type'));
            $value = (string) $this->input('value');

            if ($type === CouponType::Percentage && bccomp($value, (string) $coupons->maxPercent(), 3) > 0) {
                $validator->errors()->add('value', __('A seller coupon can give at most :max% off.', ['max' => $coupons->maxPercent()]));
            }

            if ($type === CouponType::Bogo && filled($value) && bccomp($value, '100', 3) > 0) {
                $validator->errors()->add('value', __('The BOGO discount on the free items is 1 to 100 %.'));
            }

            $this->checkTargets($validator, $coupons, $vendor);
            $this->checkDates($validator, $coupons);

            if ($this->boolean('is_active')) {
                if ($this->coupon?->admin_disabled_at !== null) {
                    $validator->errors()->add('is_active', __('Nawy switched this coupon off. Contact support to switch it on again.'));
                } elseif ($coupons->liveCount($vendor, $this->coupon?->id) >= $coupons->maxLive()) {
                    $validator->errors()->add('is_active', __('You can have at most :max coupons switched on. Switch one off first.', ['max' => $coupons->maxLive()]));
                }
            }

            if ($this->coupon !== null && $this->coupon->redemptions()->exists()) {
                $locked = $this->coupon->code !== $this->input('code')
                    || $this->coupon->type !== $type
                    || ($type !== CouponType::Bogo && bccomp((string) $this->coupon->value, $value, 4) !== 0)
                    || ($type === CouponType::Bogo && ((int) $this->coupon->buy_quantity !== $this->integer('buy_quantity') || (int) $this->coupon->get_quantity !== $this->integer('get_quantity')));

                if ($locked) {
                    $validator->errors()->add('code', __('Customers have used this coupon, so its code and discount cannot change. Create a new coupon instead.'));
                }
            }
        }];
    }

    /**
     * @return array<string, mixed>
     */
    public function attributesFor(Vendor $vendor): array
    {
        $country = app(CountryContext::class)->current();
        $clock = app(CountryClock::class);
        $type = CouponType::from((string) $this->input('type'));
        $target = CouponTarget::from((string) $this->input('target_type'));

        return [
            'code' => (string) $this->input('code'),
            'description' => $this->input('description'),
            'scope' => 'vendor',
            'vendor_id' => $vendor->id,
            'funded_by' => 'vendor',
            'type' => $type,
            'value' => match ($type) {
                CouponType::Bogo => filled($this->input('value')) ? (string) $this->input('value') : '100',
                default => (string) $this->input('value'),
            },
            'buy_quantity' => $type === CouponType::Bogo ? $this->integer('buy_quantity') : null,
            'get_quantity' => $type === CouponType::Bogo ? $this->integer('get_quantity') : null,
            'target_type' => $target,
            'target_ids' => $target === CouponTarget::All ? null : $this->targetIds($target),
            'first_order_only' => $this->boolean('first_order_only'),
            'customer_segment' => (string) $this->input('customer_segment'),
            'min_order_amount' => $this->filled('min_order_amount') ? (string) $this->input('min_order_amount') : null,
            'max_discount_amount' => $this->filled('max_discount_amount') ? (string) $this->input('max_discount_amount') : null,
            'usage_limit' => $this->filled('usage_limit') ? $this->integer('usage_limit') : null,
            'per_customer_limit' => $this->filled('per_customer_limit') ? $this->integer('per_customer_limit') : null,
            'starts_at' => $this->filled('starts_at') ? $clock->localToUtc($country, (string) $this->input('starts_at')) : null,
            'ends_at' => $clock->localToUtc($country, (string) $this->input('ends_at')),
            'is_active' => $this->boolean('is_active'),
            'is_public' => $this->boolean('is_public'),
        ];
    }

    private function checkTargets(Validator $validator, SellerCoupons $coupons, Vendor $vendor): void
    {
        $target = CouponTarget::from((string) $this->input('target_type'));

        if ($target === CouponTarget::All) {
            return;
        }

        $ids = $this->targetIds($target);
        $field = 'target_'.$target->value.'_ids';

        if ($ids === []) {
            $validator->errors()->add($field, __('Choose at least one of your :target.', ['target' => mb_strtolower($target->label())]));

            return;
        }

        $own = match ($target) {
            CouponTarget::Product => array_keys($coupons->productOptions($vendor)),
            CouponTarget::Category => array_keys($coupons->categoryOptions($vendor)),
            default => array_keys($coupons->brandOptions($vendor)),
        };

        if (array_diff($ids, array_map('intval', $own)) !== []) {
            $validator->errors()->add($field, __('Choose only your own :target.', ['target' => mb_strtolower($target->label())]));
        }
    }

    private function checkDates(Validator $validator, SellerCoupons $coupons): void
    {
        $country = app(CountryContext::class)->current();
        $clock = app(CountryClock::class);
        $start = $this->filled('starts_at') ? $clock->localToUtc($country, (string) $this->input('starts_at')) : null;
        $end = $clock->localToUtc($country, (string) $this->input('ends_at'));
        $from = $start !== null && $start->isAfter(now()) ? $start : CarbonImmutable::now();

        if ($start !== null && ! $end->isAfter($start)) {
            $validator->errors()->add('ends_at', __('The end must be after the start.'));
        } elseif ($this->boolean('is_active') && ! $end->isFuture()) {
            $validator->errors()->add('ends_at', __('The end must be in the future.'));
        } elseif ($end->isAfter($from->addDays($coupons->maxDays()))) {
            $validator->errors()->add('ends_at', __('A seller coupon can run for at most :days days.', ['days' => $coupons->maxDays()]));
        }
    }

    /**
     * @return array<int, int>
     */
    private function targetIds(CouponTarget $target): array
    {
        $raw = (array) $this->input('target_'.$target->value.'_ids', []);

        return array_values(array_unique(array_map('intval', array_filter($raw, fn ($id): bool => is_scalar($id) && ctype_digit((string) $id) && (int) $id > 0))));
    }

    private function vendor(): Vendor
    {
        return app(VendorWorkspace::class)->vendor();
    }
}
