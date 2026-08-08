<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Inline brand/channel glyphs for the public marketing site.
 *
 * The channel cards on the homepage used to render bare emoji, which look
 * different on every OS (and plainly wrong for WhatsApp/Instagram/Facebook,
 * where the real logo is what a visitor recognises). These are self-hosted
 * SVG paths — no external icon CDN, no webfont, and they inherit the page's
 * neon theme via `currentColor` unless the brand has its own colour.
 *
 * Values come from the super-admin content editor (`content.channelN_icon`),
 * so render() is deliberately forgiving: a known slug becomes an SVG, and
 * anything else (an emoji, a stray word) is escaped and passed through so an
 * operator can never break the page by typing into that box.
 */
final class BrandIcons
{
    /**
     * slug => [viewBox path(s), brand colour or null for currentColor]
     *
     * Paths are single-colour glyphs on a 24×24 grid.
     */
    private const ICONS = [
        'whatsapp' => [
            'color' => '#25D366',
            'path'  => 'M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z',
        ],
        'instagram' => [
            // Instagram's mark is a gradient; render() special-cases it below.
            'color' => 'url(#sa-ig-grad)',
            'path'  => 'M12 0C8.74 0 8.333.015 7.053.072 5.775.132 4.905.333 4.14.63c-.789.306-1.459.717-2.126 1.384S.935 3.35.63 4.14C.333 4.905.131 5.775.072 7.053.012 8.333 0 8.74 0 12s.015 3.667.072 4.947c.06 1.277.261 2.148.558 2.913.306.788.717 1.459 1.384 2.126.667.666 1.336 1.079 2.126 1.384.766.296 1.636.499 2.913.558C8.333 23.988 8.74 24 12 24s3.667-.015 4.947-.072c1.277-.06 2.148-.262 2.913-.558.788-.306 1.459-.718 2.126-1.384.666-.667 1.079-1.335 1.384-2.126.296-.765.499-1.636.558-2.913.06-1.28.072-1.687.072-4.947s-.015-3.667-.072-4.947c-.06-1.277-.262-2.149-.558-2.913-.306-.789-.718-1.459-1.384-2.126C21.319 1.347 20.651.935 19.86.63c-.765-.297-1.636-.499-2.913-.558C15.667.012 15.26 0 12 0zm0 2.16c3.203 0 3.585.016 4.85.071 1.17.055 1.805.249 2.227.415.562.217.96.477 1.382.896.419.42.679.819.896 1.381.164.422.36 1.057.413 2.227.057 1.266.07 1.646.07 4.85s-.015 3.585-.074 4.85c-.061 1.17-.256 1.805-.421 2.227-.224.562-.479.96-.899 1.382-.419.419-.824.679-1.38.896-.42.164-1.065.36-2.235.413-1.274.057-1.649.07-4.859.07-3.211 0-3.586-.015-4.859-.074-1.171-.061-1.816-.256-2.236-.421-.569-.224-.96-.479-1.379-.899-.421-.419-.69-.824-.9-1.38-.165-.42-.359-1.065-.42-2.235-.045-1.26-.061-1.649-.061-4.844 0-3.196.016-3.586.061-4.861.061-1.17.255-1.814.42-2.234.21-.57.479-.96.9-1.381.419-.419.81-.689 1.379-.898.42-.166 1.051-.361 2.221-.421 1.275-.045 1.65-.06 4.859-.06zm0 3.678a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm7.846-10.405a1.441 1.441 0 11-2.881 0 1.441 1.441 0 012.881 0z',
        ],
        'facebook' => [
            'color' => '#1877F2',
            'path'  => 'M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z',
        ],
        'messenger' => [
            'color' => '#00B2FF',
            'path'  => 'M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.301 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111C24 4.974 18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26L10.732 8l3.131 3.259L19.752 8l-6.561 6.963z',
        ],
        // Non-brand channels: neutral glyphs that take the section's accent
        // colour, so they sit beside the brand marks without shouting.
        'voice' => [
            'color' => null,
            'path'  => 'M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z',
        ],
        'webchat' => [
            'color' => null,
            'path'  => 'M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM6 9h12v2H6V9zm8 5H6v-2h8v2zm4-6H6V6h12v2z',
        ],
        'sms' => [
            'color' => null,
            'path'  => 'M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z',
        ],
    ];

    /** Slugs an operator may type into a `*_icon` content field. */
    public static function slugs(): array
    {
        return array_keys(self::ICONS);
    }

    /** True when $value names a glyph we can render. */
    public static function has(string $value): bool
    {
        return isset(self::ICONS[strtolower(trim($value))]);
    }

    /**
     * Render $value as an inline SVG when it names a known glyph, otherwise
     * escape it and return it verbatim (emoji, text — operator's choice).
     *
     * Safe to echo with {!! !!}: every branch is either a fixed literal from
     * self::ICONS or run through e().
     */
    public static function render(string $value, int $size = 30, string $class = ''): HtmlString
    {
        $slug = strtolower(trim($value));

        if (! isset(self::ICONS[$slug])) {
            return new HtmlString(e($value));
        }

        [$color, $path] = [self::ICONS[$slug]['color'], self::ICONS[$slug]['path']];
        $fill  = $color ?? 'currentColor';
        $attrs = sprintf(
            'width="%d" height="%d" viewBox="0 0 24 24" fill="%s" role="img" aria-label="%s"%s',
            $size,
            $size,
            $fill,
            e(ucfirst($slug)),
            $class !== '' ? ' class="' . e($class) . '"' : ''
        );

        // Instagram's mark is officially a corner-to-corner gradient; the
        // <defs> is emitted with the icon so it stays self-contained.
        $defs = $slug === 'instagram'
            ? '<defs><linearGradient id="sa-ig-grad" x1="0%" y1="100%" x2="100%" y2="0%">'
              . '<stop offset="0%" stop-color="#FFD521"/><stop offset="25%" stop-color="#F50000"/>'
              . '<stop offset="60%" stop-color="#B900B4"/><stop offset="100%" stop-color="#7638FA"/>'
              . '</linearGradient></defs>'
            : '';

        return new HtmlString("<svg {$attrs}>{$defs}<path d=\"{$path}\"/></svg>");
    }
}
