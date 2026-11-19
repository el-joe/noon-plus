<?php

namespace App\Domains\Orders\Http\Requests;

use App\Domains\Orders\Enums\OrderCancelReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Customer shipment cancel (QA-041): the reason, when sent, is a known code
 * (`GET orders/cancel-reasons`); `other` needs a note.
 */
class CancelShipmentRequest extends FormRequest
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
            'reason' => ['nullable', Rule::enum(OrderCancelReason::class)],
            'note' => ['nullable', 'required_if:reason,other', 'string', 'max:255'],
        ];
    }

    /**
     * The history line: the reason, plus the note when given.
     */
    public function historyReason(): ?string
    {
        $reason = $this->validated('reason');
        $note = $this->validated('note');

        return $reason === null ? null : $reason.(filled($note) ? ': '.$note : '');
    }
}
