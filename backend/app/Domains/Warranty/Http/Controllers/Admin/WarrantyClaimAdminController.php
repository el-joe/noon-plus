<?php

namespace App\Domains\Warranty\Http\Controllers\Admin;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Disputes\Models\Dispute;
use App\Domains\Identity\Support\AdminVendorScope;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Warranty\Enums\WarrantyClaimStatus;
use App\Domains\Warranty\Enums\WarrantyCoverage;
use App\Domains\Warranty\Enums\WarrantyOutcome;
use App\Domains\Warranty\Models\WarrantyClaim;
use App\Domains\Warranty\Support\WarrantyClaims;
use App\Http\Controllers\Controller;
use App\Shared\Money\MoneyFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Orders & disputes → Warranty claims (QC-002, SRS §7.7, lifecycle
 * §2.8): extended-plan claims are Nawy's to decide; standard claims are the
 * seller's, but an admin can step in. The three-party thread, review,
 * approve/reject, fulfil (repair, replace, refund through Refunds), resolve
 * and close. Disputes on the same shipment are linked. Account managers see
 * their vendors' claims only (QC-019).
 */
class WarrantyClaimAdminController extends Controller
{
    public function __construct(private WarrantyClaims $claims, private CountryContext $countryContext, private AdminVendorScope $scope) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(WarrantyClaimStatus::class)],
            'coverage' => ['nullable', Rule::enum(WarrantyCoverage::class)],
        ]);

        return view('panels.admin.warranty-claims.index', [
            'claims' => $this->scope->apply(WarrantyClaim::query())->with('subOrder:id,number')
                ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
                ->when($filters['coverage'] ?? null, fn ($query, string $coverage) => $query->where('coverage', $coverage))
                ->latest('id')->paginate(25)->withQueryString(),
            'filters' => $filters,
            'country' => $this->countryContext->current(),
        ]);
    }

    public function show(Request $request, int $claim): View
    {
        $claim = $this->find($claim)->load(['messages', 'subOrder', 'orderItem', 'transitions']);

        return view('panels.admin.warranty-claims.show', [
            'claim' => $claim,
            'disputes' => $this->scope->apply(Dispute::query())->where('sub_order_id', $claim->sub_order_id)->get(),
            'country' => $this->countryContext->current(),
            'canManage' => $request->user()->can('warranty_claims.manage'),
        ]);
    }

    public function message(Request $request, int $claim): RedirectResponse
    {
        $body = $request->validate(['body' => ['required', 'string', 'min:2', 'max:2000']])['body'];
        $this->claims->message($this->find($claim), ActorType::Admin, $request->user()->id, $body);

        return back()->with('status', __('Message sent.'));
    }

    public function act(Request $request, int $claim, string $action, MoneyFactory $money): RedirectResponse
    {
        $claim = $this->find($claim);
        $userId = $request->user()->id;

        match ($action) {
            'review' => $this->claims->review($claim, ActorType::Admin, $userId),
            'approve' => $this->claims->approve($claim, ActorType::Admin, $userId),
            'reject' => $this->claims->reject($claim, ActorType::Admin, $userId, $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason']),
            'fulfil' => (function () use ($request, $claim, $userId, $money): void {
                $data = $request->validate([
                    'outcome' => ['required', Rule::enum(WarrantyOutcome::class)],
                    'refund_amount' => ['nullable', 'decimal:0,3', 'gt:0'],
                ]);
                $amount = filled($data['refund_amount'] ?? null) ? $money->of((string) $data['refund_amount'], $this->countryContext->current()->currency) : null;
                $this->claims->fulfil($claim, WarrantyOutcome::from($data['outcome']), ActorType::Admin, $userId, $amount);
            })(),
            'resolve' => $this->claims->resolve($claim, ActorType::Admin, $userId),
            'close' => $this->claims->close($claim, ActorType::Admin, $userId),
        };

        return back()->with('status', __('Claim updated.'));
    }

    private function find(int $claim): WarrantyClaim
    {
        return $this->scope->apply(WarrantyClaim::query())->findOrFail($claim);
    }
}
