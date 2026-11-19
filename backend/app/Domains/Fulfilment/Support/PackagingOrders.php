<?php

namespace App\Domains\Fulfilment\Support;

use App\Domains\Campaigns\Support\CampaignLedger;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Models\VendorBalanceItem;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Finance\Support\Posting;
use App\Domains\Fulfilment\Enums\PackagingOrderStatus;
use App\Domains\Fulfilment\Models\PackagingOrder;
use App\Domains\Fulfilment\Models\PackagingSupply;
use App\Domains\Identity\Enums\AdminPermission;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Panels\Listeners\NotifySellerOfStateChange;
use App\Domains\Panels\Support\AdminAlerts;
use App\Domains\Settings\SettingsService;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Money\MoneyFactory;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Packaging orders (lifecycle catalogue §6.3, SRS §4.1.3). A store orders
 * supplies from the country's packaging catalogue: unit costs are
 * snapshotted, the delivery fee (setting `packaging.delivery_fee`, country
 * override) is charged in full — never subsidised — and the total is
 * deducted from the store's next payout at once (Dr vendor spendable / Cr
 * fee revenue for the supplies + shipping revenue for delivery, statement
 * line `packaging`). Admin approves (→ shipped with carrier and tracking →
 * delivered) or rejects, which gives the whole charge back (statement line
 * `packaging_refund`). The store is told at each step.
 */
class PackagingOrders
{
    public const DELIVERY_FEE_SETTING = 'packaging.delivery_fee';

    public function __construct(
        private StateMachine $machine,
        private CampaignLedger $ledger,
        private MoneyFactory $money,
        private SettingsService $settings,
        private CountryContext $countryContext,
        private AdminAlerts $alerts,
    ) {}

    public function deliveryFee(): Money
    {
        $country = $this->countryContext->current();

        return $this->money->of((string) $this->settings->get(self::DELIVERY_FEE_SETTING, '0', $country), $country->currency);
    }

    /**
     * @param  array<int, int>  $quantities  packaging_supply_id => quantity (0 = not ordered)
     * @param  array<string, string|null>  $address
     */
    public function place(Vendor $vendor, int $userId, array $quantities, array $address, ?string $notes = null): PackagingOrder
    {
        $quantities = array_filter(array_map('intval', $quantities), fn (int $quantity): bool => $quantity > 0);

        if ($quantities === []) {
            throw new TransitionRefused(__('Choose at least one packaging item.'));
        }

        $currency = $this->countryContext->current()->currency;
        $supplies = PackagingSupply::query()->where('is_active', true)->whereKey(array_keys($quantities))->get()->keyBy('id');

        if ($supplies->count() !== count($quantities)) {
            throw new TransitionRefused(__('One of the packaging items is no longer available.'));
        }

        $order = DB::connection($vendor->getConnectionName())->transaction(function () use ($vendor, $userId, $quantities, $address, $notes, $currency, $supplies): PackagingOrder {
            $itemsTotal = $this->money->zero($currency);
            $lines = [];

            foreach ($quantities as $supplyId => $quantity) {
                $supply = $supplies[$supplyId];

                if ($quantity < $supply->min_quantity || ($supply->max_quantity !== null && $quantity > $supply->max_quantity)) {
                    throw new TransitionRefused(__(':item can be ordered in quantities of :min to :max.', ['item' => $supply->name(), 'min' => $supply->min_quantity, 'max' => $supply->max_quantity ?? '∞']));
                }

                $unit = $this->money->of((string) $supply->unit_cost, $currency);
                $line = $unit->multipliedBy($quantity);
                $itemsTotal = $itemsTotal->plus($line);
                $lines[] = [
                    'packaging_supply_id' => $supply->id, 'sku' => $supply->sku, 'name_en' => $supply->name_en, 'name_ar' => $supply->name_ar,
                    'unit_cost' => $this->money->toStorage($unit), 'quantity' => $quantity, 'line_total' => $this->money->toStorage($line),
                ];
            }

            $deliveryFee = $this->deliveryFee();
            $total = $itemsTotal->plus($deliveryFee);
            $order = PackagingOrder::query()->create([
                'vendor_id' => $vendor->id,
                'ordered_by_user_id' => $userId,
                'status' => PackagingOrderStatus::Pending,
                'currency' => $currency,
                'items_total' => $this->money->toStorage($itemsTotal),
                'delivery_fee' => $this->money->toStorage($deliveryFee),
                'total' => $this->money->toStorage($total),
                'delivery_address' => $address,
                'notes' => $notes,
            ]);
            $order->forceFill(['number' => sprintf('PKG-%s-%06d', strtoupper($this->countryContext->current()->iso2), $order->id)])->save();
            $order->items()->createMany($lines);
            $this->machine->created($order, ActorType::Vendor, $userId);

            $this->ledger->post("packaging:{$order->id}:charge", VendorBalanceItem::TYPE_PACKAGING, (new Posting)
                ->debit(AccountRef::vendor($vendor->id), $total)
                ->credit(AccountRef::platform(LedgerAccountCode::FeeRevenue), $itemsTotal)
                ->credit(AccountRef::platform(LedgerAccountCode::ShippingRevenue), $deliveryFee), $order, "Packaging order {$order->number}");
            $this->ledger->balanceItem($vendor->id, VendorBalanceItem::TYPE_PACKAGING, $order, "Packaging order {$order->number}", $total->negated());

            return $order;
        });

        $this->alerts->notify(AdminPermission::PackagingManage, 'packaging.ordered', __('Packaging order :number', ['number' => $order->number]), $vendor->store_name, '/packaging/orders/'.$order->id);

        return $order;
    }

