<?php

namespace App\Domains\Returns\Http\Controllers\Admin;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Identity\Support\AdminVendorScope;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Returns\Enums\ReturnStatus;
use App\Domains\Returns\Http\Controllers\ManagesReturns;
use App\Domains\Returns\Models\ReturnRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Orders & disputes → Returns (SRS §4.1.4, §7.4): every return of
 * the selected country, review, logistics steps and inspection.
 */
class AdminReturnController extends Controller
{
    use ManagesReturns;

    public function __construct(private CountryContext $countryContext) {}

    public function index(Request $request): View
    {
        $filters = $request->validate(['status' => ['nullable', Rule::enum(ReturnStatus::class)]]);

        return view('panels.admin.returns.index', [
            'returns' => app(AdminVendorScope::class)->apply(ReturnRequest::query())->with(['vendor:id,store_name', 'subOrder:id,number'])->withCount('items')
                ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
                ->latest('id')->paginate(25)->withQueryString(),
            'filters' => $filters,
            'country' => $this->countryContext->current(),
            'routePrefix' => 'admin.returns',
        ]);
    }

    public function show(int $return): View
    {
        return view('panels.admin.returns.show', [
            'return' => $this->findReturn($return)->load(['items.orderItem', 'subOrder', 'vendor', 'refund', 'transitions']),
            'country' => $this->countryContext->current(),
            'routePrefix' => 'admin.returns',
            'canManage' => request()->user()->can('returns.manage'),
        ]);
    }

    protected function findReturn(int $id): ReturnRequest
    {
        return app(AdminVendorScope::class)->apply(ReturnRequest::query())->findOrFail($id);
    }

    protected function actorType(): ActorType
    {
        return ActorType::Admin;
    }
}
