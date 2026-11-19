<?php

use App\Domains\Delivery\Support\DeliveryDispatch;
use App\Domains\Disputes\Enums\DisputeReason;
use App\Domains\Disputes\Support\Disputes;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Support\SubOrderStateMachine;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Support\Gateways\FakeGateway;
use App\Domains\Payments\Support\PaymentAttempts;
use App\Domains\Refunds\Enums\RefundDestination;
use App\Domains\Refunds\Models\Refund;
use App\Domains\Returns\Enums\ReturnMethod;
use App\Domains\Returns\Enums\ReturnReason;
use App\Domains\Returns\Enums\ReturnStatus;
use App\Domains\Returns\Support\ReturnRequests;
use App\Shared\Notifications\Email\EmailPreferences;
use App\Shared\Notifications\Email\Models\EmailLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    Cache::flush();
    Storage::fake('local');
});

/**
 * @return Collection<int, EmailLog>
 */
function commerceEmails(string $event): Collection
{
    return EmailLog::query()->where('event', $event)->orderBy('id')->get();
}

it('sends the COD receipt with a 3-decimal OMR total and one new-order email per sub-order', function () {
    $this->actAsCountry('omn');
    [$seller, $vendor] = sellerAccount('omn');
    [$order, $customer] = placeOrderVia('cod', '12.345', 'omn-en', vendor: $vendor);

    $total = 'OMR '.number_format((float) $order->grand_total, 3);
    expect($total)->toMatch('/OMR \d+\.\d{3}$/');

    $receipt = commerceEmails('order.placed');
    expect($receipt)->toHaveCount(1)
        ->and($receipt->first()->user_id)->toBe($customer->id)
        ->and($receipt->first()->entity_id)->toBe((string) $order->id);

    $html = html_entity_decode((string) sentCatalogueEmails('order.placed', $customer->email)->sole()->getHtmlBody(), ENT_QUOTES);
    expect($html)->toContain($total)->toContain('OMR 12.345')->toContain('Gate 2')->toContain('Cash on delivery');

    $sellerMails = commerceEmails('seller.order_new');
    expect($sellerMails)->toHaveCount($order->subOrders()->count())
        ->and($sellerMails->pluck('user_id')->unique()->all())->toBe([$seller->id]);

    // No invoice before a COD parcel is delivered.
    expect(commerceEmails('invoice.available'))->toHaveCount(0);
});

it('sends the card receipt and the invoice once the payment is captured, not before', function () {
    $this->actAsCountry('uae');
    [, $vendor] = sellerAccount('uae');
    [$order, $customer] = placeOrderVia('stripe', vendor: $vendor);

    expect(commerceEmails('order.placed'))->toHaveCount(0);

    captureOrderPayment($order);
    captureOrderPayment($order->refresh());

    expect(commerceEmails('order.placed'))->toHaveCount(1)
        ->and(commerceEmails('seller.order_new'))->toHaveCount(1)
        ->and(commerceEmails('invoice.available'))->toHaveCount(1)
        ->and(commerceEmails('payment.retry_succeeded'))->toHaveCount(0);

    $invoice = sentCatalogueEmails('invoice.available', $customer->email)->sole();
    expect($invoice->getAttachments())->toHaveCount(1)
        ->and($invoice->getAttachments()[0]->getContentType())->toBe('application/pdf');
});

it('tells the customer when a payment fails and when the retry goes through', function () {
    $this->actAsCountry('uae');
    [$order, $customer] = placeOrderVia('stripe');
    $payment = $order->payments()->sole();
    FakeGateway::willReport($payment->gateway_reference, PaymentStatus::Failed);
    app(PaymentAttempts::class)->refresh($payment);

    expect(commerceEmails('payment.failed'))->toHaveCount(1)
        ->and(commerceEmails('payment.failed')->first()->user_id)->toBe($customer->id);

    app(PaymentAttempts::class)->retry($order->refresh(), 'stripe', $customer);
    // The retry is the order's pending attempt (captureOrderPayment() would pick the first one).
    $retry = $order->payments()->where('status', PaymentStatus::Pending)->sole();
    FakeGateway::willReport($retry->gateway_reference, PaymentStatus::Captured);
    app(PaymentAttempts::class)->refresh($retry);

    expect(commerceEmails('payment.retry_succeeded'))->toHaveCount(1)
        ->and(commerceEmails('order.placed'))->toHaveCount(1)
        ->and(commerceEmails('payment.failed'))->toHaveCount(1);
});

