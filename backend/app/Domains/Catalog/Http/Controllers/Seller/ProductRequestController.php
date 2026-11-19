<?php

namespace App\Domains\Catalog\Http\Controllers\Seller;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\ProductRequest;
use App\Domains\Catalog\Support\ProductRequests;
use App\Domains\Marketers\Support\MarketerWorkspace;
use App\Domains\Vendors\Support\VendorWorkspace;
use App\Http\Controllers\Controller;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Seller Center and Creator Studio → Product requests (FR-CAT-2, lifecycle
 * catalogue §1.3): ask Nawy to add a product that is not in the catalogue,
 * follow the decision, cancel while pending. The panel comes from the route
 * default `panel` (partner = the store, marketer = the marketer profile);
 * every query is scoped to that requester.
 */
class ProductRequestController extends Controller
{
    public function __construct(private ProductRequests $requests) {}

    public function index(Request $request): View
    {
        $panel = $this->panel($request);

        return view('panels.shared.product-requests', [
            'panel' => $panel,
            'requests' => $this->own($request)->latest('id')->paginate(20),
            'categories' => Category::query()->where('is_active', true)->orderBy('name_en')->get(['id', 'name_en', 'name_ar']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title_en' => ['required', 'string', 'min:3', 'max:255'],
            'title_ar' => ['nullable', 'string', 'max:255'],
            'brand_name' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer', 'exists:'.(new Category)->getConnectionName().'.categories,id'],
            'gtin' => ['nullable', 'string', 'regex:/^[0-9]{8,14}$/'],
            'model_number' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:2000'],
            'reference_url' => ['nullable', 'url', 'max:500'],
        ]);

        try {
            $created = $this->panel($request) === 'partner'
                ? $this->requests->submit(ProductRequest::REQUESTER_VENDOR, app(VendorWorkspace::class)->vendor()->id, null, $request->user()->id, $data)
                : $this->requests->submit(ProductRequest::REQUESTER_MARKETER, null, app(MarketerWorkspace::class)->marketer()->id, $request->user()->id, $data);
        } catch (TransitionRefused $refused) {
            return back()->withInput()->withErrors(['title_en' => $refused->getMessage()]);
        }

        return back()->with('status', __('Product request :number sent.', ['number' => $created->number]));
    }

    public function cancel(Request $request, int $productRequest): RedirectResponse
    {
        try {
            $this->requests->cancel($this->own($request)->findOrFail($productRequest), $request->user()->id);
        } catch (TransitionRefused $refused) {
            return back()->withErrors(['action' => $refused->getMessage()]);
        }

        return back()->with('status', __('Product request cancelled.'));
    }

    /**
     * @return Builder<ProductRequest>
     */
    private function own(Request $request): Builder
    {
        return $this->panel($request) === 'partner'
            ? ProductRequest::query()->where('requester_type', ProductRequest::REQUESTER_VENDOR)->where('vendor_id', app(VendorWorkspace::class)->vendor()->id)
            : ProductRequest::query()->where('requester_type', ProductRequest::REQUESTER_MARKETER)->where('marketer_profile_id', app(MarketerWorkspace::class)->marketer()->id);
    }

    private function panel(Request $request): string
    {
        return (string) ($request->route()?->defaults['panel'] ?? 'partner');
    }
}
