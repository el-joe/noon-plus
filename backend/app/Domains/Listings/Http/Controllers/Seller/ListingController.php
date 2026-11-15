<?php

namespace App\Domains\Listings\Http\Controllers\Seller;

use App\Domains\Catalog\Models\ProductVariant;
use App\Domains\Catalog\Support\ProductImages;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Exceptions\InsufficientStock;
use App\Domains\Listings\Exceptions\ListingTransitionRefused;
use App\Domains\Listings\Http\Requests\AdjustStockRequest;
use App\Domains\Listings\Http\Requests\StoreListingImagesRequest;
use App\Domains\Listings\Http\Requests\StoreListingRequest;
use App\Domains\Listings\Http\Requests\UpdateListingImageRequest;
use App\Domains\Listings\Http\Requests\UpdateListingRequest;
use App\Domains\Listings\Models\Listing;
use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Listings\Support\ListingStateMachine;
use App\Domains\Listings\Support\ListingSubmissionGates;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\OrderItem;
use App\Domains\Vendors\Support\VendorWorkspace;
use App\Http\Controllers\Controller;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Seller Center → Products (SRS §4.3 Listings, §5.2; Figma 13:2, 100:8566):
 * list, create from an existing catalogue variant behind the listing gates,
 * edit price, move stock through the ledger and pause / resume / archive.
 */
