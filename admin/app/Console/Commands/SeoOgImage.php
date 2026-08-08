<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Renders the 1200×630 social-share card used for og:image / twitter:image.
 *
 * Without one, Facebook, LinkedIn, WhatsApp and X fall back to the square
 * brand icon, which they letterbox or crop into something unreadable — so
 * every shared link looks broken. `twitter:card` is set to
 * summary_large_image, which explicitly expects a 2:1-ish image.
 *
 * Generated rather than hand-designed so it stays in sync with the brand
 * name and tagline in config/site.php. Re-run after changing either:
 *
 *   php artisan seo:og-image
 *
 * A designer-made card is better; drop it in as og_image in /admin/seo and
 * this file stops being used.
 */
class SeoOgImage extends Command
{
    protected $signature = 'seo:og-image {--path=assets/dist/images/og-cover.png : Output path under public/}';

    protected $description = 'Generate the 1200x630 Open Graph share image from the brand name + tagline';

    public function handle(): int
    {
        if (! extension_loaded('gd')) {
            $this->error('The GD extension is not available — install it, or upload a card as og_image in /admin/seo.');
            return self::FAILURE;
        }

        $w = 1200;
        $h = 630;
        $img = imagecreatetruecolor($w, $h);
        imageantialias($img, true);

        // Background: the same near-black the site uses, with a blue glow
        // in the top-left so the card is recognisably ours at thumbnail size.
        $bg = imagecolorallocate($img, 5, 6, 9);
        imagefilledrectangle($img, 0, 0, $w, $h, $bg);
        for ($r = 900; $r > 0; $r -= 6) {
            $t = $r / 900;
            $c = imagecolorallocatealpha(
                $img,
                (int) (13 + 30 * (1 - $t)),
                (int) (26 + 60 * (1 - $t)),
                (int) (46 + 110 * (1 - $t)),
                (int) (110 * $t)
            );
            imagefilledellipse($img, 220, 60, $r, $r, $c);
        }

        // Accent rule down the left edge.
        $accent = imagecolorallocate($img, 59, 130, 246);
        imagefilledrectangle($img, 0, 0, 10, $h, $accent);

        $font = $this->font();
        if (! $font) {
            $this->error('No TrueType font found under public/assets/dist/fonts — cannot render text.');
            imagedestroy($img);
            return self::FAILURE;
        }

        $white = imagecolorallocate($img, 235, 241, 247);
        $dim   = imagecolorallocate($img, 139, 150, 168);

        $brand   = (string) tva_setting('content.brand_name', 'Serve AI');
        $tagline = (string) tva_setting('content.hero_title', 'Your AI receptionist that')
                 . ' ' . (string) tva_setting('content.hero_title_accent', 'never sleeps.');
        $sub     = (string) tva_setting('seo.meta_description', '');

        // Brand logo, when a raster one exists.
        $x = 90;
        $logo = public_path('assets/dist/images/servai-icon.png');
        if (is_file($logo) && ($mark = @imagecreatefrompng($logo))) {
            imagealphablending($img, true);
            imagecopyresampled($img, $mark, $x, 78, 0, 0, 72, 72, imagesx($mark), imagesy($mark));
            imagedestroy($mark);
            $x += 92;
        }
        imagettftext($img, 34, 0, $x, 128, $white, $font, $brand);

        $this->wrap($img, $font, 56, 90, 260, $w - 180, $white, $tagline, 74);
        $this->wrap($img, $font, 24, 90, 470, $w - 180, $dim, $sub, 38, 2);

        imagettftext($img, 22, 0, 90, 570, $accent, $font, rtrim(preg_replace('#^https?://#', '', \App\Support\Seo::origin()), '/'));

        $out = public_path(ltrim((string) $this->option('path'), '/'));
        @mkdir(dirname($out), 0775, true);
        imagepng($img, $out, 6);
        imagedestroy($img);

        $this->info('Wrote ' . $out . ' (' . $w . '×' . $h . ')');
        $this->line('Set it as og_image in /admin/seo, or leave blank — the head partial picks it up automatically.');

        return self::SUCCESS;
    }

    /** First usable TrueType font shipped with the app. */
    protected function font(): ?string
    {
        foreach ([
            'assets/dist/fonts/roboto/Roboto-Bold.ttf',
            'assets/dist/fonts/roboto/Roboto-Regular.ttf',
            'assets/dist/fonts/roboto/Roboto-Black.ttf',
        ] as $rel) {
            if (is_file(public_path($rel))) {
                return public_path($rel);
            }
        }

        return null;
    }

    /** Word-wrapped imagettftext, returning the y of the last line drawn. */
    protected function wrap($img, string $font, int $size, int $x, int $y, int $maxWidth, int $colour, string $text, int $lineHeight, int $maxLines = 3): int
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $line  = '';
        $lines = [];

        foreach ($words as $word) {
            $try = $line === '' ? $word : $line . ' ' . $word;
            $box = imagettfbbox($size, 0, $font, $try);
            if (($box[2] - $box[0]) > $maxWidth && $line !== '') {
                $lines[] = $line;
                $line = $word;
                if (count($lines) >= $maxLines) {
                    break;
                }
            } else {
                $line = $try;
            }
        }
        if ($line !== '' && count($lines) < $maxLines) {
            $lines[] = $line;
        }

        foreach ($lines as $i => $l) {
            imagettftext($img, $size, 0, $x, $y + $i * $lineHeight, $colour, $font, $l);
        }

        return $y + (count($lines) - 1) * $lineHeight;
    }
}
