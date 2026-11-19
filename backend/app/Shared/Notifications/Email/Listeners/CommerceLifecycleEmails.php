<?php

namespace App\Shared\Notifications\Email\Listeners;

use App\Domains\Countries\Support\BusinessCalendar;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Delivery\Models\Carrier;
use App\Domains\Delivery\Models\DeliveryAgent;
use App\Domains\Delivery\Models\DeliveryAssignment;
use App\Domains\Delivery\Models\Shipment;
use App\Domains\Disputes\Enums\DisputeStatus;
use App\Domains\Disputes\Models\Dispute;
use App\Domains\Disputes\Models\DisputeMessage;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderItem;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Panels\Support\Panel;
use App\Domains\Payments\Enums\PaymentMethod;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Support\PaymentStateMachine;
use App\Domains\Refunds\Enums\RefundDestination;
use App\Domains\Refunds\Enums\RefundStatus;
use App\Domains\Refunds\Models\Refund;
use App\Domains\Returns\Enums\ReturnMethod;
use App\Domains\Returns\Enums\ReturnStatus;
use App\Domains\Returns\Models\ReturnRequest;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use App\Shared\Http\StorefrontLinks;
use App\Shared\Localization\InLocale;
use App\Shared\Localization\Label;
use App\Shared\Notifications\Email\Attachments\OrderInvoiceAttachment;
use App\Shared\Notifications\Email\Emails;
use App\Shared\Notifications\Email\EmailValue;
use App\Shared\StateMachine\StateMoved;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Catalogue emails of the commerce flow (docs/project/email-catalogue.md):
 * lifecycle moves of sub-orders, returns, refunds and disputes arrive as
 * {@see StateMoved}; payments, checkout, return requests, dispute threads
 * and delivery assignments call the public methods directly. Every send is
 * deferred until the surrounding transaction commits and is idempotent per
 * event + entity + recipient ({@see Emails::send()}), so a receipt or an
 * invoice goes out once per order however many moves lead to it.
 */
class CommerceLifecycleEmails
{
    public function __construct(
        private Emails $emails,
        private CountryContext $countryContext,
        private StorefrontLinks $storefront,
    ) {}

    public function handle(StateMoved $event): void
    {
        try {
            match (true) {
                $event->subject instanceof SubOrder => $this->subOrderMoved($event),
                $event->subject instanceof ReturnRequest => $this->returnMoved($event),
                $event->subject instanceof Refund => $this->refundMoved($event),
                $event->subject instanceof Dispute => $this->disputeMoved($event),
                default => null,
            };
        } catch (Throwable $exception) {
            // An email must never break the move that triggered it.
            report($exception);
        }
    }

