<?php

namespace App\Domains\Finance\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Finance\Models\VendorSubscriptionInvoice;
use App\Shared\Money\MoneyFormatter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * A vendor subscription invoice as HTML and as an A4 PDF (dompdf): seller,
 * plan, period, amount with the VAT included, status and how it was paid.
 */
class SubscriptionInvoiceDocument
{
    public function __construct(private MoneyFormatter $formatter, private CountryContext $countryContext) {}

    public function view(VendorSubscriptionInvoice $invoice): View
    {
        $invoice->loadMissing(['vendor', 'plan']);
        $country = $this->countryContext->current();

        return view('finance.subscription-invoice', [
            'invoice' => $invoice,
            'country' => $country,
            'net' => $this->formatter->format(bcsub((string) $invoice->amount, (string) $invoice->vat, 4), $invoice->currency),
            'vat' => $this->formatter->format((string) $invoice->vat, $invoice->currency),
            'total' => $this->formatter->format((string) $invoice->amount, $invoice->currency),
        ]);
    }

    public function pdf(VendorSubscriptionInvoice $invoice): Response
    {
        $pdf = Pdf::loadHTML($this->view($invoice)->render())->setPaper('a4')->output();

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.preg_replace('/[^A-Za-z0-9-]/', '', $invoice->number).'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
