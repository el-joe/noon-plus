<?php

namespace App\Domains\Returns\Http\Requests;

use App\Domains\Refunds\Enums\RefundDestination;
use App\Domains\Returns\Enums\ReturnMethod;
use App\Domains\Returns\Enums\ReturnReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Return wizard (web 104:5646…, app 99:6051/99:6097): the shipment, the
 * items and quantities, a reason, up to 5 photos, pickup or drop-off and
 * where the refund goes.
 */
class StoreReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'shipment' => ['required', 'string', 'max:40'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.order_item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['required', Rule::enum(ReturnReason::class)],
            'comment' => ['nullable', 'string', 'max:1000'],
            'method' => ['required', Rule::enum(ReturnMethod::class)],
            'refund_destination' => ['required', Rule::enum(RefundDestination::class)],
            'photos' => ['nullable', 'array', 'max:5'],
            'photos.*' => ['image', 'max:5120'],
        ];
    }
}
