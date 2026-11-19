<?php

namespace App\Domains\Fulfilment\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Fulfilment\Enums\TransferStatus;
use App\Domains\Fulfilment\Models\StockTransfer;
use App\Domains\Fulfilment\Models\StockTransferLine;
use App\Domains\Fulfilment\Models\Warehouse;
use App\Domains\Listings\Models\Listing;
use App\Domains\Orders\Enums\ActorType;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Support\Facades\DB;

/**
 * Transfers between FBN warehouses (admin): requested → in_transit (units
 * leave the source and are off sale) → received (counted units go on the
 * destination shelf and back on sale; missing units stay lost); cancelled
 * before dispatch.
 */
class StockTransfers
{
    public function __construct(private StateMachine $machine, private WarehouseStockService $stock, private CountryContext $countryContext) {}

    /**
     * @param  array<int, array{listing_id: int, quantity: int}>  $lines
     */
    public function request(int $fromId, int $toId, array $lines, ?string $note, int $actorUserId): StockTransfer
    {
        if ($fromId === $toId) {
            throw new TransitionRefused(__('Choose two different warehouses.'));
        }

        Warehouse::query()->active()->findOrFail($fromId);
        Warehouse::query()->active()->findOrFail($toId);
        $lines = array_values(array_filter($lines, fn (array $line): bool => (int) $line['quantity'] > 0));

        if ($lines === []) {
            throw new TransitionRefused(__('Add at least one product to transfer.'));
        }

        return DB::connection(Warehouse::query()->getConnection()->getName())->transaction(function () use ($fromId, $toId, $lines, $note, $actorUserId): StockTransfer {
            $transfer = StockTransfer::query()->create(['from_warehouse_id' => $fromId, 'to_warehouse_id' => $toId, 'note' => $note]);
            $transfer->forceFill(['number' => sprintf('FBN-TR-%s-%06d', strtoupper($this->countryContext->current()->iso2), $transfer->id)])->save();

            foreach ($lines as $line) {
                Listing::query()->where('fulfilment', 'fbn')->findOrFail((int) $line['listing_id']);
                $transfer->lines()->create(['listing_id' => (int) $line['listing_id'], 'quantity' => (int) $line['quantity']]);
            }

            $this->machine->created($transfer, ActorType::Admin, $actorUserId);

            return $transfer;
        });
    }

    public function dispatch(StockTransfer $transfer, int $actorUserId): StockTransfer
    {
        return DB::connection($transfer->getConnectionName())->transaction(function () use ($transfer, $actorUserId): StockTransfer {
            foreach ($transfer->lines()->with('listing.variant')->get() as $line) {
                $this->stock->transferOut($transfer->from_warehouse_id, $line->listing, $line->quantity, $transfer->id, $actorUserId);
            }

            return $this->machine->move($transfer, TransferStatus::InTransit, ActorType::Admin, $actorUserId, null, ['dispatched_at' => now()]);
        });
    }

    /**
     * @param  array<int, int>  $received  line id => units counted (default: all)
     */
    public function receive(StockTransfer $transfer, array $received, int $actorUserId): StockTransfer
    {
        return DB::connection($transfer->getConnectionName())->transaction(function () use ($transfer, $received, $actorUserId): StockTransfer {
            $lost = 0;

            foreach ($transfer->lines()->with('listing.variant')->get() as $line) {
                /** @var StockTransferLine $line */
                $count = min($line->quantity, max(0, (int) ($received[$line->id] ?? $line->quantity)));

                if ($count > 0) {
                    $this->stock->transferIn($transfer->to_warehouse_id, $line->listing, $count, $transfer->id, $actorUserId);
                }

                $line->forceFill(['quantity_received' => $count])->save();
                $lost += $line->quantity - $count;
            }

            return $this->machine->move($transfer, TransferStatus::Received, ActorType::Admin, $actorUserId, $lost > 0 ? "{$lost} units missing" : null, ['received_at' => now()]);
        });
    }

    public function cancel(StockTransfer $transfer, int $actorUserId): StockTransfer
    {
        return $this->machine->move($transfer, TransferStatus::Cancelled, ActorType::Admin, $actorUserId);
    }
}
