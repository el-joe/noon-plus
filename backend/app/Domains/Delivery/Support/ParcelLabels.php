<?php

namespace App\Domains\Delivery\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Delivery\Models\Shipment;
use App\Domains\Fulfilment\Support\InboundLabels;
use App\Shared\Money\MoneyFormatter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * The A6 parcel label of a shipment: the {@see ParcelBarcode} payload as a
 * Code 39 barcode (drawn as table cells, like the FBN carton labels), the
 * human-readable shipment number, ship-to, seller, sub-order and COD
 * amount. HTML to print and the same view as a PDF (dompdf).
 */
class ParcelLabels
{
    public function __construct(private CountryContext $countryContext, private MoneyFormatter $formatter) {}

    public function view(Shipment $shipment, bool $forPdf = false): View
    {
        $shipment->loadMissing(['subOrder.order', 'subOrder.vendor', 'subOrder.items', 'carrier']);
        $payload = ParcelBarcode::payload($shipment);
        $order = $shipment->subOrder->order;

        return view('delivery.parcel-label', [
            'shipment' => $shipment,
            'payload' => $payload,
            'bars' => InboundLabels::code39($payload),
            'address' => collect($order->shipping_address ?? [])->only(['name', 'phone', 'street', 'building', 'area', 'city'])->filter(fn ($value) => is_scalar($value) && $value !== ''),
            'cod' => $order->payment_method === 'cod' ? $this->formatter->format((string) $shipment->subOrder->total, $order->currency) : null,
            'country' => $this->countryContext->current(),
            'forPdf' => $forPdf,
        ]);
    }

    public function pdf(Shipment $shipment): Response
    {
        $pdf = Pdf::loadHTML($this->view($shipment, true)->render())->setPaper('a6')->output();

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="label-'.preg_replace('/[^A-Za-z0-9-]/', '', (string) $shipment->number).'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
