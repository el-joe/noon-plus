<?php

namespace App\Domains\Support\Http\Requests;

use App\Domains\Support\Enums\TicketCategory;
use App\Domains\Support\Enums\TicketPriority;
use App\Domains\Support\Support\TicketAttachments;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * New support ticket (QC-007): a category, a subject, the first message,
 * optionally a priority (low / normal / high), the order or shipment it is
 * about and up to `nawy.support.max_attachments` files.
 */
class StoreTicketRequest extends FormRequest
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
            'category' => ['required', Rule::enum(TicketCategory::class)],
            'subject' => ['required', 'string', 'min:3', 'max:160'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'priority' => ['nullable', Rule::in(TicketPriority::requesterValues())],
            'order' => ['nullable', 'string', 'max:40'],
            'shipment' => ['nullable', 'string', 'max:40'],
            'attachments' => ['nullable', 'array', 'max:'.(int) config('nawy.support.max_attachments')],
            'attachments.*' => TicketAttachments::rules(),
        ];
    }
}
