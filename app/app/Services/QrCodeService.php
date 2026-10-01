<?php

namespace App\Services;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * QR rendering for the public customer invoice (chillerlan/php-qrcode).
 *
 * The QR only ever encodes the PUBLIC invoice URL — never a database id, a
 * session, or anything that grants write access.
 */
class QrCodeService
{
    /** Raw PNG bytes of the QR for the given payload. */
    public function png(string $data, int $scale = 6): string
    {
        return (string) $this->render($data, $scale, false);
    }

    /** Inline data URI (avoids an extra authenticated asset route). */
    public function pngDataUri(string $data, int $scale = 6): string
    {
        return 'data:image/png;base64,'.base64_encode($this->png($data, $scale));
    }

    private function render(string $data, int $scale, bool $base64): string
    {
        return (string) (new QRCode(new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'outputBase64' => $base64,
            'scale' => max(2, min(12, $scale)),
            // Public invoice URLs are ~60 characters; LOW keeps the module
            // count small while remaining scannable from a phone screen.
            'eccLevel' => EccLevel::L,
            'quietzoneSize' => 2,
        ])))->render($data);
    }
}
