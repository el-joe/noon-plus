<?php

namespace App\Domains\Campaigns\Support;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Symfony\Component\HttpFoundation\Response;

/**
 * D16: the QR code of a creator's referral link (`/r/{code}`), drawn on the
 * server (chillerlan/php-qrcode) as SVG or PNG. It encodes the share
 * payload from {@see ReferralCodes::share()} (`…/r/{code}?utm_source=qr&market=…`).
 */
class ReferralQr
{
    public const FORMATS = ['svg', 'png'];

    public function render(string $payload, string $format = 'svg'): string
    {
        $options = new QROptions([
            'outputInterface' => $format === 'png' ? QRGdImagePNG::class : QRMarkupSVG::class,
            'outputBase64' => false,
            'eccLevel' => EccLevel::M,
            'scale' => 10,
            'quietzoneSize' => 4,
            'svgAddXmlHeader' => true,
            'drawLightModules' => false,
            'connectPaths' => true,
        ]);

        return (new QRCode($options))->render($payload);
    }

    /**
     * The QR as a file download (`referral-{code}.svg|png`), or inline.
     */
    public function response(string $payload, string $code, string $format, bool $download = true): Response
    {
        $format = in_array($format, self::FORMATS, true) ? $format : 'svg';
        $filename = 'referral-'.preg_replace('/[^A-Za-z0-9]/', '', $code).'.'.$format;

        return response($this->render($payload, $format), 200, [
            'Content-Type' => $format === 'png' ? 'image/png' : 'image/svg+xml',
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.$filename.'"',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
