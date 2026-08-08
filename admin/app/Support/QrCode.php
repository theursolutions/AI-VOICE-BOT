<?php

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Renderer\RendererStyle\EyeFill;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Writer;

/**
 * SVG QR codes, rendered server-side.
 *
 * SVG rather than PNG on purpose: it needs no image extension, stays sharp
 * on any display, is a few KB, and can be inlined straight into the page so
 * the browser makes no extra request for it.
 */
class QrCode
{
    /**
     * @param  string  $data  what the QR encodes (a URL, here)
     * @param  int     $size  rendered edge length in px
     */
    public static function svg(string $data, int $size = 260, string $foreground = '#0f172a'): string
    {
        [$r, $g, $b] = self::rgb($foreground);

        $style = new RendererStyle(
            $size,
            // Quiet-zone modules. Below ~2 some phone cameras fail to lock
            // onto the code, which reads to the user as "the QR is broken".
            2,
            null,
            null,
            Fill::uniformColor(new Rgb(255, 255, 255), new Rgb($r, $g, $b)),
        );

        $writer = new Writer(new ImageRenderer($style, new SvgImageBackEnd()));

        return $writer->writeString($data);
    }

    /**
     * The SVG as a data: URI, for an <img src>. Base64 rather than raw
     * because the SVG contains characters that would need escaping inside
     * an attribute.
     */
    public static function dataUri(string $data, int $size = 260, string $foreground = '#0f172a'): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::svg($data, $size, $foreground));
    }

    /** #rrggbb (or #rgb) → [r, g, b]; falls back to near-black. */
    private static function rgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (! preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return [15, 23, 42];
        }

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
