<?php

namespace App\Domains\Disputes\Http\Controllers;

use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Disputes\Enums\DisputeReason;
use App\Domains\Disputes\Models\Dispute;
use App\Domains\Disputes\Support\Disputes;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Models\SubOrder;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Customer disputes (account disputes page, SRS §7.6): open on a shipment,
 * list, read the thread and write in it.
 */
class DisputeController extends Controller
{
    public function __construct(private Disputes $disputes) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(Dispute::query()->where('customer_user_id', $request->user()->id)->with('subOrder')->latest('id')->limit(50)->get()
            ->map(fn (Dispute $dispute): array => $this->present($dispute, false))->all());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'shipment' => ['required', 'string', 'max:40'],
            'reason' => ['required', Rule::enum(DisputeReason::class)],
            'message' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $subOrder = SubOrder::query()->where('number', $data['shipment'])
            ->whereHas('order', fn ($query) => $query->where('customer_user_id', $request->user()->id))->firstOrFail();
        $dispute = $this->disputes->open($subOrder, $request->user()->id, DisputeReason::from($data['reason']), $data['message']);

        return ApiResponse::success($this->present($dispute->load('subOrder', 'messages')), status: 201);
    }

    public function show(Request $request, string $number): JsonResponse
    {
        return ApiResponse::success($this->present($this->customerDispute($request, $number)));
    }

    public function message(Request $request, string $number): JsonResponse
    {
        $body = $request->validate(['body' => ['required', 'string', 'max:2000']])['body'];
        $dispute = $this->disputes->message($this->customerDispute($request, $number), ActorType::Customer, $request->user()->id, $body);

        return ApiResponse::success($this->present($dispute->load('subOrder', 'messages')), status: 201);
    }

    private function customerDispute(Request $request, string $number): Dispute
    {
        return Dispute::query()->where('customer_user_id', $request->user()->id)->where('number', $number)->with('subOrder', 'messages')->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Dispute $dispute, bool $withThread = true): array
    {
        return [
            'number' => $dispute->number,
            'shipment' => $dispute->subOrder?->number,
            'status' => $dispute->status->value,
            'reason' => $dispute->reason->value,
            'resolution' => $dispute->resolution?->value,
            'refund' => MoneyResource::make($dispute->refund_amount === null ? null : (string) $dispute->refund_amount),
            'created_at' => $dispute->created_at->toIso8601String(),
        ] + ($withThread ? ['messages' => $dispute->messages->map(fn ($message): array => [
            'from' => $message->author_type->value,
            'body' => $message->body,
            'at' => $message->created_at->toIso8601String(),
        ])->all()] : []);
    }
}
