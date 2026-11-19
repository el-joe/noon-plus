<?php

namespace App\Domains\Orders\Http\Controllers;

use App\Domains\Cart\Exceptions\CartRejected;
use App\Domains\Cart\Support\CartService;
use App\Domains\Contracts\Support\CheckoutContracts;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\OrderCancelReason;
use App\Domains\Orders\Enums\OrderListTab;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Exceptions\SubOrderTransitionRefused;
use App\Domains\Orders\Http\Requests\CancelShipmentRequest;
use App\Domains\Orders\Http\Requests\OrderIndexRequest;
use App\Domains\Orders\Http\Requests\PlaceOrderRequest;
use App\Domains\Orders\Http\Resources\OrderResource;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Support\InvoicePdf;
use App\Domains\Orders\Support\PlaceOrder;
use App\Domains\Orders\Support\SubOrderStateMachine;
use App\Domains\Payments\Enums\PaymentMethod;
use App\Domains\Payments\Support\PaymentAttempts;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\LikeSearch;
use App\Shared\Localization\Label;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

class OrderController extends Controller
{
    /**
     * Place the order and start its payment. A new account must have a
     * verified email (ADR 5) and must have accepted the contracts that apply
     * (FR-CHK-4: 409 `customer_contract_required`). The response carries `payment.next_action`:
     * a redirect for card/BNPL (hosted page, 3DS), none for COD, or a retry
     * with another method when the gateway could not start.
     */
    public function store(PlaceOrderRequest $request, CartService $carts, PlaceOrder $placeOrder, PaymentAttempts $payments, CheckoutContracts $checkoutContracts): JsonResponse
    {
        $user = $request->user();

        if ($user->email_verified_at === null) {
            return ApiResponse::error('email_not_verified', __('Verify your email before placing an order.'), 403);
        }

        $cart = $carts->forCustomer($user->id);
        $visitorToken = $request->validated('visitor_token') ?? $request->header('X-Visitor-Token');
        $missing = $checkoutContracts->missing($cart, $user->id, $visitorToken, $request->validated('referral_code'));

        if ($missing !== []) {
            // QC-054: the shared envelope, with the contracts to accept.
            return ApiResponse::error('customer_contract_required', __('Accept the required contract before checking out.'), 409, extra: ['contracts' => $missing]);
        }

        try {
            $order = $placeOrder->handle(
                $cart,
                $user->id,
                $request->validated('payment_method'),
                $request->shippingAddress(),
                $visitorToken,
                $request->validated('referral_code'),
            );
        } catch (CartRejected $rejected) {
            return ApiResponse::error($rejected->errorCode, $rejected->getMessage(), $rejected->status);
        }

        $payments->start($order, PaymentMethod::from($order->payment_method), $user);

        return ApiResponse::success(new OrderResource($order->refresh()->load('subOrders.vendor', 'subOrders.items', 'payments')), status: 201);
    }

    /**
     * QC-026: tabs (all / in_progress / delivered / cancelled / returns),
     * search and paging; `meta.counts` per tab for the tab badges. Every
     * shipment carries its `actions`. QC-050: one query per relation.
     */
    public function index(OrderIndexRequest $request): JsonResponse
    {
        $customerId = $request->user()->id;
        $search = $request->validated('q');
        $base = fn () => Order::query()->where('customer_user_id', $customerId)
            ->when(filled($search), fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->where('number', 'like', LikeSearch::contains($search))
                ->orWhereHas('subOrders', fn (Builder $subOrder) => $subOrder->where('number', 'like', LikeSearch::contains($search))
                    ->orWhereHas('items', fn (Builder $item) => $item->where('title', 'like', LikeSearch::contains($search)))
                    ->orWhereHas('vendor', fn (Builder $vendor) => $vendor->where('store_name', 'like', LikeSearch::contains($search))))));

        $orders = $request->tab()->apply($base())
            ->with(['subOrders.vendor', 'subOrders.items.listing.variant.product.media', 'subOrders.deliveryAssignments', 'payments'])
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->paginate((int) ($request->validated('per_page') ?? 20));

        $counts = collect(OrderListTab::cases())->mapWithKeys(fn (OrderListTab $tab): array => [$tab->value => $tab->apply($base())->count()])->all();

