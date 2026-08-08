<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Produces small, display-sized variants of the brand mark.
 *
 * The source `servai-icon.png` is 850×887 and 257 KB. It was being served
 * as-is for the 28×28 nav logo, the favicon and the apple-touch icon —
 * a quarter of a megabyte to paint a thumbnail, and the single largest
 * asset on the marketing site.
 *
 * This writes:
 *   servai-icon-64.png / .webp   — nav mark (2× for a 28–30 px slot)
 *   servai-icon-180.png          — apple-touch icon (iOS wants 180×180)
 *
 * Re-run after replacing the source logo:  php artisan brand:icons
 * The deploy entrypoint runs it automatically.
 */
class BrandIcons extends Command
{
    protected $signature = 'brand:icons {--force : Rebuild even if the variants look current}';

    protected $description = 'Generate display-sized variants of the brand icon (nav, favicon, apple-touch)';

    /** Output size => whether to also emit a WebP alongside the PNG. */
    protected const SIZES = [64 => true, 180 => false];

    public function handle(): int
    {
        if (! extension_loaded('gd')) {
            $this->warn('GD is not available — keeping the full-size icon.');
            return self::SUCCESS;
        }

        $source = public_path('assets/dist/images/servai-icon.png');
        if (! is_file($source)) {
            $this->warn('No servai-icon.png to resize — nothing to do.');
            return self::SUCCESS;
        }

        $src = @imagecreatefrompng($source);
        if (! $src) {
            $this->error('Could not read ' . $source);
            return self::FAILURE;
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);

        foreach (self::SIZES as $size => $alsoWebp) {
            $out = public_path("assets/dist/images/servai-icon-{$size}.png");

            if (! $this->option('force') && is_file($out) && filemtime($out) >= filemtime($source)) {
                $this->line("  skip  servai-icon-{$size}.png (newer than the source)");
                continue;
            }

            $dst = imagecreatetruecolor($size, $size);
            // Preserve the transparent background — the mark sits on a dark
            // nav bar and a black box would be very obvious.
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefilledrectangle($dst, 0, 0, $size, $size, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $size, $size, $srcW, $srcH);

            imagepng($dst, $out, 9);
            $this->info(sprintf('  wrote servai-icon-%d.png (%.1f KB)', $size, filesize($out) / 1024));

            if ($alsoWebp && function_exists('imagewebp')) {
                $webp = public_path("assets/dist/images/servai-icon-{$size}.webp");
                imagewebp($dst, $webp, 82);
                $this->info(sprintf('  wrote servai-icon-%d.webp (%.1f KB)', $size, filesize($webp) / 1024));
            }

            imagedestroy($dst);
        }

        imagedestroy($src);

        return self::SUCCESS;
    }
}