it('sends order.shipped for each parcel of an FBN split with its tracking number', function () {
    $this->actAsCountry('uae');
    [, $vendor] = sellerAccount('uae');
    [$order, $customer] = placeOrderVia('cod', vendor: $vendor);
    $sellerParcel = $order->subOrders()->sole();
    $warehouseParcel = $order->subOrders()->create([
        'vendor_id' => $vendor->id,
        'fulfilled_by' => 'nawy',
        'split_from_sub_order_id' => $sellerParcel->id,
        'number' => $order->number.'-2',
        'subtotal' => '10.00',
        'total' => '10.00',
    ]);
    $warehouseParcel->items()->create(['listing_id' => $sellerParcel->items()->first()->listing_id, 'sku' => 'FBN-1', 'title' => 'Warehouse item', 'quantity' => 1, 'unit_price' => '10.00', 'line_total' => '10.00']);
    $shipment = app(DeliveryDispatch::class)->shipmentFor($sellerParcel, ActorType::Admin);

    moveSubOrderTo($sellerParcel, SubOrderStatus::Shipped);
    moveSubOrderTo($warehouseParcel, SubOrderStatus::Shipped);

    $shipped = commerceEmails('order.shipped');
    expect($shipped)->toHaveCount(2)
        ->and($shipped->pluck('entity_id')->all())->toBe([(string) $sellerParcel->id, (string) $warehouseParcel->id])
        ->and($shipped[0]->payload['variables']['tracking_number'])->toBe($shipment->number)
        ->and($shipped[1]->payload['variables']['tracking_number'])->toBe($warehouseParcel->number)
        ->and($shipped->pluck('user_id')->unique()->all())->toBe([$customer->id])
        ->and(commerceEmails('order.confirmed'))->toHaveCount(2);
});

it('sends the cancellation with its refund to the customer, tells the seller and confirms the refund', function () {
    $this->actAsCountry('uae');
    [$seller, $vendor] = sellerAccount('uae');
    [$order, $customer] = placeOrderVia('stripe', vendor: $vendor);
    captureOrderPayment($order);
    $subOrder = $order->subOrders()->sole();

    app(SubOrderStateMachine::class)->transition($subOrder, SubOrderStatus::Cancelled, ActorType::Customer, $customer->id, 'Changed my mind');

    $refund = Refund::query()->sole();
    $amount = 'AED '.number_format((float) $refund->amount, 2);

    expect(commerceEmails('order.cancelled'))->toHaveCount(1)
        ->and(commerceEmails('seller.order_cancelled')->first()?->user_id)->toBe($seller->id)
        ->and(commerceEmails('refund.issued'))->toHaveCount(1);

    expect(html_entity_decode((string) sentCatalogueEmails('order.cancelled', $customer->email)->sole()->getHtmlBody(), ENT_QUOTES))
        ->toContain('We are refunding '.$amount.' to your original payment method.');
});

it('emails the customer and the seller through the return flow up to the refund', function () {
    $this->actAsCountry('uae');
    [$seller, $vendor] = sellerAccount('uae');
    [$order, $customer] = placeOrderVia('stripe', vendor: $vendor);
    captureOrderPayment($order);
    $subOrder = moveSubOrderTo($order->subOrders()->sole(), SubOrderStatus::Delivered);
    $returns = app(ReturnRequests::class);

    $return = $returns->create($subOrder, $customer->id, [['order_item_id' => $subOrder->items()->first()->id, 'quantity' => 1]],
        ReturnReason::Defective, ReturnMethod::DropOff, RefundDestination::Wallet);

    expect(commerceEmails('return.requested')->first()?->user_id)->toBe($customer->id)
        ->and(commerceEmails('seller.return_requested')->first()?->user_id)->toBe($seller->id);

    $returns->approve($return, ActorType::Vendor, $seller->id);
    $returns->advance($return->refresh(), ReturnStatus::Received, ActorType::Vendor, $seller->id);
    $returns->inspect($return->refresh(), [$return->items()->first()->id => ['outcome' => 'good', 'restock' => true]], ActorType::Vendor, $seller->id);

    expect(commerceEmails('return.approved'))->toHaveCount(1)
        ->and(commerceEmails('return.received'))->toHaveCount(1)
        ->and(commerceEmails('return.refunded'))->toHaveCount(1)
        ->and(commerceEmails('refund.issued'))->toHaveCount(1);

    expect((string) sentCatalogueEmails('refund.issued')->sole()->getHtmlBody())->toContain('your Nawy wallet');
});

it('confirms a dispute to the customer, alerts the opted-in admin and passes on the seller reply', function () {
    $this->actAsCountry('uae');
    [$seller, $vendor] = sellerAccount('uae');
    [$order, $customer] = placeOrderVia('cod', vendor: $vendor);
    $subOrder = moveSubOrderTo($order->subOrders()->sole(), SubOrderStatus::Shipped);
    $optedIn = adminAccount();
    $silent = adminAccount();
    app(EmailPreferences::class)->set($optedIn, 'admin:admin.dispute_opened', true);
    $disputes = app(Disputes::class);

    $dispute = $disputes->open($subOrder, $customer->id, DisputeReason::NotReceived, 'The parcel never arrived');
    $disputes->message($dispute, ActorType::Vendor, $seller->id, 'It is with the courier');

    expect(commerceEmails('dispute.opened')->first()?->user_id)->toBe($customer->id)
        ->and(commerceEmails('admin.dispute_opened')->pluck('user_id')->all())->toBe([$optedIn->id])
        ->and(commerceEmails('dispute.updated'))->toHaveCount(1);

    expect((string) sentCatalogueEmails('dispute.updated')->sole()->getHtmlBody())->toContain('It is with the courier')
        ->and(sentCatalogueEmails('admin.dispute_opened', $silent->email))->toHaveCount(0);
});