class ListingController extends Controller
{
    public function __construct(
        private VendorWorkspace $workspace,
        private ListingStateMachine $stateMachine,
        private InventoryLedger $ledger,
        private ListingSubmissionGates $gates,
        private CountryContext $countryContext,
        private ProductImages $images,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(ListingStatus::class)],
            'stock' => ['nullable', Rule::in(['in', 'low', 'out'])],
        ]);

        $listings = Listing::query()
            ->where('vendor_id', $this->workspace->vendor()->id)
            ->with('variant.product')
            ->withAvailableQuantity()
            ->when($filters['q'] ?? null, fn ($query, string $search) => $query->where(fn ($scoped) => $scoped
                ->where('vendor_sku', 'like', "%{$search}%")
                ->orWhereHas('variant', fn ($variant) => $variant->where('sku', 'like', "%{$search}%")
                    ->orWhereHas('product', fn ($product) => $product->where('title_en', 'like', "%{$search}%")->orWhere('title_ar', 'like', "%{$search}%")))))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['stock'] ?? null, fn ($query, string $stock) => match ($stock) {
                'out' => $query->havingRaw('coalesce(available_quantity, 0) <= 0'),
                'low' => $query->havingRaw('coalesce(available_quantity, 0) between 1 and 5'),
                default => $query->havingRaw('coalesce(available_quantity, 0) > 0'),
            })
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('panels.partner.products.index', [
            'listings' => $listings,
            'filters' => $filters,
            'currency' => $this->countryContext->current()->currency,
        ]);
    }

    public function create(Request $request): View
    {
        $search = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'variant' => ['nullable', 'integer'],
        ]);

        $vendor = $this->workspace->vendor();
        $variant = isset($search['variant'])
            ? ProductVariant::query()->with('product.category')->whereHas('product', fn ($product) => $product->where('status', 'active'))->find($search['variant'])
            : null;

        $matches = filled($search['q'] ?? null)
            ? ProductVariant::query()
                ->with('product')
                ->whereHas('product', fn ($product) => $product->where('status', 'active'))
                ->where(fn ($query) => $query
                    ->whereHas('product', fn ($product) => $product->where('title_en', 'like', "%{$search['q']}%")->orWhere('title_ar', 'like', "%{$search['q']}%"))
                    ->orWhere('sku', $search['q'])
                    ->orWhere('barcode', $search['q']))
                ->limit(20)
                ->get()
            : collect();

        return view('panels.partner.products.create', [
            'vendor' => $vendor,
            'search' => $search['q'] ?? '',
            'matches' => $matches,
            'variant' => $variant,
            'checklist' => $this->gates->checklist($vendor, $variant?->product->category),
        ]);
    }

    public function store(StoreListingRequest $request): RedirectResponse
    {
        $vendor = $this->workspace->vendor();
        $data = $request->validated();
        $variant = ProductVariant::query()->with('product.category')->findOrFail($data['product_variant_id']);

        if ($variant->product->status !== 'active') {
            return back()->withInput()->withErrors(['product_variant_id' => __('This product is not available for listing.')]);
        }

        try {
            $this->gates->assertPasses($vendor, $variant->product->category);
        } catch (ListingTransitionRefused $refused) {
            return back()->withInput()->withErrors(['action' => $refused->getMessage()]);
        }

        $listing = DB::connection($vendor->getConnectionName())->transaction(function () use ($vendor, $data, $request): Listing {
            $listing = Listing::query()->create([
                'vendor_id' => $vendor->id,
                'product_variant_id' => $data['product_variant_id'],
                'vendor_sku' => $data['vendor_sku'] ?? null,
                'price' => $data['price'],
                'sale_price' => $data['sale_price'] ?? null,
                'fulfilment' => $data['fulfilment'],
                'condition' => $data['condition'],
                'handling_days' => $data['handling_days'],
            ]);
            $listing->statusHistory()->create(['to_status' => ListingStatus::Draft, 'actor_user_id' => $request->user()->id]);

            if ($data['stock'] > 0) {
                $this->ledger->move($listing, (int) $data['stock'], 'initial_stock', actorUserId: $request->user()->id);
            }

            if ($request->hasFile('images')) {
                $listing->setRelation('variant', ProductVariant::query()->findOrFail($data['product_variant_id']));
                $this->images->add($listing, $request->file('images'), $data['image_alt_en'] ?? null, $data['image_alt_ar'] ?? null);
            }

            if ($data['intent'] === 'submit') {
                $this->stateMachine->transition($listing, ListingStatus::PendingReview, $request->user()->id);
            }

            return $listing;
        });

        return redirect()->route('partner.products.edit', $listing->id)->with('status', $data['intent'] === 'submit'
            ? __('Listing submitted for review.')
            : __('Listing saved as a draft.'));
    }

    public function edit(int $listing): View
    {
        $listing = $this->ownListing($listing);
        $listing->load(['variant.product.category', 'statusHistory' => fn ($query) => $query->latest('id')]);

        return view('panels.partner.products.edit', [
            'listing' => $listing,
            'available' => $this->ledger->available($listing),
            'movements' => $listing->movements()->latest('id')->limit(10)->get(),
            'images' => $this->images->forListing($listing),
            'maxImages' => ProductImages::MAX_PER_VENDOR_PRODUCT,
            'currency' => $this->countryContext->current()->currency,
        ]);
    }

    public function update(UpdateListingRequest $request, int $listing): RedirectResponse
    {
        $listing = $this->ownListing($listing);

        if ($listing->status === ListingStatus::Archived) {
            return back()->withErrors(['action' => __('An archived listing cannot be changed.')]);
        }

        $listing->update($request->validated());

        return back()->with('status', __('Listing updated.'));
    }

    /**
     * Product images (G-W1): for the whole product, or only this listing's variant.
     */
    public function addImages(StoreListingImagesRequest $request, int $listing): RedirectResponse
    {
        $listing = $this->ownListing($listing);
        $listing->loadMissing('variant');

        try {
            $this->images->add($listing, $request->file('images'), $request->validated('alt_en'), $request->validated('alt_ar'), $request->boolean('variant_only'));
        } catch (TransitionRefused $refused) {
            return back()->withErrors(['images' => $refused->getMessage()]);
        }

        return back()->with('status', __('Images added.'));
    }

    public function updateImage(UpdateListingImageRequest $request, int $listing, int $media): RedirectResponse
    {
        $listing = $this->ownListing($listing);
        $listing->loadMissing('variant');
        $this->images->update($this->images->ownImage($listing, $media), $request->validated());

        return back()->with('status', __('Image updated.'));
    }

    public function removeImage(int $listing, int $media): RedirectResponse
    {
        $listing = $this->ownListing($listing);
        $listing->loadMissing('variant');
        $this->images->remove($this->images->ownImage($listing, $media));

        return back()->with('status', __('Image removed.'));
    }

    public function adjustStock(AdjustStockRequest $request, int $listing): RedirectResponse
    {
        $listing = $this->ownListing($listing);

        if ($listing->status === ListingStatus::Archived) {
            return back()->withErrors(['action' => __('An archived listing cannot be changed.')]);
        }

        try {
            $this->ledger->move($listing, (int) $request->validated('quantity'), $request->validated('reason'), actorUserId: $request->user()->id);
        } catch (InsufficientStock $insufficient) {
            return back()->withErrors(['quantity' => __('Only :count units are in stock.', ['count' => $insufficient->available])]);
        }

        return back()->with('status', __('Stock updated.'));
    }

    public function changeStatus(Request $request, int $listing): RedirectResponse
    {
        $listing = $this->ownListing($listing);
        $action = $request->validate(['action' => ['required', Rule::in(['submit', 'pause', 'resume', 'archive'])]])['action'];
        $actor = $request->user()->id;

        try {
            match ($action) {
                'submit' => $this->submit($listing, $actor),
                'pause' => $this->stateMachine->transition($listing, ListingStatus::Paused, $actor),
                'resume' => $this->resume($listing, $actor),
                'archive' => $this->archive($listing, $actor),
            };
        } catch (ListingTransitionRefused $refused) {
            return back()->withErrors(['action' => $refused->getMessage()]);
        }

        return back()->with('status', __('Listing is now :status.', ['status' => str_replace('_', ' ', $listing->status->value)]));
    }

    private function submit(Listing $listing, int $actor): void
    {
        $this->gates->assertPasses($listing->vendor, $listing->variant->product->category);
        $this->stateMachine->transition($listing, ListingStatus::PendingReview, $actor);
    }

    /**
     * Resume needs stock (lifecycle catalogue §1.1).
     */
    private function resume(Listing $listing, int $actor): void
    {
        if ($listing->status === ListingStatus::Paused && $this->ledger->available($listing) <= 0) {
            throw ListingTransitionRefused::because(__('Add stock before resuming this listing.'));
        }

        $this->stateMachine->transition($listing, ListingStatus::Active, $actor);
    }

    /**
     * Archiving waits until no open sub-order still has the listing (proposed guard, lifecycle catalogue §1.1).
     */
    private function archive(Listing $listing, int $actor): void
    {
        $open = OrderItem::query()
            ->where('listing_id', $listing->id)
            ->whereHas('subOrder', fn ($query) => $query->whereIn('status', array_map(
                fn (SubOrderStatus $status): string => $status->value,
                [SubOrderStatus::Placed, SubOrderStatus::Confirmed, SubOrderStatus::Processing, SubOrderStatus::Packed, SubOrderStatus::Shipped, SubOrderStatus::OutForDelivery],
            )))
            ->exists();

        if ($open) {
            throw ListingTransitionRefused::because(__('This listing still has open orders; archive it once they are delivered or cancelled.'));
        }

        $this->stateMachine->transition($listing, ListingStatus::Archived, $actor);
    }

    /**
     * The store's own listing, or 404 for anyone else's (matrix §4 T4).
     */
    private function ownListing(int $id): Listing
    {
        $listing = Listing::query()->where('vendor_id', $this->workspace->vendor()->id)->findOrFail($id);
        Gate::authorize('manage', $listing);
        $listing->setRelation('vendor', $this->workspace->vendor());

        return $listing;
    }
}
