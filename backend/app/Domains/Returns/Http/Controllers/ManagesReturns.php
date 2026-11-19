<?php

namespace App\Domains\Returns\Http\Controllers;

use App\Domains\Orders\Enums\ActorType;
use App\Domains\Returns\Enums\InspectionOutcome;
use App\Domains\Returns\Enums\ReturnStatus;
use App\Domains\Returns\Models\ReturnRequest;
use App\Domains\Returns\Support\ReturnRequests;
use App\Shared\Money\MoneyFormatter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Review and inspection actions shared by the admin and Seller Center
 * returns pages; each controller scopes the return and names its actor.
 */
trait ManagesReturns
{
    abstract protected function findReturn(int $id): ReturnRequest;

    abstract protected function actorType(): ActorType;

    public function approve(Request $request, int $return, ReturnRequests $returns): RedirectResponse
    {
        $returns->approve($this->findReturn($return), $this->actorType(), $request->user()->id);

        return back()->with('status', __('Return approved.'));
    }

    public function reject(Request $request, int $return, ReturnRequests $returns): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']])['reason'];
        $returns->reject($this->findReturn($return), $this->actorType(), $request->user()->id, $reason);

        return back()->with('status', __('Return rejected.'));
    }

    public function advance(Request $request, int $return, ReturnRequests $returns): RedirectResponse
    {
        $to = ReturnStatus::from($request->validate(['to' => ['required', Rule::enum(ReturnStatus::class)]])['to']);
        $returns->advance($this->findReturn($return), $to, $this->actorType(), $request->user()->id);

        return back()->with('status', __('Return is now :status.', ['status' => str_replace('_', ' ', $to->value)]));
    }

    public function inspect(Request $request, int $return, ReturnRequests $returns): RedirectResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array'],
            'items.*.outcome' => ['required', Rule::enum(InspectionOutcome::class)],
            'items.*.restock' => ['nullable', 'boolean'],
            'items.*.refund_amount' => ['nullable', 'regex:/^\d{1,13}(\.\d{1,3})?$/'],
        ]);
        $results = collect($data['items'])->mapWithKeys(fn (array $item, int|string $id): array => [(int) $id => [
            'outcome' => $item['outcome'],
            'restock' => (bool) ($item['restock'] ?? false),
            'refund_amount' => $item['refund_amount'] ?? null,
        ]])->all();
        $result = $returns->inspect($this->findReturn($return), $results, $this->actorType(), $request->user()->id);

        // Browser test BT-VEN: the amount reads "AED 80.00", not the raw "80.0000" column.
        $amount = app(MoneyFormatter::class)->format((string) $result->refund_total, (string) $result->order()->value('currency'));

        return back()->with('status', __('Inspection saved. Refund :amount.', ['amount' => $amount]));
    }
}
