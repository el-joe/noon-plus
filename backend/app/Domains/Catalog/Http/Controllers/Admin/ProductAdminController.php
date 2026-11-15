<?php

namespace App\Domains\Catalog\Http\Controllers\Admin;

use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductVariant;
use App\Domains\Catalog\Models\SizeGuideTemplate;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Identity\Support\AdminVendorScope;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Models\Listing;
use App\Http\Controllers\Controller;
use App\Shared\Http\LikeSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Catalog → Products (QC-003): catalogue products and variants,
 * with the sellers' offers on them. Editing the copy, category, brand and
 * status interplays with the listing review (Approvals queue): an inactive
 * product cannot have offers approved, and its live offers are shown so
 * the admin sees what a deactivation affects. Account managers only see
 * products their vendors sell (QC-019).
 */
class ProductAdminController extends Controller
{
    public const STATUSES = ['active', 'inactive'];

    public const FULFILMENT_MODES = ['ready_to_ship', 'made_to_order'];

    public function __construct(private CountryContext $countryContext, private AdminVendorScope $scope) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'category' => ['nullable', 'integer'],
            'brand' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
        ]);

        $products = $this->scoped(Product::query())
            ->with(['category:id,name_en', 'brand:id,name'])
            ->withCount('variants')
            ->when($filters['q'] ?? null, fn ($query, string $search) => $query->where(fn ($inner) => $inner
                ->where('title_en', 'like', LikeSearch::contains($search))
                ->orWhere('title_ar', 'like', LikeSearch::contains($search))
                ->orWhere('slug', 'like', LikeSearch::contains($search))
                ->orWhereHas('variants', fn ($variant) => $variant->where('sku', 'like', LikeSearch::contains($search)))))
            ->when($filters['category'] ?? null, fn ($query, int $category) => $query->where('category_id', $category))
            ->when($filters['brand'] ?? null, fn ($query, int $brand) => $query->where('brand_id', $brand))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest('id')->paginate(25)->withQueryString();

        return view('panels.admin.catalog.products', [
            'products' => $products,
            'filters' => $filters,
            'country' => $this->countryContext->current(),
            'categories' => Category::query()->orderBy('name_en')->pluck('name_en', 'id')->all(),
            'brands' => Brand::query()->orderBy('name')->pluck('name', 'id')->all(),
        ]);
    }

    public function show(int $product): View
    {
        $product = $this->scoped(Product::query())->with(['category', 'brand', 'variants', 'media'])->findOrFail($product);
        $listings = $this->scope->apply(Listing::query())
            ->whereIn('product_variant_id', $product->variants->pluck('id'))
            ->with(['vendor:id,store_name', 'variant:id,sku'])
            ->latest('id')->get();

        return view('panels.admin.catalog.product', [
            'product' => $product,
            'listings' => $listings,
            'country' => $this->countryContext->current(),
            'canUpdate' => request()->user()->can('products.update') && ! $this->scope->isRestricted(),
        ]);
    }

    public function edit(int $product): View
    {
        $product = Product::query()->with('variants')->findOrFail($product);

        return view('panels.admin.catalog.product-form', [
            'product' => $product,
            'country' => $this->countryContext->current(),
            'categories' => Category::query()->orderBy('name_en')->pluck('name_en', 'id')->all(),
            'brands' => Brand::query()->orderBy('name')->pluck('name', 'id')->all(),
            'sizeGuides' => SizeGuideTemplate::query()->whereNull('vendor_id')->orderBy('name')->pluck('name', 'id')->all(),
            'liveListings' => Listing::query()->whereIn('product_variant_id', $product->variants->pluck('id'))->where('status', ListingStatus::Active)->count(),
        ]);
    }

    public function update(Request $request, int $product): RedirectResponse
    {
        $product = Product::query()->findOrFail($product);
        $data = $request->validate([
            'title_en' => ['required', 'string', 'max:255'],
            'title_ar' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'alpha_dash', 'max:255', Rule::unique(Product::class, 'slug')->ignore($product->id)],
            'description_en' => ['nullable', 'string', 'max:10000'],
            'description_ar' => ['nullable', 'string', 'max:10000'],
            'category_id' => ['required', 'integer', Rule::exists(Category::class, 'id')],
            'brand_id' => ['nullable', 'integer', Rule::exists(Brand::class, 'id')],
            'size_guide_template_id' => ['nullable', 'integer', Rule::exists(SizeGuideTemplate::class, 'id')],
            'status' => ['required', Rule::in(self::STATUSES)],
            'fulfilment_mode' => ['required', Rule::in(self::FULFILMENT_MODES)],
            'lead_days' => ['nullable', 'integer', 'min:0', 'max:180'],
        ]);
        $product->update($data);

        return redirect()->route('admin.catalog.products.show', $product->id)->with('status', __('Product saved.'));
    }

    public function updateVariant(Request $request, int $variant): RedirectResponse
    {
        $variant = ProductVariant::query()->findOrFail($variant);
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:64', Rule::unique(ProductVariant::class, 'sku')->ignore($variant->id)],
            'barcode' => ['nullable', 'string', 'max:32'],
            'weight_grams' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);
        $before = $variant->only(array_keys($data));
        $variant->update($data);

        activity('products')->performedOn($variant)->causedBy($request->user())->event('updated')
            ->withProperties(['country' => $this->countryContext->current()->code, 'old' => $before, 'attributes' => $data])
            ->log('Variant updated');

        return back()->with('status', __('Variant saved.'));
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    private function scoped($query)
    {
        $vendorIds = $this->scope->vendorIds();

        return $vendorIds === null ? $query : $query->whereHas('variants.listings', fn ($listing) => $listing->whereIn('vendor_id', $vendorIds));
    }
}
