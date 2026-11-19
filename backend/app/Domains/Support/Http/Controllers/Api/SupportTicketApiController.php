<?php

namespace App\Domains\Support\Http\Controllers\Api;

use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Support\Enums\TicketCategory;
use App\Domains\Support\Enums\TicketPriority;
use App\Domains\Support\Enums\TicketRequesterType;
use App\Domains\Support\Enums\TicketStatus;
use App\Domains\Support\Http\Requests\RateTicketRequest;
use App\Domains\Support\Http\Requests\ReopenTicketRequest;
use App\Domains\Support\Http\Requests\StoreTicketRequest;
use App\Domains\Support\Http\Requests\TicketReplyRequest;
use App\Domains\Support\Http\Resources\TicketResource;
use App\Domains\Support\Models\SupportTicket;
use App\Domains\Support\Models\SupportTicketMessage;
use App\Domains\Support\Support\SupportTickets;
use App\Domains\Support\Support\TicketAttachments;
use App\Domains\Support\Support\TicketRequester;
use App\Domains\Vendors\Models\Vendor;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use App\Shared\Localization\Label;
use App\Shared\StateMachine\TransitionRefused;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Support tickets over the API (QC-007, SRS §15): the customer's own
 * tickets (`support/*`, Sanctum) and the store's tickets in the vendor ops
 * app (`ops/vendor/support/*`, shared by the store's team). Another
 * requester's ticket is a 404. Refusals: 422 `not_allowed` for customers,
 * 422 `transition_refused` in the ops app (B6).
 */
class SupportTicketApiController extends Controller
{
    public function __construct(private SupportTickets $tickets) {}

    public function categories(Request $request): JsonResponse
    {
        $type = $this->workspaceVendor($request) === null ? TicketRequesterType::Customer : TicketRequesterType::Vendor;

        return ApiResponse::success([
            'categories' => array_map(fn (TicketCategory $category): array => ['value' => $category->value, 'label' => Label::of($category)], TicketCategory::for($type)),
            'priorities' => array_map(fn (string $priority): array => ['value' => $priority, 'label' => Label::of($priority)], TicketPriority::requesterValues()),
            'max_attachments' => (int) config('nawy.support.max_attachments'),
            'attachment_types' => config('nawy.support.attachment_mimes'),
            'attachment_max_kb' => (int) config('nawy.support.attachment_max_kb'),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(TicketStatus::class)],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);
        $tickets = $this->requester($request)->scope(SupportTicket::query())->with(['order:id,number', 'subOrder:id,number'])
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->orderByDesc('last_message_at')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 20));

        return ApiResponse::success(TicketResource::collection($tickets->items()), [
            'total' => $tickets->total(), 'page' => $tickets->currentPage(), 'per_page' => $tickets->perPage(), 'last_page' => $tickets->lastPage(),
        ]);
    }

    public function store(StoreTicketRequest $request): JsonResponse
    {
        $requester = $this->requester($request);
        [$order, $subOrder] = $this->reference($request, $requester);

        return $this->refusable($request, function () use ($request, $requester, $order, $subOrder): JsonResponse {
            $ticket = $this->tickets->open(
                $requester,
                TicketCategory::from($request->validated('category')),
                $request->validated('subject'),
                $request->validated('message'),
                TicketPriority::tryFrom((string) $request->validated('priority')) ?? TicketPriority::Normal,
                $order,
                $subOrder,
                $request->file('attachments', []),
            );

            return ApiResponse::success(new TicketResource($this->load($ticket)), status: 201);
        });
    }

    public function show(Request $request, string $number): JsonResponse
    {
        return ApiResponse::success(new TicketResource($this->find($request, $number)));
    }

    public function reply(TicketReplyRequest $request, string $number): JsonResponse
    {
        $ticket = $this->find($request, $number);

        return $this->refusable($request, fn (): JsonResponse => ApiResponse::success(new TicketResource($this->load(
            $this->tickets->requesterReply($ticket, $this->requester($request), $request->validated('body'), $request->file('attachments', []))
        )), status: 201));
    }

    public function reopen(ReopenTicketRequest $request, string $number): JsonResponse
    {
        $ticket = $this->find($request, $number);

        return $this->refusable($request, fn (): JsonResponse => ApiResponse::success(new TicketResource($this->load(
            $this->tickets->reopen($ticket, $this->requester($request), $request->validated('message'))
        ))));
    }

    public function rate(RateTicketRequest $request, string $number): JsonResponse
    {
        $ticket = $this->find($request, $number);

        return $this->refusable($request, fn (): JsonResponse => ApiResponse::success(new TicketResource($this->load(
            $this->tickets->rate($ticket, $this->requester($request), (int) $request->validated('rating'), $request->validated('comment'))
        ))));
    }

    /**
     * One attachment through a signed, expiring link (no token needed).
     */
    public function attachment(int $message, int $index, TicketAttachments $attachments): StreamedResponse
    {
        return $attachments->stream(SupportTicketMessage::query()->where('is_internal', false)->findOrFail($message), $index);
    }

    private function requester(Request $request): TicketRequester
    {
        $vendor = $this->workspaceVendor($request);

        return $vendor === null
            ? TicketRequester::customer($request->user()->id)
            : new TicketRequester(TicketRequesterType::Vendor, $request->user()->id, 'vendor', $vendor->id, $vendor->id);
    }

    private function workspaceVendor(Request $request): ?Vendor
    {
        $workspace = $request->attributes->get('ops_workspace');

        return $workspace instanceof Vendor ? $workspace : null;
    }

    /**
     * The order / shipment the ticket is about: the customer's own, or one
     * of the store's shipments.
     *
     * @return array{0: Order|null, 1: SubOrder|null}
     */
    private function reference(StoreTicketRequest $request, TicketRequester $requester): array
    {
        $order = null;
        $subOrder = null;

        if (filled($request->validated('order'))) {
            $order = Order::query()->where('number', $request->validated('order'))
                ->when($requester->type === TicketRequesterType::Customer,
                    fn ($query) => $query->where('customer_user_id', $requester->userId),
                    fn ($query) => $query->whereHas('subOrders', fn ($subOrders) => $subOrders->where('vendor_id', $requester->vendorId)))
                ->first() ?? throw ValidationException::withMessages(['order' => __('This order is not yours.')]);
        }

        if (filled($request->validated('shipment'))) {
            $subOrder = SubOrder::query()->where('number', $request->validated('shipment'))
                ->when($requester->type === TicketRequesterType::Customer,
                    fn ($query) => $query->whereHas('order', fn ($orders) => $orders->where('customer_user_id', $requester->userId)),
                    fn ($query) => $query->where('vendor_id', $requester->vendorId))
                ->when($order !== null, fn ($query) => $query->where('order_id', $order->id))
                ->first() ?? throw ValidationException::withMessages(['shipment' => __('This shipment is not yours.')]);
        }

        return [$order, $subOrder];
    }

    private function find(Request $request, string $number): SupportTicket
    {
        return $this->load($this->requester($request)->scope(SupportTicket::query())->where('number', $number)->firstOrFail());
    }

    private function load(SupportTicket $ticket): SupportTicket
    {
        return $ticket->load(['messages', 'transitions', 'order:id,number', 'subOrder:id,number']);
    }

    /**
     * @param  Closure(): JsonResponse  $action
     */
    private function refusable(Request $request, Closure $action): JsonResponse
    {
        if ($this->workspaceVendor($request) === null) {
            return $action();
        }

        try {
            return $action();
        } catch (TransitionRefused $refused) {
            return ApiResponse::error('transition_refused', $refused->getMessage(), 422);
        }
    }
}
