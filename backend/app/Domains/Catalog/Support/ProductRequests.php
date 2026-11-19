<?php

namespace App\Domains\Catalog\Support;

use App\Domains\Catalog\Enums\ProductRequestStatus;
use App\Domains\Catalog\Enums\ProductStatus;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductRequest;
use App\Domains\Catalog\Models\ProductVariant;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Identity\Enums\AdminPermission;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Panels\Listeners\NotifySellerOfStateChange;
use App\Domains\Panels\Support\AdminAlerts;
use App\Domains\Panels\Support\PanelNotifications;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Product requests (lifecycle catalogue §1.3, SRS §5.1 FR-CAT-2). A store
 * or marketer asks for a product that is not in the catalogue. Guard: no
 * catalogue variant with the same GTIN (barcode) and no product with the
 * same English or Arabic title, and no open request for the same GTIN or
 * title. Admins approve (the product is created with one variant, draft or
 * active, and linked), reject with a reason; the requester may cancel while
 * pending. Admins with `product_requests.review` and the requester are told.
 */
class ProductRequests
{
    public function __construct(
        private StateMachine $machine,
        private ProductStateMachine $products,
        private CountryContext $countryContext,
        private AdminAlerts $alerts,
    ) {}

    /**
     * @param  array{title_en: string, title_ar?: string|null, brand_name?: string|null, category_id?: int|null, gtin?: string|null, model_number?: string|null, description?: string|null, reference_url?: string|null}  $data
     */
    public function submit(string $requesterType, ?int $vendorId, ?int $marketerProfileId, int $userId, array $data): ProductRequest
    {
        $this->guardNotInCatalogue($data['title_en'], $data['title_ar'] ?? null, $data['gtin'] ?? null);

        $request = DB::connection((new ProductRequest)->getConnectionName())->transaction(function () use ($requesterType, $vendorId, $marketerProfileId, $userId, $data): ProductRequest {
            $request = ProductRequest::query()->create([
                'requester_type' => $requesterType,
                'vendor_id' => $vendorId,
                'marketer_profile_id' => $marketerProfileId,
                'requested_by_user_id' => $userId,
                'status' => ProductRequestStatus::Pending,
            ] + $data);
            $request->forceFill(['number' => sprintf('PR-%s-%06d', strtoupper($this->countryContext->current()->iso2), $request->id)])->save();
            $this->machine->created($request, $requesterType === ProductRequest::REQUESTER_VENDOR ? ActorType::Vendor : ActorType::Marketer, $userId);

            return $request;
        });

        $this->alerts->notify(AdminPermission::ProductRequestsReview, 'product_request.submitted',
            __('New product request :number', ['number' => $request->number]), $request->title_en, '/product-requests/'.$request->id);

        return $request;
    }

    /**
     * @param  array{category_id: int, brand_id?: int|null, title_en: string, title_ar: string, description_en?: string|null, gtin?: string|null, activate?: bool}  $data
     */
    public function approve(ProductRequest $request, int $adminUserId, array $data): ProductRequest
    {
        if ($request->status !== ProductRequestStatus::Pending) {
            throw new TransitionRefused(__('Only pending requests can be decided.'));
        }

        $gtin = $data['gtin'] ?? $request->gtin;
        $this->guardNotInCatalogue($data['title_en'], $data['title_ar'], $gtin, $request->id);
        Category::query()->findOrFail($data['category_id']);

        $request = DB::connection($request->getConnectionName())->transaction(function () use ($request, $adminUserId, $data, $gtin): ProductRequest {
            $product = Product::query()->create([
                'category_id' => $data['category_id'],
                'brand_id' => $data['brand_id'] ?? null,
                'slug' => Str::slug($data['title_en']).'-'.Str::lower(Str::random(6)),
                'title_en' => $data['title_en'],
                'title_ar' => $data['title_ar'],
                'model_number' => $request->model_number,
                'description_en' => $data['description_en'] ?? $request->description,
                'status' => ProductStatus::Draft->value,
            ]);
            ProductVariant::query()->create([
                'product_id' => $product->id,
                'sku' => 'NW-'.$product->id.'-'.Str::upper(Str::random(6)),
                'barcode' => $gtin,
            ]);

            if ($data['activate'] ?? false) {
                $this->products->move($product, ProductStatus::Active, ActorType::Admin, $adminUserId, "Product request {$request->number}");
            }

            return $this->machine->move($request, ProductRequestStatus::Approved, ActorType::Admin, $adminUserId, null, [
                'product_id' => $product->id,
                'category_id' => $data['category_id'],
                'brand_id' => $data['brand_id'] ?? null,
                'reviewed_by' => $adminUserId,
                'reviewed_at' => now(),
            ]);
        });

        $this->tellRequester($request, 'product_request.approved', __('Product request :number approved', ['number' => $request->number]),
            __(':title is now in the catalogue; you can add your offer.', ['title' => $request->title_en]));

        return $request;
    }

    public function reject(ProductRequest $request, int $adminUserId, string $reason): ProductRequest
    {
        $request = $this->machine->move($request, ProductRequestStatus::Rejected, ActorType::Admin, $adminUserId, $reason, [
            'rejection_reason' => mb_substr($reason, 0, 255), 'reviewed_by' => $adminUserId, 'reviewed_at' => now(),
        ]);
        $this->tellRequester($request, 'product_request.rejected', __('Product request :number rejected', ['number' => $request->number]), $reason);

        return $request;
    }

    public function cancel(ProductRequest $request, int $userId): ProductRequest
    {
        return $this->machine->move($request, ProductRequestStatus::Cancelled, $request->requester_type === ProductRequest::REQUESTER_VENDOR ? ActorType::Vendor : ActorType::Marketer, $userId, 'Cancelled by the requester');
    }

    private function guardNotInCatalogue(string $titleEn, ?string $titleAr, ?string $gtin, ?int $ignoreRequestId = null): void
    {
        $gtin = filled($gtin) ? trim($gtin) : null;

        if ($gtin !== null && ProductVariant::query()->where('barcode', $gtin)->exists()) {
            throw new TransitionRefused(__('A product with this barcode (GTIN) is already in the catalogue.'));
        }

        $titles = array_values(array_filter([trim($titleEn), $titleAr === null ? null : trim($titleAr)]));

        if (Product::query()->where(fn ($query) => $query->whereIn('title_en', $titles)->orWhereIn('title_ar', $titles))->exists()) {
            throw new TransitionRefused(__('A product with this name is already in the catalogue.'));
        }

        $open = ProductRequest::query()->where('status', ProductRequestStatus::Pending)
            ->when($ignoreRequestId !== null, fn ($query) => $query->whereKeyNot($ignoreRequestId))
            ->where(fn ($query) => $query->where('title_en', trim($titleEn))->when($gtin !== null, fn ($q) => $q->orWhere('gtin', $gtin)))
            ->exists();

        if ($open) {
            throw new TransitionRefused(__('This product has already been requested and is waiting for review.'));
        }
    }

    private function tellRequester(ProductRequest $request, string $type, string $title, ?string $body): void
    {
        if ($request->requester_type === ProductRequest::REQUESTER_VENDOR && $request->vendor_id !== null) {
            NotifySellerOfStateChange::notifyVendor($request->vendor_id, $type, $title, $body, '/product-requests');

            return;
        }

        $userId = MarketerProfile::query()->whereKey($request->marketer_profile_id)->value('user_id') ?? $request->requested_by_user_id;
        app(PanelNotifications::class)->notify((int) $userId, 'marketer', $type, $title, $body, '/product-requests');
    }
}
