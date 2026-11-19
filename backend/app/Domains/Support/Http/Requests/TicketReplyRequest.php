<?php

namespace App\Domains\Support\Http\Requests;

use App\Domains\Support\Support\TicketAttachments;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A reply in a ticket thread, with optional attachments. Support staff
 * may also send it as an internal note or ask the requester for
 * information (`wait`).
 */
class TicketReplyRequest extends FormRequest
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
            'body' => ['required', 'string', 'min:2', 'max:5000'],
            'internal' => ['sometimes', 'boolean'],
            'wait' => ['sometimes', 'boolean'],
            'attachments' => ['nullable', 'array', 'max:'.(int) config('nawy.support.max_attachments')],
            'attachments.*' => TicketAttachments::rules(),
        ];
    }
}
