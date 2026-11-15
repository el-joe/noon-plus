<?php

namespace App\Domains\Disputes\Http\Controllers\Admin;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Disputes\Enums\DisputeResolution;
use App\Domains\Disputes\Enums\DisputeStatus;
use App\Domains\Disputes\Models\Dispute;
use App\Domains\Disputes\Support\Disputes;
use App\Domains\Identity\Support\AdminVendorScope;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Warranty\Models\WarrantyClaim;
use App\Http\Controllers\Controller;
use App\Shared\Money\MoneyFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Orders & disputes → Disputes (QC-001, SRS §7.6, lifecycle §2.7):
 * the country's disputes, the thread, and the admin moves through the
 * dispute machine — review, escalate, resolve (refund through Refunds for
 * favor_customer / split) and close.
 */
class AdminDisputeController extends Controller
{
    public function __construct(private Disputes $disputes, private CountryContext $countryContext, private AdminVendorScope $scope) {}

    public function index(Request $request): View
    {
        $filters = $request->validate(['status' => ['nullable', Rule::enum(DisputeStatus::class)]]);

        return view('panels.admin.disputes.index', [
            'disputes' => $this->scope->apply(Dispute::query())->with('subOrder:id,number')
                ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
                ->latest('id')->paginate(25)->withQueryString(),
            'filters' => $filters,
            'country' => $this->countryContext->current(),
        ]);
    }

    public function show(Request $request, int $dispute): View
    {
        $model = $this->find($dispute)->load(['messages', 'subOrder', 'order']);

        return view('panels.admin.disputes.show', [
            'dispute' => $model,
            'warrantyClaims' => WarrantyClaim::query()->where('sub_order_id', $model->sub_order_id)->get(),
            'country' => $this->countryContext->current(),
            'canManage' => $request->user()->can('disputes.manage'),
        ]);
    }

    public function message(Request $request, int $dispute): RedirectResponse
    {
        $body = $request->validate(['body' => ['required', 'string', 'min:2', 'max:2000']])['body'];
        $this->disputes->message($this->find($dispute), ActorType::Admin, $request->user()->id, $body);

        return back()->with('status', __('Message sent.'));
    }

    public function review(Request $request, int $dispute): RedirectResponse
    {
        $this->disputes->review($this->find($dispute), $request->user()->id);

        return back()->with('status', __('The dispute is under review.'));
    }

    public function escalate(Request $request, int $dispute): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason'];
        $this->disputes->escalate($this->find($dispute), $request->user()->id, $reason);

        return back()->with('status', __('The dispute is escalated.'));
    }

    public function resolve(Request $request, int $dispute, MoneyFactory $money): RedirectResponse
    {
        $dispute = $this->find($dispute)->load('order');
        $data = $request->validate([
            'resolution' => ['required', Rule::enum(DisputeResolution::class)],
            'refund_amount' => ['nullable', 'required_if:resolution,split', 'decimal:0,3', 'gt:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $amount = filled($data['refund_amount'] ?? null) ? $money->of((string) $data['refund_amount'], $dispute->order->currency) : null;
        $this->disputes->resolve($dispute, $request->user()->id, DisputeResolution::from($data['resolution']), $amount, $data['note'] ?? null);

        return back()->with('status', __('The dispute is resolved.'));
    }

    public function close(Request $request, int $dispute): RedirectResponse
    {
        $this->disputes->close($this->find($dispute), ActorType::Admin, $request->user()->id);

        return back()->with('status', __('The dispute is closed.'));
    }

    /**
     * Account managers reach their vendors' disputes only (QC-019).
     */
    private function find(int $dispute): Dispute
    {
        return $this->scope->apply(Dispute::query())->findOrFail($dispute);
    }
}
