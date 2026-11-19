<?php

namespace App\Domains\Fulfilment\Support;

use App\Domains\Campaigns\Support\CampaignLedger;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Models\VendorBalanceItem;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Fulfilment\Enums\RemovalStatus;
use App\Domains\Fulfilment\Enums\RemovalType;
use App\Domains\Fulfilment\Models\RemovalLine;
use App\Domains\Fulfilment\Models\RemovalOrder;
use App\Domains\Fulfilment\Models\Warehouse;
use App\Domains\Listings\Models\Listing;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Panels\Listeners\NotifySellerOfStateChange;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Money\MoneyFactory;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * FBN removal orders: a seller asks for warehouse units back (to an
 * address) or disposed of. Sellable units are held off sale at the request;
 * the warehouse picks them (processing — units leave the stock) and
 * completes it (shipped / disposed), when the removal fee
 * ({@see FbnFees::removal()}) is charged on the store's next statement
 * (Dr vendor / Cr fee revenue, statement line `fbn_removal`). A cancelled
 * or rejected request puts the held units back on sale.
 */
class RemovalOrders
{
    public function __construct(
        private StateMachine $machine,
        private WarehouseStockService $stock,
        private FbnFees $fees,
        private CampaignLedger $ledger,
        private MoneyFactory $money,
        private CountryContext $countryContext,
    ) {}

    /**
     * @param  array<int, array{listing_id: int, quantity: int, source?: string|null}>  $lines
     * @param  array<string, string>|null  $address
     */
    public function request(Vendor $vendor, int $warehouseId, RemovalType $type, array $lines, ?array $address, ?string $reason, int $actorUserId): RemovalOrder
    {
        Warehouse::query()->findOrFail($warehouseId);
        $lines = array_values(array_filter($lines, fn (array $line): bool => (int) $line['quantity'] > 0));

        if ($lines === []) {
            throw new TransitionRefused(__('Add at least one product to remove.'));
        }

        if ($type === RemovalType::ReturnToSeller && blank($address['street'] ?? null)) {
            throw new TransitionRefused(__('Enter the address to send the units to.'));
        }

        return DB::connection($vendor->getConnectionName())->transaction(function () use ($vendor, $warehouseId, $type, $lines, $address, $reason, $actorUserId): RemovalOrder {
            $order = RemovalOrder::query()->create([
                'vendor_id' => $vendor->id, 'warehouse_id' => $warehouseId, 'type' => $type, 'reason' => $reason,
                'address' => $type === RemovalType::ReturnToSeller ? $address : null, 'currency' => $this->countryContext->current()->currency,
            ]);
            $order->forceFill(['number' => sprintf('FBN-RM-%s-%06d', strtoupper($this->countryContext->current()->iso2), $order->id)])->save();

            foreach ($lines as $line) {
                $listing = Listing::query()->where('vendor_id', $vendor->id)->with('variant')->findOrFail((int) $line['listing_id']);
                $source = ($line['source'] ?? null) === RemovalLine::SOURCE_DAMAGED ? RemovalLine::SOURCE_DAMAGED : RemovalLine::SOURCE_SELLABLE;
                $order->lines()->create(['listing_id' => $listing->id, 'source' => $source, 'quantity' => (int) $line['quantity']]);
                $this->stock->holdForRemoval($warehouseId, $listing, $source, (int) $line['quantity'], $order->id, $actorUserId);
            }

            $order->forceFill(['fee' => $this->money->toStorage($this->quote($order))])->save();
            $this->machine->created($order, ActorType::Vendor, $actorUserId);

            return $order;
        });
    }

    public function quote(RemovalOrder $order): Money
    {
        $lines = $order->lines()->with('listing.variant')->get()->map(fn (RemovalLine $line): array => ['listing' => $line->listing, 'quantity' => $line->quantity]);

        return $this->fees->removal($order->type, $lines, $order->currency);
    }

    public function process(RemovalOrder $order, int $actorUserId): RemovalOrder
    {
        return DB::connection($order->getConnectionName())->transaction(function () use ($order, $actorUserId): RemovalOrder {
            foreach ($order->lines()->with('listing.variant')->get() as $line) {
                $this->stock->pickForRemoval($order->warehouse_id, $line->listing, $line->source, $line->quantity, $order->id, $actorUserId);
            }

            return $this->machine->move($order, RemovalStatus::Processing, ActorType::Admin, $actorUserId, null, ['processed_at' => now()]);
        });
    }

    public function complete(RemovalOrder $order, int $actorUserId): RemovalOrder
    {
        return DB::connection($order->getConnectionName())->transaction(function () use ($order, $actorUserId): RemovalOrder {
            $fee = $this->quote($order);
            $moved = $this->machine->move($order, RemovalStatus::Completed, ActorType::Admin, $actorUserId, null, ['completed_at' => now(), 'fee' => $this->money->toStorage($fee)]);
            $this->ledger->chargeVendor($order->vendor_id, $fee, VendorBalanceItem::TYPE_FBN_REMOVAL, $order, "fbn-removal:{$order->id}:charge",
                "FBN removal {$order->number}", AccountRef::platform(LedgerAccountCode::FeeRevenue));
            NotifySellerOfStateChange::notifyVendor($order->vendor_id, 'fbn.removal_completed', __('Removal :number completed', ['number' => $order->number]), null, '/inventory/removals');

            return $moved;
        });
    }

    public function cancel(RemovalOrder $order, ActorType $actorType, int $actorUserId, ?string $reason = null): RemovalOrder
    {
        $to = $actorType === ActorType::Vendor ? RemovalStatus::Cancelled : RemovalStatus::Rejected;

        return DB::connection($order->getConnectionName())->transaction(function () use ($order, $to, $actorType, $actorUserId, $reason): RemovalOrder {
            $moved = $this->machine->move($order, $to, $actorType, $actorUserId, $reason);

            foreach ($order->lines()->with('listing.variant')->get() as $line) {
                $this->stock->releaseRemovalHold($order->warehouse_id, $line->listing, $line->source, $line->quantity, $order->id, $actorUserId);
            }

            return $moved;
        });
    }
}
