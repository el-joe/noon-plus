<?php

namespace App\Domains\Warranty\Http\Controllers;

use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Models\OrderItem;
use App\Domains\Warranty\Models\WarrantyClaim;
use App\Domains\Warranty\Support\WarrantyClaims;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer warranty claims (account warranty page, SRS §7.7): submit for a
 * delivered item, list, read and write in the three-party thread.
 */
class WarrantyClaimController extends Controller
{
    public function __construct(private WarrantyClaims $claims) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(WarrantyClaim::query()->where('customer_user_id', $request->user()->id)->with('orderItem')->latest('id')->limit(50)->get()
            ->map(fn (WarrantyClaim $claim): array => $this->present($claim, false))->all());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['order_item_id' => ['required', 'integer'], 'description' => ['required', 'string', 'min:10', 'max:2000']]);
        $item = OrderItem::query()->whereKey($data['order_item_id'])
            ->whereHas('subOrder.order', fn ($query) => $query->where('customer_user_id', $request->user()->id))->firstOrFail();
        $claim = $this->claims->submit($item, $request->user()->id, $data['description']);

        return ApiResponse::success($this->present($claim->load('orderItem', 'messages')), status: 201);
    }

    public function show(Request $request, string $number): JsonResponse
    {
        return ApiResponse::success($this->present($this->customerClaim($request, $number)));
    }

    public function message(Request $request, string $number): JsonResponse
    {
        $body = $request->validate(['body' => ['required', 'string', 'max:2000']])['body'];
        $claim = $this->claims->message($this->customerClaim($request, $number), ActorType::Customer, $request->user()->id, $body);

        return ApiResponse::success($this->present($claim->load('orderItem', 'messages')), status: 201);
    }

    private function customerClaim(Request $request, string $number): WarrantyClaim
    {
        return WarrantyClaim::query()->where('customer_user_id', $request->user()->id)->where('number', $number)->with('orderItem', 'messages')->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(WarrantyClaim $claim, bool $withThread = true): array
    {
        return [
            'number' => $claim->number,
            'item' => $claim->orderItem?->title,
            'coverage' => $claim->coverage->value,
            'status' => $claim->status->value,
            'outcome' => $claim->outcome?->value,
            'created_at' => $claim->created_at->toIso8601String(),
        ] + ($withThread ? ['messages' => $claim->messages->map(fn ($message): array => [
            'from' => $message->author_type->value,
            'body' => $message->body,
            'at' => $message->created_at->toIso8601String(),
        ])->all()] : []);
    }
}
