<?php

namespace App\Domains\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Admin → Finance → Ledger → Journal entries filters (also used by the CSV
 * export): posting date range (admin time zone), account and reference type.
 */
class LedgerEntriesRequest extends FormRequest
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
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'account' => ['nullable', 'integer', 'min:1'],
            'reference_type' => ['nullable', 'string', 'max:32', 'regex:/^[a-z0-9_]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'from' => __('from date'),
            'to' => __('to date'),
            'account' => __('account'),
            'reference_type' => __('reference type'),
        ];
    }
}
