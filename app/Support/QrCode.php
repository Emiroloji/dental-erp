<?php

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Etiketler için QR kod (SVG — yazdırmada netlik kaybı olmaz, ek PHP eklentisi gerekmez).
 */
class QrCode
{
    public static function svg(string $payload, int $size = 160): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd));

        // XML başlığı olmadan satır içi <svg> döner.
        return preg_replace('/^<\?xml[^>]*>\s*/', '', $writer->writeString($payload));
    }
}
