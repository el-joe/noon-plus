<?php

namespace App\Domains\Fulfilment\Support;

use App\Domains\Fulfilment\Models\InboundShipment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Carton labels of a submitted inbound plan: one label per carton with the
 * plan reference as a Code 39 barcode (drawn as table cells, no package),
 * the destination warehouse, the seller and the SKUs — HTML to print and
 * the same view as an A6 PDF (dompdf).
 */
class InboundLabels
{
    /**
     * Code 39: 9 elements per character (bar, space, …), n = narrow, w = wide.
     *
     * @var array<string, string>
     */
    private const CODE39 = [
        '0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn', '4' => 'nnnwwnnnw',
        '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw', '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn',
        'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw', 'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw', 'E' => 'wnnnwwnnn',
        'F' => 'nnwnwwnnn', 'G' => 'nnnnnwwnw', 'H' => 'wnnnnwwnn', 'I' => 'nnwnnwwnn', 'J' => 'nnnnwwwnn',
        'K' => 'wnnnnnnww', 'L' => 'nnwnnnnww', 'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww', 'O' => 'wnnnwnnwn',
        'P' => 'nnwnwnnwn', 'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn', 'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn',
        'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw', 'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw', 'Y' => 'wwnnwnnnn',
        'Z' => 'nwwnwnnnn', '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn', '*' => 'nwnnwnwnn',
    ];

    /**
     * Bars of a Code 39 value: [isBar, width units] pairs, with start/stop
     * characters and a narrow gap between characters.
     *
     * @return array<int, array{0: bool, 1: int}>
     */
    public static function code39(string $value): array
    {
        $elements = [];
        $characters = str_split('*'.strtoupper(preg_replace('/[^A-Za-z0-9\-. ]/', '', $value)).'*');

        foreach ($characters as $index => $character) {
            foreach (str_split(self::CODE39[$character]) as $position => $width) {
                $elements[] = [$position % 2 === 0, $width === 'w' ? 3 : 1];
            }

            if ($index < count($characters) - 1) {
                $elements[] = [false, 1];
            }
        }

        return $elements;
    }

    public function view(InboundShipment $shipment, bool $forPdf = false): View
    {
        $shipment->loadMissing(['lines.listing.variant.product', 'warehouse', 'vendor']);

        return view('fbn.inbound-labels', [
            'shipment' => $shipment,
            'cartons' => max(1, (int) $shipment->cartons),
            'bars' => self::code39((string) $shipment->number),
            'forPdf' => $forPdf,
        ]);
    }

    public function pdf(InboundShipment $shipment): Response
    {
        $pdf = Pdf::loadHTML($this->view($shipment, true)->render())->setPaper('a6')->output();

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="labels-'.preg_replace('/[^A-Za-z0-9-]/', '', (string) $shipment->number).'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
