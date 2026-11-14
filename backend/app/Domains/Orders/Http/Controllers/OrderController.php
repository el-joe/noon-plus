<?php

namespace App\Domains\Orders\Http\Controllers;

use App\Domains\Cart\Exceptions\CartRejected;
use App\Domains\Cart\Support\CartService;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Exceptions\SubOrderTransitionRefused;
use App\Domains\Orders\Http\Requests\PlaceOrderRequest;
use App\Domains\Orders\Http\Resources\OrderResource;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Support\PlaceOrder;
use App\Domains\Orders\Support\SubOrderStateMachine;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    /**
     * Place the order. A new account must have a verified email (ADR 5).
     */
    public function store(PlaceOrderRequest $request, CartService $carts, PlaceOrder $placeOrder): JsonResponse
    {
        $user = $request->user();

        if ($user->email_verified_at === null) {
            return ApiResponse::error('email_not_verified', __('Verify your email before placing an order.'), 403);
        }

        try {
            $order = $placeOrder->handle(
                $carts->forCustomer($user->id),
                $user->id,
                $request->validated('payment_method'),
                $request->validated('address'),
            );
        } catch (CartRejected $rejected) {
            return ApiResponse::error($rejected->errorCode, $rejected->getMessage(), $rejected->status);
        }

        return ApiResponse::success(new OrderResource($order->load('subOrders.vendor', 'subOrders.items')), status: 201);
    }

    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->where('customer_user_id', $request->user()->id)
            ->with('subOrders.vendor', 'subOrders.items')
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->paginate(20);

        return ApiResponse::success(OrderResource::collection($orders->items()), ['total' => $orders->total(), 'page' => $orders->currentPage()]);
    }

    public function show(Request $request, string $number): JsonResponse
    {
        $order = $this->customerOrder($request, $number);

        return ApiResponse::success(new OrderResource($order));
    }

    /**
     * Cancel one shipment before it ships; its stock goes back on sale.
     */
    public function cancelShipment(Request $request, string $number, string $shipmentNumber, SubOrderStateMachine $machine): JsonResponse
    {
        $order = $this->customerOrder($request, $number);
        $subOrder = $order->subOrders->firstWhere('number', $shipmentNumber) ?? abort(404);

        try {
            $machine->transition($subOrder, SubOrderStatus::Cancelled, ActorType::Customer, $request->user()->id, $request->input('reason'));
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
            ->with('subOrders.vendor', 'subOrders.items')
            ->firstOrFail();
    }
}