        return ApiResponse::success(OrderResource::collection($orders->items()), [
            'total' => $orders->total(),
            'page' => $orders->currentPage(),
            'per_page' => $orders->perPage(),
            'last_page' => $orders->lastPage(),
            'tab' => $request->tab()->value,
            'counts' => $counts,
        ]);
    }

    /**
     * QA-041: the reasons a customer may give when cancelling a shipment.
     */
    public function cancelReasons(): JsonResponse
    {
        return ApiResponse::success(array_map(fn (OrderCancelReason $reason): array => [
            'code' => $reason->value,
            'label' => Label::of($reason),
            'needs_note' => $reason === OrderCancelReason::Other,
        ], OrderCancelReason::cases()));
    }

    /**
     * G-A6: a printable HTML tax invoice (the PDF is `invoice/pdf`, D16).
     * 409 until it is paid.
     */
    public function invoice(Request $request, string $number, CountryContext $countryContext): Response|JsonResponse
    {
        $order = $this->customerOrder($request, $number);
        $resource = (new OrderResource($order))->resolve($request);

        if (! $resource['invoice_available']) {
            return ApiResponse::error('invoice_not_ready', __('The invoice is ready once the order is paid.'), 409);
        }

        return $this->invoiceView($order, $request->user(), $countryContext);
    }

    /**
     * D16: the same tax invoice as a PDF download (dompdf). 409 until it is paid.
     */
    public function invoicePdf(Request $request, string $number, CountryContext $countryContext, InvoicePdf $pdf): Response|JsonResponse
    {
        $order = $this->customerOrder($request, $number);
        $resource = (new OrderResource($order))->resolve($request);

        if (! $resource['invoice_available']) {
            return ApiResponse::error('invoice_not_ready', __('The invoice is ready once the order is paid.'), 409);
        }

        return $pdf->download($pdf->forOrder($order, $countryContext->current(), $request->user()), $order->number);
    }

    /**
     * Wave 5b apps: a signed link (30 minutes) to the same invoice, so the app
     * can open it in the system browser, which has no bearer token.
     */
    public function invoiceLink(Request $request, string $number): JsonResponse
    {
        $order = $this->customerOrder($request, $number);
        $resource = (new OrderResource($order))->resolve($request);

        if (! $resource['invoice_available']) {
            return ApiResponse::error('invoice_not_ready', __('The invoice is ready once the order is paid.'), 409);
        }

        $expires = now()->addMinutes(30);

        $parameters = ['market' => $request->attributes->get('market'), 'number' => $order->number];

        return ApiResponse::success([
            'url' => URL::temporarySignedRoute('api.v1.orders.invoice.signed', $expires, $parameters),
            'pdf_url' => URL::temporarySignedRoute('api.v1.orders.invoice.signed', $expires, $parameters + ['format' => 'pdf']),
            'expires_at' => $expires->toIso8601String(),
        ]);
    }

    /** The invoice behind a signed link (`signed` middleware, no token); `format=pdf` (signed too) for the PDF. */
    public function signedInvoice(Request $request, string $number, CountryContext $countryContext, InvoicePdf $pdf): Response|JsonResponse
    {
        $order = Order::query()->where('number', $number)->with('subOrders.vendor', 'subOrders.items', 'payments')->firstOrFail();
        $resource = (new OrderResource($order))->resolve($request);

        if (! $resource['invoice_available']) {
            return ApiResponse::error('invoice_not_ready', __('The invoice is ready once the order is paid.'), 409);
        }

        $customer = User::query()->findOrFail($order->customer_user_id);

        if ($request->query('format') === 'pdf') {
            return $pdf->download($pdf->forOrder($order, $countryContext->current(), $customer), $order->number);
        }

        return $this->invoiceView($order, $customer, $countryContext);
    }

    private function invoiceView(Order $order, User $customer, CountryContext $countryContext): Response
    {
        return response()->view('invoices.order', [
            'order' => $order,
            'country' => $countryContext->current(),
            'customer' => $customer,
        ])->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function show(Request $request, string $number): JsonResponse
    {
        $order = $this->customerOrder($request, $number);

        return ApiResponse::success(new OrderResource($order));
    }

    /**
     * Cancel one shipment before it ships; its stock goes back on sale.
     */
    public function cancelShipment(CancelShipmentRequest $request, string $number, string $shipmentNumber, SubOrderStateMachine $machine): JsonResponse
    {
        $order = $this->customerOrder($request, $number);
        $subOrder = $order->subOrders->firstWhere('number', $shipmentNumber) ?? abort(404);

        try {
            $machine->transition($subOrder, SubOrderStatus::Cancelled, ActorType::Customer, $request->user()->id, $request->historyReason());
        } catch (SubOrderTransitionRefused $refused) {
            return ApiResponse::error('cannot_cancel', $refused->getMessage(), 422);
        }

        return ApiResponse::success(new OrderResource($this->customerOrder($request, $number)));
    }

    private function customerOrder(Request $request, string $number): Order
    {
        return Order::query()
            ->where('number', $number)
            ->where('customer_user_id', $request->user()->id)
            ->with('subOrders.vendor', 'subOrders.items', 'payments')
            ->firstOrFail();
    }
}
