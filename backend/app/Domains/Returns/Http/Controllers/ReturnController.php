<?php

namespace App\Domains\Returns\Http\Controllers;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Refunds\Enums\RefundDestination;
use App\Domains\Returns\Enums\ReturnMethod;
use App\Domains\Returns\Enums\ReturnReason;
use App\Domains\Returns\Http\Requests\StoreReturnRequest;
use App\Domains\Returns\Http\Resources\ReturnResource;
use App\Domains\Returns\Models\ReturnRequest;
use App\Domains\Returns\Support\ReturnPhotos;
use App\Domains\Returns\Support\ReturnRequests;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Customer returns (account returns pages, SRS §7.4): create from a
 * delivered shipment, list, detail with timeline, cancel before pickup.
 */
class ReturnController extends Controller
{
    public function __construct(private ReturnRequests $returns) {}

    public function index(Request $request): JsonResponse
    {
        $returns = ReturnRequest::query()->where('customer_user_id', $request->user()->id)
            ->with(['subOrder', 'items.orderItem', 'refund', 'transitions'])->latest('id')->paginate(20);

        return ApiResponse::success(ReturnResource::collection($returns->items()), ['total' => $returns->total(), 'page' => $returns->currentPage()]);
    }

    public function store(StoreReturnRequest $request, CountryContext $countryContext): JsonResponse
    {
        $subOrder = SubOrder::query()
            ->where('number', $request->validated('shipment'))
            ->whereHas('order', fn ($query) => $query->where('customer_user_id', $request->user()->id))
            ->firstOrFail();

        $return = $this->returns->create(
            $subOrder,
            $request->user()->id,
            $request->validated('items'),
            ReturnReason::from($request->validated('reason')),
            ReturnMethod::from($request->validated('method')),
            RefundDestination::from($request->validated('refund_destination')),
            $request->validated('comment'),
        );

        /*
        | QC-049: evidence is stored only once the return is accepted, on the
        | private disk; it is served through signed links (ReturnPhotos).
        */
        $photos = array_map(
            fn ($photo): string => $photo->store('returns/'.$countryContext->current()->code.'/'.$return->number, ReturnPhotos::DISK),
            $request->file('photos', []),
        );

        if ($photos !== []) {
            $return->forceFill(['photos' => $photos])->save();
        }

        return ApiResponse::success(new ReturnResource($this->load($return)), status: 201);
    }

    /**
     * One evidence photo through a signed, expiring link (QC-049).
     */
    public function photo(int $return, int $index, ReturnPhotos $photos): StreamedResponse
    {
        return $photos->stream(ReturnRequest::query()->findOrFail($return), $index);
    }

    public function show(Request $request, string $number): JsonResponse
    {
        return ApiResponse::success(new ReturnResource($this->customerReturn($request, $number)));
    }

    public function cancel(Request $request, string $number): JsonResponse
    {
        $return = $this->returns->cancel($this->customerReturn($request, $number), $request->user()->id);

        return ApiResponse::success(new ReturnResource($this->load($return)));
    }

    private function customerReturn(Request $request, string $number): ReturnRequest
    {
        return $this->load(ReturnRequest::query()->where('customer_user_id', $request->user()->id)->where('number', $number)->firstOrFail());
    }

    private function load(ReturnRequest $return): ReturnRequest
    {
        return $return->load(['subOrder', 'items.orderItem', 'refund', 'transitions']);
    }
}
