<?php

namespace App\Domains\Orders\Support;

use App\Domains\Countries\Models\Country;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Models\User;
use App\Shared\Money\MoneyFactory;
use App\Shared\Money\MoneyFormatter;
use Barryvdh\DomPDF\Facade\Pdf;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * D16: the tax invoice as a PDF (dompdf, no browser on the servers). The
 * customer gets the whole order; a seller gets only its own shipment, with
 * the shipment's totals and the VAT included in them.
 *
 * Amounts are rounded to the currency's minor unit (3 decimals for OMR, KWD,
 * BHD and JOD) and shown through {@see MoneyFormatter}.
 */
class InvoicePdf
{
    public function __construct(private MoneyFactory $money, private MoneyFormatter $formatter) {}

    /**
     * G-A6: an invoice exists once the order is paid (a cash-on-delivery
     * order once one of its shipments is delivered).
     */
    public static function isReady(Order $order): bool
    {
        if (PaymentStatus::tryFrom((string) $order->payment_status)?->isSuccessful() === true) {
            return true;
        }

        return $order->payment_method === 'cod'
            && $order->subOrders->contains(fn (SubOrder $subOrder): bool => in_array($subOrder->status, [SubOrderStatus::Delivered, SubOrderStatus::Completed], true));
    }

    public function forOrder(Order $order, Country $country, User $customer): string
    {
        return $this->pdf($this->orderView($order, $country, $customer));
    }

    public function forSubOrder(SubOrder $subOrder, Country $country): string
    {
        return $this->pdf($this->subOrderView($subOrder, $country));
    }

    /**
     * The customer's invoice for the whole order (the HTML the PDF is drawn from).
     */
    public function orderView(Order $order, Country $country, User $customer): View
    {
        $order->loadMissing('subOrders.vendor', 'subOrders.items');
        $totals = [['label' => __('Subtotal'), 'amount' => $order->subtotal]];

        if ((float) $order->discount_total > 0) {
            $totals[] = ['label' => __('Discount'), 'amount' => $order->discount_total, 'negative' => true];
        }

        $totals[] = ['label' => __('Shipping'), 'amount' => $order->shipping_total];

        if ((float) $order->cod_fee > 0) {
            $totals[] = ['label' => __('Cash on delivery fee'), 'amount' => $order->cod_fee];
        }

        if ((float) $order->warranty_total > 0) {
            $totals[] = ['label' => __('Warranty'), 'amount' => $order->warranty_total];
        }

        $totals[] = ['label' => __('Total'), 'amount' => $order->grand_total, 'grand' => true];
        $totals[] = ['label' => $this->vatLabel($order), 'amount' => $order->tax_included, 'muted' => true];

        return $this->view($order, $order->subOrders, $totals, $country, $customer);
    }

    /**
     * A seller's invoice for one shipment: its lines, its totals and the VAT they include.
     */
    public function subOrderView(SubOrder $subOrder, Country $country): View
    {
        $subOrder->loadMissing('order', 'vendor', 'items');
        $order = $subOrder->order;
        $total = $this->money->of((string) $subOrder->total, $order->currency);
        $rate = BigRational::of((string) ($order->tax_rate ?? '0'));
        $vat = $rate->isZero() ? $this->money->zero($order->currency)
            : $total->multipliedBy($rate->dividedBy($rate->plus(100)), RoundingMode::HalfUp);
        $totals = [['label' => __('Subtotal'), 'amount' => $subOrder->subtotal]];

        if ((float) $subOrder->discount_total > 0) {
            $totals[] = ['label' => __('Discount'), 'amount' => $subOrder->discount_total, 'negative' => true];
        }

        $totals[] = ['label' => __('Shipping'), 'amount' => $subOrder->shipping_total];
        $totals[] = ['label' => __('Total'), 'amount' => $subOrder->total, 'grand' => true];
        $totals[] = ['label' => $this->vatLabel($order), 'amount' => (string) $vat->getAmount(), 'muted' => true];

        $customer = User::query()->findOrFail($order->customer_user_id);

        return $this->view($order, collect([$subOrder]), $totals, $country, $customer);
    }

    /**
     * The PDF as a download.
     */
    public function download(string $pdf, string $number): Response
    {
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="invoice-'.preg_replace('/[^A-Za-z0-9-]/', '', $number).'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @param  Collection<int, SubOrder>  $subOrders
     * @param  array<int, array<string, mixed>>  $totals
     */
    private function view(Order $order, Collection $subOrders, array $totals, Country $country, User $customer): View
    {
        return view('invoices.order-pdf', [
            'order' => $order,
            'subOrders' => $subOrders,
            'totals' => $totals,
            'country' => $country,
            'customer' => $customer,
            'money' => fn (string|int|null $amount): string => $this->formatter->format($amount, $order->currency),
        ]);
    }

    private function pdf(View $view): string
    {
        return Pdf::loadHTML($view->render())->setPaper('a4')->output();
    }

    private function vatLabel(Order $order): string
    {
        return __('VAT included (:rate%)', ['rate' => rtrim(rtrim((string) $order->tax_rate, '0'), '.')]);
    }
}
