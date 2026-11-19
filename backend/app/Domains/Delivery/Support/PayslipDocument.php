<?php

namespace App\Domains\Delivery\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Delivery\Models\DeliveryPayslip;
use App\Shared\Money\MoneyFormatter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * A payslip as HTML (delivery panel) and as an A4 PDF (dompdf, D16): the
 * agent, the period, the rates, every delivery or failed attempt and the
 * total credited to the wallet.
 */
class PayslipDocument
{
    public function __construct(private MoneyFormatter $formatter, private CountryContext $countryContext) {}

    public function view(DeliveryPayslip $payslip, bool $forPdf = false): View
    {
        $payslip->loadMissing(['agent.carrier', 'lines.assignment.subOrder']);

        return view('delivery.payslip', [
            'payslip' => $payslip,
            'country' => $this->countryContext->current(),
            'forPdf' => $forPdf,
            'money' => fn (string|int|null $amount): string => $amount === null ? '—' : $this->formatter->format($amount, $payslip->currency),
        ]);
    }

    public function pdf(DeliveryPayslip $payslip): Response
    {
        $pdf = Pdf::loadHTML($this->view($payslip, true)->render())->setPaper('a4')->output();

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="payslip-'.preg_replace('/[^A-Za-z0-9-]/', '', $payslip->number).'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
