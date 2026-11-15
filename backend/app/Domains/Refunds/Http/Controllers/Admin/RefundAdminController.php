<?php

namespace App\Domains\Refunds\Http\Controllers\Admin;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Refunds\Enums\RefundDestination;
use App\Domains\Refunds\Enums\RefundSource;
use App\Domains\Refunds\Enums\RefundStatus;
use App\Domains\Refunds\Models\Refund;
use App\Domains\Refunds\Support\Refunds;
use App\Http\Controllers\Controller;
use App\Shared\Money\MoneyFactory;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Orders & disputes → Refunds (QC-029, SRS §7.5, lifecycle §2.6):
 * every refund of the country; pending ones wait for approval (then they are
 * processed), failed ones can be retried. Finance can raise a manual refund
 * on an order; a manual refund needs a second admin to approve it
 * (maker-checker). All moves go through {@see Refunds}.
 */
class RefundAdminController extends Controller
{
    public function __construct(private Refunds $refunds, private CountryContext $countryContext) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(RefundStatus::class)],
            'source' => ['nullable', Rule::enum(RefundSource::class)],
        ]);
        $status = $filters['status'] ?? ($request->has('status') ? null : RefundStatus::Pending->value);

        return view('panels.admin.refunds.index', [
            'refunds' => Refund::query()->with('order:id,number')
                ->when($status, fn ($query, string $value) => $query->where('status', $value))
                ->when($filters['source'] ?? null, fn ($query, string $source) => $query->where('source_type', $source))
                ->latest('id')->paginate(25)->withQueryString(),
            'counts' => Refund::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'filters' => ['status' => $status] + $filters,
            'country' => $this->countryContext->current(),
        ]);
    }

    public function show(int $refund): View
    {
        $refund = Refund::query()->with(['order', 'subOrder', 'paymentRefund', 'transitions'])->findOrFail($refund);

        return view('panels.admin.refunds.show', [
            'refund' => $refund,
            'refundable' => $this->refunds->refundable($refund->order),
            'country' => $this->countryContext->current(),
        ]);
    }

    /**
     * A manual refund on an order (source admin), left pending for approval.
     */
    public function store(Request $request, int $order, MoneyFactory $money): RedirectResponse
    {
        $order = Order::query()->findOrFail($order);
        $data = $request->validate([
            'amount' => ['required', 'decimal:0,3', 'gt:0'],
            'destination' => ['required', Rule::enum(RefundDestination::class)],
            'sub_order_id' => ['nullable', 'integer', Rule::exists(SubOrder::class, 'id')->where('order_id', $order->id)],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);
        $refund = $this->refunds->request(
            $order,
            isset($data['sub_order_id']) ? SubOrder::query()->find($data['sub_order_id']) : null,
            $money->of((string) $data['amount'], $order->currency),
            RefundSource::Admin,
            null,
            RefundDestination::from($data['destination']),
            'admin:'.$order->id.':'.Str::uuid(),
            ActorType::Admin,
            $request->user()->id,
            $data['reason'],
        );

        return redirect()->route('admin.refunds.show', $refund->id)->with('status', __('Refund :number requested — another admin approves it.', ['number' => $refund->number]));
    }

    public function approve(Request $request, int $refund): RedirectResponse
    {
        $refund = Refund::query()->findOrFail($refund);

        if ($refund->status !== RefundStatus::Pending) {
            throw new TransitionRefused(__('Only a pending refund can be approved.'));
        }

        if ($refund->source_type === RefundSource::Admin && $this->requestedBy($refund) === $request->user()->id) {
            throw new TransitionRefused(__('A refund you requested must be approved by another admin.'));
        }

        $this->refunds->approve($refund, ActorType::Admin, $request->user()->id, $request->input('note'));
        $refund = $this->refunds->process($refund->refresh(), ActorType::Admin, $request->user()->id);

        return back()->with('status', $refund->status === RefundStatus::Completed ? __('Refund approved and paid out.') : __('Refund approved, but processing failed: :message', ['message' => $refund->failure_message]));
    }

    public function reject(Request $request, int $refund): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason'];
        $this->refunds->reject(Refund::query()->findOrFail($refund), ActorType::Admin, $request->user()->id, $reason);

        return back()->with('status', __('Refund rejected.'));
    }

    public function retry(Request $request, int $refund): RedirectResponse
    {
        $refund = Refund::query()->findOrFail($refund);

        if ($refund->status !== RefundStatus::Failed) {
            throw new TransitionRefused(__('Only a failed refund can be retried.'));
        }

        $refund = $this->refunds->process($refund, ActorType::Admin, $request->user()->id);

        return back()->with('status', $refund->status === RefundStatus::Completed ? __('Refund paid out.') : __('The refund failed again: :message', ['message' => $refund->failure_message]));
    }

    private function requestedBy(Refund $refund): ?int
    {
        $first = $refund->transitions()->first();

        return $first?->actor_user_id === null ? null : (int) $first->actor_user_id;
    }
}