    public function approve(PackagingOrder $order, int $adminUserId): PackagingOrder
    {
        $order = $this->machine->move($order, PackagingOrderStatus::Approved, ActorType::Admin, $adminUserId, null, ['approved_at' => now()]);
        $this->tell($order, 'packaging.approved', __('Packaging order :number approved', ['number' => $order->number]), __('We are preparing your packaging supplies.'));

        return $order;
    }

    public function reject(PackagingOrder $order, int $adminUserId, string $reason): PackagingOrder
    {
        $order = DB::connection($order->getConnectionName())->transaction(function () use ($order, $adminUserId, $reason): PackagingOrder {
            $order = $this->machine->move($order, PackagingOrderStatus::Rejected, ActorType::Admin, $adminUserId, $reason, ['rejection_reason' => mb_substr($reason, 0, 255)]);
            $total = $this->money->of((string) $order->total, $order->currency);
            $items = $this->money->of((string) $order->items_total, $order->currency);
            $delivery = $this->money->of((string) $order->delivery_fee, $order->currency);

            if ($total->isPositive()) {
                $this->ledger->post("packaging:{$order->id}:refund", VendorBalanceItem::TYPE_PACKAGING_REFUND, (new Posting)
                    ->debit(AccountRef::platform(LedgerAccountCode::FeeRevenue), $items)
                    ->debit(AccountRef::platform(LedgerAccountCode::ShippingRevenue), $delivery)
                    ->credit(AccountRef::vendor($order->vendor_id), $total), $order, "Packaging order {$order->number} rejected");
                $this->ledger->balanceItem($order->vendor_id, VendorBalanceItem::TYPE_PACKAGING_REFUND, $order, "Packaging order {$order->number} rejected", $total);
            }

            return $order;
        });
        $this->tell($order, 'packaging.rejected', __('Packaging order :number rejected', ['number' => $order->number]), $reason);

        return $order;
    }

    public function ship(PackagingOrder $order, int $adminUserId, ?string $carrier, ?string $tracking): PackagingOrder
    {
        $order = $this->machine->move($order, PackagingOrderStatus::Shipped, ActorType::Admin, $adminUserId, null, ['shipped_at' => now(), 'carrier_name' => $carrier, 'tracking_number' => $tracking]);
        $this->tell($order, 'packaging.shipped', __('Packaging order :number shipped', ['number' => $order->number]), $tracking ? __('Tracking number: :tracking', ['tracking' => $tracking]) : null);

        return $order;
    }

    public function deliver(PackagingOrder $order, int $adminUserId): PackagingOrder
    {
        $order = $this->machine->move($order, PackagingOrderStatus::Delivered, ActorType::Admin, $adminUserId, null, ['delivered_at' => now()]);
        $this->tell($order, 'packaging.delivered', __('Packaging order :number delivered', ['number' => $order->number]), null);

        return $order;
    }

    private function tell(PackagingOrder $order, string $type, string $title, ?string $body): void
    {
        NotifySellerOfStateChange::notifyVendor($order->vendor_id, $type, $title, $body, '/packaging');
    }
}