    /**
     * Every order payment move ({@see PaymentStateMachine}),
     * called inside the move's transaction.
     */
    public function paymentMoved(Payment $payment, PaymentStatus $from, PaymentStatus $to): void
    {
        if ($payment->order_id === null) {
            return;
        }

        try {
            $order = Order::query()->find($payment->order_id);

            if ($order === null) {
                return;
            }

            match ($to) {
                PaymentStatus::Authorized => $this->orderPlaced($order, $payment),
                PaymentStatus::Captured => $this->paymentCaptured($order, $payment),
                PaymentStatus::Failed, PaymentStatus::Expired => $this->paymentFailed($order, $payment),
                PaymentStatus::UnderReview => $this->paymentUnderReview($order, $payment),
                default => null,
            };
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * The receipt to the customer and one new-order email per sub-order to
     * its store: a cash-on-delivery order when it is placed, other methods
     * once the payment is authorised or captured. Once per order.
     */
    public function orderPlaced(Order $order, ?Payment $payment = null): void
    {
        $order->loadMissing('subOrders.items', 'subOrders.vendor');
        $payment ??= $order->payments()->latest('id')->first();
        $customer = $this->customer($order);

        if ($customer !== null) {
            $this->emails->send('order.placed', $customer, [
                'order_number' => $order->number,
                'total' => $this->money($order->grand_total, $order->currency),
                'payment_method' => $this->methodLabel($order->payment_method),
                'shipment_count' => (string) $order->subOrders->count(),
            ], [
                'entity' => $order,
                'url' => $this->orderUrl($order, $customer),
                'blocks' => $this->receipt($order, $payment),
            ]);
        }

        $hours = (int) config('nawy.mail.reminders.seller_unconfirmed_hours', 24);
        $confirmBy = Carbon::parse($order->placed_at ?? now())->addHours($hours);

        foreach ($order->subOrders as $subOrder) {
            // A Fulfilled-by-Nawy parcel is confirmed and shipped by the warehouse, not the store.
            if ($subOrder->fulfilled_by === 'nawy') {
                continue;
            }

            $owner = $subOrder->vendor?->owner_user_id;

            if ($owner === null) {
                continue;
            }

            $this->emails->send('seller.order_new', (int) $owner, [
                'shipment_number' => $subOrder->number,
                'items' => $this->itemList($subOrder->items),
                'total' => $this->money($subOrder->total, $order->currency),
                'confirm_by' => EmailValue::date($confirmBy, time: true),
            ], [
                'entity' => $subOrder,
                'panel' => 'partner',
                'url' => Panel::find('partner')->url('/orders/'.$subOrder->id),
            ]);
        }
    }

    /**
     * A customer asked to return items: confirmation to the customer and a
     * request to the store (respond within two business days).
     */
    public function returnRequested(ReturnRequest $return): void
    {
        try {
            $return->loadMissing('items.orderItem');
            $order = Order::query()->findOrFail($return->order_id);
            $subOrder = SubOrder::query()->findOrFail($return->sub_order_id);
            $customer = User::query()->find($return->customer_user_id);
            $items = $return->items->map(fn ($item): string => $item->orderItem?->title.' × '.$item->quantity)->implode(', ');

            if ($customer !== null) {
                $this->emails->send('return.requested', $customer, [
                    'return_number' => $return->number,
                    'order_number' => $order->number,
                    'items' => $items,
                    'method' => EmailValue::t(Label::key($return->method)),
                ], ['entity' => $return, 'url' => $this->storefrontUrl('account/returns/'.$return->number, $customer)]);
            }

            $owner = Vendor::query()->whereKey($return->vendor_id)->value('owner_user_id');

            if ($owner !== null) {
                $country = $this->countryContext->current();
                $respondBy = app(BusinessCalendar::class)->addBusinessDays($country, now($country->timezone ?: config('app.timezone')), 2);

                $this->emails->send('seller.return_requested', (int) $owner, [
                    'return_number' => $return->number,
                    'shipment_number' => $subOrder->number,
                    'reason' => EmailValue::t(Label::key($return->reason)),
                    'respond_by' => EmailValue::date($respondBy),
                ], ['entity' => $return, 'panel' => 'partner', 'url' => Panel::find('partner')->url('/returns/'.$return->id)]);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * A dispute was opened: confirmation to the customer and an alert to
     * the admins who handle disputes.
     */
    public function disputeOpened(Dispute $dispute, string $message): void
    {
        try {
            $order = Order::query()->findOrFail($dispute->order_id);
            $customer = User::query()->find($dispute->customer_user_id);

            if ($customer !== null) {
                $this->emails->send('dispute.opened', $customer, [
                    'dispute_number' => $dispute->number,
                    'order_number' => $order->number,
                ], ['entity' => $dispute, 'url' => $this->storefrontUrl('account/disputes/'.$dispute->number, $customer)]);
            }

            $this->emails->alertAdmins('admin.dispute_opened', [
                'dispute_number' => $dispute->number,
                'order_number' => $order->number,
                'reason' => [EmailValue::t(Label::key($dispute->reason)), mb_substr($message, 0, 500)],
            ], ['entity' => $dispute, 'url' => Panel::find('admin')->url('/disputes/'.$dispute->id)]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * A seller or admin wrote in the dispute thread: the customer is told.
     */
    public function disputeMessage(Dispute $dispute, DisputeMessage $message): void
    {
        if (! in_array($message->author_type, [ActorType::Vendor, ActorType::Admin], true)) {
            return;
        }

        try {
            $customer = User::query()->find($dispute->customer_user_id);

            if ($customer === null) {
                return;
            }

            $this->emails->send('dispute.updated', $customer, [
                'dispute_number' => $dispute->number,
                'update' => EmailValue::t('New message from :author: :message', [
                    'author' => $message->author_type === ActorType::Vendor ? EmailValue::t('the seller') : EmailValue::t('Nawy'),
                    'message' => mb_substr((string) $message->body, 0, 1000),
                ]),
            ], ['entity' => $dispute, 'key' => 'message:'.$message->id, 'url' => $this->storefrontUrl('account/disputes/'.$dispute->number, $customer)]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * A delivery attempt was assigned to an agent (new or reassigned).
     */
    public function deliveryAssigned(DeliveryAssignment $assignment): void
    {
        try {
            $agent = DeliveryAgent::query()->find($assignment->delivery_agent_id);
            $user = $agent?->user_id === null ? null : User::query()->find($agent->user_id);

            if ($user === null) {
                return;
            }

            $subOrder = SubOrder::query()->findOrFail($assignment->sub_order_id);
            $address = (array) Order::query()->whereKey($subOrder->order_id)->value('shipping_address');
            $cod = $assignment->cod_amount === null || (float) $assignment->cod_amount <= 0 ? ''
                : InLocale::run($user->preferredLocale(), fn (): string => ' '.__('Collect cash:').' '.$this->money($assignment->cod_amount, (string) $assignment->currency));

            $this->emails->send('delivery.assignment', $user, [
                'shipment_number' => $subOrder->number,
                'area' => implode(', ', array_filter([$address['area'] ?? null, $address['city'] ?? null])),
                'deadline' => EmailValue::date($assignment->sla_due_at, time: true),
                'cod_amount' => $cod,
            ], ['entity' => $assignment, 'panel' => 'delivery', 'url' => Panel::find('delivery')->url('/assignments/'.$assignment->number)]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function paymentCaptured(Order $order, Payment $payment): void
    {
        $customer = $this->customer($order);
        $retried = $order->payments()->where('id', '<', $payment->id)->whereIn('status', [PaymentStatus::Failed, PaymentStatus::Expired])->exists();

        if ($customer !== null && $retried) {
            $this->emails->send('payment.retry_succeeded', $customer, [
                'order_number' => $order->number,
                'amount' => $this->money($payment->captured_amount ?? $payment->amount, $payment->currency),
            ], ['entity' => $order, 'url' => $this->orderUrl($order, $customer)]);
        }

        $this->orderPlaced($order, $payment);

        if ($customer !== null && $payment->method !== PaymentMethod::Cod) {
            $this->invoiceAvailable($order, $customer);
        }
    }

    private function paymentFailed(Order $order, Payment $payment): void
    {
        if ($payment->failure_code === 'superseded') {
            return;
        }

        $customer = $this->customer($order);

        if ($customer === null) {
            return;
        }

        $this->emails->send('payment.failed', $customer, [
            'order_number' => $order->number,
            'amount' => $this->money($payment->amount, $payment->currency),
            'reason' => EmailValue::t((string) ($payment->failure_message ?: 'The payment did not go through.')),
        ], ['entity' => $payment, 'url' => $this->orderUrl($order, $customer), 'bell_link' => '/account/orders/'.$order->number]);
    }

    private function paymentUnderReview(Order $order, Payment $payment): void
    {
        $customer = $this->customer($order);
        $amount = $this->money($payment->captured_amount ?? $payment->amount, $payment->currency);

        if ($customer !== null) {
            $this->emails->send('payment.under_review', $customer, [
                'order_number' => $order->number,
                'amount' => $amount,
            ], ['entity' => $payment, 'url' => $this->orderUrl($order, $customer)]);
        }

        $this->emails->alertAdmins('admin.payment_under_review', [
            'payment_reference' => $payment->reference,
            'order_number' => $order->number,
            'amount' => $amount,
            'reason' => EmailValue::t((string) ($payment->failure_message ?: 'The gateway captured a different amount.')),
        ], ['entity' => $payment, 'url' => Panel::find('admin')->url('/finance/payments-review/'.$payment->id)]);
    }

    /**
     * The tax invoice PDF, once per order (first capture, or the first
     * delivered parcel of a cash-on-delivery order).
     */
    private function invoiceAvailable(Order $order, User $customer): void
    {
        $this->emails->send('invoice.available', $customer, [
            'order_number' => $order->number,
            'total' => $this->money($order->grand_total, $order->currency),
        ], [
            'entity' => $order,
            'url' => $this->orderUrl($order, $customer),
            'attachments' => [[OrderInvoiceAttachment::class, ['order' => $order->number]]],
        ]);
    }

    private function subOrderMoved(StateMoved $event): void
    {
        /** @var SubOrder $subOrder */
        $subOrder = $event->subject;
        $order = Order::query()->find($subOrder->order_id);

        if ($order === null) {
            return;
        }

        $customer = $this->customer($order);

        match ($event->to) {
            SubOrderStatus::Confirmed => $customer === null ? null : $this->emails->send('order.confirmed', $customer, [
                'order_number' => $order->number,
                'shipment_number' => $subOrder->number,
                'seller' => $subOrder->fulfilled_by === 'nawy' ? EmailValue::t('Nawy') : (string) Vendor::query()->whereKey($subOrder->vendor_id)->value('store_name'),
                'estimated_delivery' => $this->estimatedDelivery($subOrder),
            ], ['entity' => $subOrder, 'url' => $this->orderUrl($order, $customer)]),
            SubOrderStatus::Shipped => $customer === null ? null : $this->shipped($order, $subOrder, $customer),
            SubOrderStatus::Delivered => $customer === null || $order->payment_method !== PaymentMethod::Cod->value ? null : $this->invoiceAvailable($order, $customer),
            SubOrderStatus::Cancelled => $this->cancelled($event, $order, $subOrder, $customer),
            default => null,
        };
    }

    private function shipped(Order $order, SubOrder $subOrder, User $customer): void
    {
        $shipment = Shipment::query()->where('sub_order_id', $subOrder->id)->latest('id')->first();
        $carrier = $shipment?->carrier_id === null ? null : Carrier::query()->whereKey($shipment->carrier_id)->value('name');

        $this->emails->send('order.shipped', $customer, [
            'order_number' => $order->number,
            'shipment_number' => $subOrder->number,
            'tracking_number' => $shipment?->number ?? $subOrder->number,
            'carrier' => $carrier ?? EmailValue::t('Nawy'),
            'items' => $this->itemList($subOrder->items()->get()),
            'estimated_delivery' => $this->estimatedDelivery($subOrder),
        ], ['entity' => $subOrder, 'url' => $this->orderUrl($order, $customer)]);
    }

    private function cancelled(StateMoved $event, Order $order, SubOrder $subOrder, ?User $customer): void
    {
        $reason = blank($event->reason) ? EmailValue::t('Not given') : EmailValue::t((string) $event->reason);

        if ($customer !== null) {
            $refund = Refund::query()->where('idempotency_key', 'cancel:'.$subOrder->id)->first();

            $this->emails->send('order.cancelled', $customer, [
                'order_number' => $order->number,
                'shipment_number' => $subOrder->number,
                'cancelled_by' => match ($event->actorType) {
                    ActorType::Customer => EmailValue::t('you'),
                    ActorType::Vendor => EmailValue::t('the seller'),
                    default => EmailValue::t('Nawy'),
                },
                'reason' => $reason,
                'refund' => $refund === null
                    ? EmailValue::t('Nothing was charged for this shipment, so there is nothing to refund.')
                    : EmailValue::t('We are refunding :amount to :method.', ['amount' => $this->money($refund->amount, $refund->currency), 'method' => $this->refundMethod($refund, $order)]),
            ], ['entity' => $subOrder, 'url' => $this->orderUrl($order, $customer)]);
        }

        $owner = Vendor::query()->whereKey($subOrder->vendor_id)->value('owner_user_id');

        // The store only heard of the order once it was placed (COD) or paid.
        $sellerKnew = $order->payment_method === PaymentMethod::Cod->value
            || $order->payments()->whereIn('status', [PaymentStatus::Authorized, PaymentStatus::Captured, PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded])->exists();

        if ($event->actorType !== ActorType::Vendor && $owner !== null && $subOrder->fulfilled_by !== 'nawy' && $sellerKnew) {
            $this->emails->send('seller.order_cancelled', (int) $owner, [
                'shipment_number' => $subOrder->number,
                'cancelled_by' => $event->actorType === ActorType::Customer ? EmailValue::t('the customer') : EmailValue::t('Nawy'),
                'reason' => $reason,
            ], ['entity' => $subOrder, 'panel' => 'partner', 'url' => Panel::find('partner')->url('/orders/'.$subOrder->id)]);
        }
    }

    private function returnMoved(StateMoved $event): void
    {
        /** @var ReturnRequest $return */
        $return = $event->subject;
        $customer = User::query()->find($return->customer_user_id);

        if ($customer === null) {
            return;
        }

        $orderNumber = (string) Order::query()->whereKey($return->order_id)->value('number');
        $options = ['entity' => $return, 'url' => $this->storefrontUrl('account/returns/'.$return->number, $customer)];

        match ($event->to) {
            ReturnStatus::Approved => $this->emails->send('return.approved', $customer, [
                'return_number' => $return->number,
                'order_number' => $orderNumber,
                'next_step' => $return->method === ReturnMethod::Pickup
                    ? EmailValue::t('A courier will collect the items from your address. Please keep them packed and ready.')
                    : EmailValue::t('Please drop the items off within seven days and keep the receipt.'),
            ], $options),
            ReturnStatus::Rejected => $this->emails->send('return.rejected', $customer, [
                'return_number' => $return->number,
                'order_number' => $orderNumber,
                'reason' => EmailValue::t((string) ($return->rejection_reason ?? $event->reason ?? 'Not given')),
            ], $options),
            ReturnStatus::Received => $this->emails->send('return.received', $customer, [
                'return_number' => $return->number,
                'order_number' => $orderNumber,
            ], $options),
            ReturnStatus::Completed => $this->returnCompleted($return, $customer, $options),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function returnCompleted(ReturnRequest $return, User $customer, array $options): void
    {
        $refund = $return->refund_id === null ? null : Refund::query()->find($return->refund_id);

        if ($refund === null || (float) $return->refund_total <= 0) {
            return;
        }

        $this->emails->send('return.refunded', $customer, [
            'return_number' => $return->number,
            'amount' => $this->money($return->refund_total, $refund->currency),
            'method' => $this->refundMethod($refund),
        ], $options);
    }

    private function refundMoved(StateMoved $event): void
    {
        if ($event->to !== RefundStatus::Completed) {
            return;
        }

        /** @var Refund $refund */
        $refund = $event->subject;
        $customer = User::query()->find($refund->customer_user_id);
        $order = Order::query()->find($refund->order_id);

        if ($customer === null || $order === null) {
            return;
        }

        $toWallet = $this->refundsToWallet($refund, $order);

        $this->emails->send('refund.issued', $customer, [
            'refund_number' => $refund->number,
            'order_number' => $order->number,
            'amount' => $this->money($refund->amount, $refund->currency),
            'method' => $this->refundMethod($refund, $order),
            'timeline' => $toWallet
                ? EmailValue::t('The money is in your Nawy wallet now.')
                : EmailValue::t('Card refunds usually reach your account within 5 to 10 business days.'),
        ], ['entity' => $refund, 'url' => $this->orderUrl($order, $customer)]);
    }

    private function disputeMoved(StateMoved $event): void
    {
        /** @var Dispute $dispute */
        $dispute = $event->subject;
        $customer = User::query()->find($dispute->customer_user_id);

        if ($customer === null) {
            return;
        }

        $options = ['entity' => $dispute, 'url' => $this->storefrontUrl('account/disputes/'.$dispute->number, $customer)];

        if (in_array($event->to, [DisputeStatus::UnderReview, DisputeStatus::Escalated], true)) {
            $this->emails->send('dispute.updated', $customer, [
                'dispute_number' => $dispute->number,
                'update' => EmailValue::t('The dispute is now: :status', ['status' => EmailValue::t(Label::key($event->to))]),
            ], $options + ['key' => 'status:'.$event->to->value]);

            return;
        }

        if ($event->to !== DisputeStatus::Resolved) {
            return;
        }

        $refund = $dispute->refund_id === null ? null : Refund::query()->find($dispute->refund_id);

        $this->emails->send('dispute.resolved', $customer, [
            'dispute_number' => $dispute->number,
            'resolution' => EmailValue::t(Label::key($dispute->resolution)),
            'refund' => $refund === null ? '' : EmailValue::t('We are refunding :amount to :method.', ['amount' => $this->money($refund->amount, $refund->currency), 'method' => $this->refundMethod($refund)]),
        ], $options);
    }

    /**
     * Receipt blocks of the order.placed email: lines, totals with VAT,
     * delivery address and payment.
     *
     * @return array<int, array<string, mixed>>
     */
    private function receipt(Order $order, ?Payment $payment): array
    {
        $currency = $order->currency;
        $lines = [];

        foreach ($order->subOrders as $subOrder) {
            foreach ($subOrder->items as $item) {
                $lines[] = [(string) $item->title, (string) $item->quantity, $this->money($item->line_total, $currency)];
            }
        }

        $totals = [['Subtotal', $this->money($order->subtotal, $currency)]];

        if ((float) $order->discount_total > 0) {
            $totals[] = ['Discount', '−'.$this->money($order->discount_total, $currency)];
        }

        $totals[] = ['Shipping', (float) $order->shipping_total > 0 ? $this->money($order->shipping_total, $currency) : EmailValue::t('Free')];

        if ((float) $order->cod_fee > 0) {
            $totals[] = ['Cash on delivery fee', $this->money($order->cod_fee, $currency)];
        }

        if ((float) $order->warranty_total > 0) {
            $totals[] = ['Warranty', $this->money($order->warranty_total, $currency)];
        }

        if ((float) $order->tax_included > 0) {
            $rate = rtrim(rtrim((string) $order->tax_rate, '0'), '.');
            $totals[] = ['VAT', [$this->money($order->tax_included, $currency), EmailValue::t('VAT included (:rate%)', ['rate' => $rate])]];
        }

        $totals[] = ['Total', $this->money($order->grand_total, $currency)];

        $address = (array) $order->shipping_address;
        $street = implode(', ', array_filter(array_map(fn ($part): string => trim((string) $part), [
            $address['apartment'] ?? null, $address['building'] ?? null, $address['street'] ?? null, $address['landmark'] ?? null, $address['area'] ?? null, $address['city'] ?? null,
        ])));
        $addressRows = array_values(array_filter([
            filled($address['name'] ?? null) ? ['Name', (string) $address['name']] : null,
            $street !== '' ? ['Address', $street] : null,
            filled($address['phone'] ?? null) ? ['Phone', (string) $address['phone']] : null,
        ]));

        $paymentRows = [['Method', $this->methodLabel($order->payment_method)]];

        if ($payment !== null) {
            $paymentRows[] = ['Status', EmailValue::t(Label::key($payment->status))];
        }

        return array_values(array_filter([
            ['type' => 'table', 'columns' => ['Item', 'Qty', 'Price'], 'rows' => $lines],
            ['type' => 'totals', 'rows' => $totals],
            $addressRows === [] ? null : ['type' => 'facts', 'title' => 'Delivery address', 'rows' => $addressRows],
            ['type' => 'facts', 'title' => 'Payment', 'rows' => $paymentRows],
        ]));
    }

    /**
     * @param  iterable<OrderItem>  $items
     */
    private function itemList(iterable $items): string
    {
        return collect($items)->map(fn (OrderItem $item): string => $item->title.' × '.$item->quantity)->implode(', ');
    }

    /**
     * @return array<string, mixed>|string
     */
    private function estimatedDelivery(SubOrder $subOrder): array|string
    {
        $by = $subOrder->estimated_delivery_by;

        return $by === null ? EmailValue::t('Not set') : EmailValue::date($by->toDateString().' 12:00:00');
    }

    /**
     * A payment method's name in the recipient's language (brand names as they are).
     *
     * @return array<string, mixed>|string
     */
    private function methodLabel(?string $method): array|string
    {
        return match (PaymentMethod::tryFrom((string) $method)) {
            PaymentMethod::Stripe => EmailValue::t('Credit / debit card'),
            PaymentMethod::Tabby => EmailValue::t('Tabby · pay in 4'),
            PaymentMethod::Tamara => EmailValue::t('Tamara · split in 3'),
            PaymentMethod::Cod => EmailValue::t('Cash on delivery'),
            PaymentMethod::Wallet => EmailValue::t('Nawy Wallet'),
            PaymentMethod::GiftCard => EmailValue::t('Gift card'),
            null => (string) $method,
            default => PaymentMethod::from((string) $method)->label(),
        };
    }

    /**
     * Where a refund goes: the Nawy wallet (credits, COD and wallet
     * payments) or the original card / BNPL method.
     *
     * @return array<string, mixed>
     */
    private function refundMethod(Refund $refund, ?Order $order = null): array
    {
        return $this->refundsToWallet($refund, $order ?? Order::query()->find($refund->order_id))
            ? EmailValue::t('your Nawy wallet')
            : EmailValue::t('your original payment method');
    }

    private function refundsToWallet(Refund $refund, ?Order $order): bool
    {
        $method = PaymentMethod::tryFrom((string) $order?->payment_method);

        return $refund->destination === RefundDestination::Wallet || $method === null || ! $method->isGateway();
    }

    private function customer(Order $order): ?User
    {
        return User::query()->find($order->customer_user_id);
    }

    private function money(string|int|float|null $amount, string $currency): string
    {
        return EmailValue::money($amount, $currency);
    }

    private function orderUrl(Order $order, User $customer): string
    {
        return $this->storefrontUrl('account/orders/'.$order->number, $customer);
    }

    private function storefrontUrl(string $path, User $customer): string
    {
        return $this->storefront->to($path, [], $customer->preferredLocale());
    }
}
