<?php

namespace App\Domains\Classifieds\Exceptions;

use App\Domains\Contracts\Models\ContractVersion;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * FR-CLS-4: a customer purchase contract applies and the customer has not
 * accepted its current version (guests must sign in to accept). The API
 * answers 409 `customer_contract_required` with the version to accept.
 */
class CustomerContractRequired extends RuntimeException
{
    public function __construct(public readonly ContractVersion $version, public readonly string $market)
    {
        parent::__construct(__('Accept the purchase contract for this category first.'));
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'customer_contract_required',
                'message' => $this->getMessage(),
                'fields' => (object) [],
                'contracts' => [[
                    'version_id' => $this->version->id,
                    'kind' => $this->version->template->kind->value,
                    'title' => $this->version->template->name,
                    'version' => $this->version->version,
                    'url' => route('api.v1.contracts.show', ['market' => $this->market, 'version' => $this->version->id]),
                ]],
            ],
        ], 409);
    }
}
