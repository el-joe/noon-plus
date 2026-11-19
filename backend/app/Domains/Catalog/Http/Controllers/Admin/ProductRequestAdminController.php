<?php

namespace App\Domains\Catalog\Http\Controllers\Admin;

use App\Domains\Catalog\Enums\ProductRequestStatus;
use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\ProductRequest;
use App\Domains\Catalog\Support\ProductRequests;
use App\Domains\Identity\Support\AdminVendorScope;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Http\Controllers\Controller;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Commerce → Product requests (FR-CAT-2, lifecycle catalogue §1.3):
 * the queue of products stores and marketers asked for; approve (creates the
 * product with one variant, draft or active) or reject with a reason.
 * Account managers see only their stores' requests.
 */
class ProductRequestAdminController extends Controller
{
    public function __construct(private ProductRequests $requests, private AdminVendorScope $scope) {}

    public function index(Request $request): View
    {
        $filters = $request->validate(['status' => ['nullable', Rule::enum(ProductRequestStatus::class)], 'q' => ['nullable', 'string', 'max:120']]);
        $status = $filters['status'] ?? ProductRequestStatus::Pending->value;

        return view('panels.admin.product-requests.index', [
            'requests' => $this->scoped()->with('vendor')
                ->where('status', $status)
                ->when($filters['q'] ?? null, fn ($query, string $q) => $query->where(fn ($search) => $search->where('title_en', 'like', '%'.addcslashes($q, '%_\\').'%')->orWhere('gtin', $q)->orWhere('number', $q)))
                ->oldest('id')->paginate(25)->withQueryString(),
            'status' => $status,
            'counts' => ProductRequest::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'filters' => $filters,
        ]);
    }

    public function show(int $productRequest): View
    {
        $productRequest = $this->scoped()->with(['vendor', 'category', 'brand', 'product', 'transitions'])->findOrFail($productRequest);

        return view('panels.admin.product-requests.show', [
            'productRequest' => $productRequest,
            'marketer' => $productRequest->marketer_profile_id ? MarketerProfile::query()->find($productRequest->marketer_profile_id) : null,
            'categories' => Category::query()->orderBy('name_en')->pluck('name_en', 'id')->all(),
            'brands' => Brand::query()->orderBy('name')->pluck('name', 'id')->all(),
        ]);
    }

    public function approve(Request $request, int $productRequest): RedirectResponse
    {
        $productRequest = $this->scoped()->findOrFail($productRequest);
        $data = $request->validate([
            'category_id' => ['required', 'integer'],
            'brand_id' => ['nullable', 'integer'],
            'title_en' => ['required', 'string', 'max:255'],
            'title_ar' => ['required', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:5000'],
            'gtin' => ['nullable', 'string', 'regex:/^[0-9]{8,14}$/'],
            'activate' => ['boolean'],
        ]);

        try {
            $approved = $this->requests->approve($productRequest, $request->user()->id, $data + ['activate' => $request->boolean('activate')]);
        } catch (TransitionRefused $refused) {
            return back()->withInput()->withErrors(['action' => $refused->getMessage()]);
        }

        return redirect()->route('admin.product-requests.show', $approved->id)->with('status', __('Request approved and product created.'));
    }

    public function reject(Request $request, int $productRequest): RedirectResponse
    {
        $productRequest = $this->scoped()->findOrFail($productRequest);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']])['reason'];

        try {
            $this->requests->reject($productRequest, $request->user()->id, $reason);
        } catch (TransitionRefused $refused) {
            return back()->withErrors(['action' => $refused->getMessage()]);
        }

        return back()->with('status', __('Request rejected.'));
    }

    private function scoped()
    {
        $ids = $this->scope->vendorIds();

        return $ids === null ? ProductRequest::query() : ProductRequest::query()->whereIn('vendor_id', $ids);
    }
}
