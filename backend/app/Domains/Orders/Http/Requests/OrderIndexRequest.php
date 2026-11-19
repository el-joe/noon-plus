<?php

namespace App\Domains\Orders\Http\Requests;

use App\Domains\Orders\Enums\OrderListTab;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET orders` (QC-026): tab, search (order / shipment number, item title,
 * seller) and paging.
 */
class OrderIndexRequest extends FormRequest
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
            'tab' => ['nullable', Rule::enum(OrderListTab::class)],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function tab(): OrderListTab
    {
        return OrderListTab::tryFrom((string) $this->validated('tab')) ?? OrderListTab::All;
    }
}
