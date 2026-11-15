<?php

namespace App\Domains\Payments\Http\Requests;

use App\Domains\Payments\Enums\GatewayMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Admin → Payment gateways → Configure. Amounts are decimals in the country
 * currency; currencies are ISO codes separated by commas; credentials are
 * write-only (blank keeps the saved value).
 */
class UpdateGatewayConfigRequest extends FormRequest
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
            'is_enabled' => ['required', 'boolean'],
            'mode' => ['required', Rule::enum(GatewayMode::class)],
            'currencies' => ['required', 'string', 'regex:/^\s*[A-Za-z]{3}(\s*,\s*[A-Za-z]{3})*\s*$/'],
            'min_amount' => ['nullable', 'decimal:0,3', 'min:0'],
            'max_amount' => ['nullable', 'decimal:0,3', 'gt:min_amount'],
            'sort_order' => ['required', 'integer', 'between:0,999'],
            'fee_percent' => ['nullable', 'decimal:0,2', 'between:0,100'],
            'fee_fixed' => ['nullable', 'decimal:0,3', 'min:0'],
            'credentials' => ['nullable', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array{is_enabled: bool, mode: string, currencies: array<int, string>, min_amount: ?string, max_amount: ?string, sort_order: int, fee_rule: ?array<string, string>}
     */
    public function settings(): array
    {
        $percent = $this->validated('fee_percent');
        $fixed = $this->validated('fee_fixed');

        return [
            'is_enabled' => $this->boolean('is_enabled'),
            'mode' => (string) $this->validated('mode'),
            'currencies' => array_values(array_unique(array_map(fn (string $code): string => strtoupper(trim($code)), explode(',', (string) $this->validated('currencies'))))),
            'min_amount' => $this->validated('min_amount'),
            'max_amount' => $this->validated('max_amount'),
            'sort_order' => (int) $this->validated('sort_order'),
            'fee_rule' => $percent === null && $fixed === null ? null : ['percent' => (string) ($percent ?? '0'), 'fixed' => (string) ($fixed ?? '0'), 'payer' => 'platform'],
        ];
    }
}
