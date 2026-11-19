<?php

namespace App\Domains\Returns\Http\Controllers\Seller;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Panels\Support\TenantPermissions;
use App\Domains\Returns\Enums\ReturnStatus;
use App\Domains\Returns\Http\Controllers\ManagesReturns;
use App\Domains\Returns\Models\ReturnRequest;
use App\Domains\Vendors\Support\VendorWorkspace;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Seller Center → Returns (SRS §4.3, §7.4): the store's returns only
 * (others are 404), review, receive and inspect.
 */
class SellerReturnController extends Controller
{
    use ManagesReturns;

    public function __construct(private VendorWorkspace $workspace, private CountryContext $countryContext) {}

    public function index(Request $request): View
    {
        $filters = $request->validate(['status' => ['nullable', Rule::enum(ReturnStatus::class)]]);

        return view('panels.partner.returns.index', [
            'returns' => ReturnRequest::query()->where('vendor_id', $this->workspace->vendor()->id)->with('subOrder:id,number')->withCount('items')
                ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
                ->latest('id')->paginate(25)->withQueryString(),
            'filters' => $filters,
            'country' => $this->countryContext->current(),
        ]);
    }

    public function show(Request $request, int $return, TenantPermissions $permissions): View
    {
        return view('panels.partner.returns.show', [
            'return' => $this->findReturn($return)->load(['items.orderItem', 'subOrder', 'refund', 'transitions']),
            'country' => $this->countryContext->current(),
            'routePrefix' => 'partner.returns',
            'canManage' => $permissions->allows($request, 'partner', 'returns', true),
        ]);
    }

    protected function findReturn(int $id): ReturnRequest
    {
        return ReturnRequest::query()->where('vendor_id', $this->workspace->vendor()->id)->findOrFail($id);
    }

    protected function actorType(): ActorType
    {
        return ActorType::Vendor;
    }
}
