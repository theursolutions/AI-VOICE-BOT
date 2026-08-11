<?php

namespace App\Console\Commands;

use App\Models\BlogPost;
use Illuminate\Console\Command;

/**
 * Generates a branded 1200×675 cover card for any article that has none.
 *
 * These are DESIGNED CARDS, not photographs and not diffusion-model art:
 * the article's title and category set in the brand palette, over a gradient
 * derived from the slug so every post gets a distinct but on-brand image.
 *
 * Why this rather than stock photography: a generic photo of a smiling
 * person in a headset tells the reader nothing and makes a technical article
 * look like an advert. A title card is honest about what it is, is legible
 * at thumbnail size in a card grid, and never misrepresents anything as
 * evidence — which matters when the articles cite research.
 *
 * Replaceable at any time: upload a real image in /admin/blog and this
 * command leaves that post alone.
 *
 *   php artisan blog:covers            # fill in the gaps
 *   php artisan blog:covers --force    # regenerate everything
 *   php artisan blog:covers --slug=x   # just one
 */
class BlogCovers extends Command
{
    protected $signature = 'blog:covers
                            {--force : Regenerate covers that already exist}
                            {--slug= : Only this article}';

    protected $description = 'Generate branded cover images for blog articles that have none';

    private const W = 1200;
    private const H = 675;          // 16:9, matching the card and article aspect

    public function handle(): int
    {
        if (! extension_loaded('gd')) {
            $this->warn('GD is not available — articles will fall back to the gradient placeholder.');
            return self::SUCCESS;
        }

        $font = $this->font();
        if (! $font) {
            $this->error('No TrueType font under public/assets/dist/fonts — cannot render text.');
            return self::FAILURE;
        }

        $posts = BlogPost::query()
            ->when($this->option('slug'), fn ($q) => $q->where('slug', $this->option('slug')))
            ->get();

        if ($posts->isEmpty()) {
            $this->line('  no articles found.');
            return self::SUCCESS;
        }

        $made = $kept = 0;

        foreach ($posts as $post) {
            $rel = 'assets/dist/images/blog/' . $post->slug . '.png';

            // Never clobber a real image someone uploaded.
            $isGenerated = str_contains((string) $post->cover_url, '/assets/dist/images/blog/');
            if (trim((string) $post->cover_url) !== '' && ! $isGenerated) {
                $this->line("  keep   {$post->slug} — has an uploaded cover");
                $kept++;
                continue;
            }
            if (is_file(public_path($rel)) && ! $this->option('force')) {
                // Make sure the post points at it, then move on.
                if ($post->cover_url !== '/' . $rel) {
                    $post->cover_url = '/' . $rel;
                    $post->save();
                }
                $this->line("  keep   {$post->slug} — cover already generated");
                $kept++;
                continue;
            }

            $this->render($post, $font, public_path($rel));

            $post->cover_url = '/' . $rel;
            if (trim((string) $post->cover_alt) === '') {
                $post->cover_alt = $post->title;
            }
            $post->save();

            $this->info(sprintf('  cover  %s  (%.0f KB)', $post->slug, filesize(public_path($rel)) / 1024));
            $made++;
        }

        $this->newLine();
        $this->line("{$made} cover(s) generated, {$kept} left alone.");

        return self::SUCCESS;
    }

    private function render(BlogPost $post, string $font, string $out): void
    {
        @mkdir(dirname($out), 0775, true);

        $img = imagecreatetruecolor(self::W, self::H);
        imageantialias($img, true);

        // Base: the site's near-black.
        imagefilledrectangle($img, 0, 0, self::W, self::H, imagecolorallocate($img, 5, 6, 9));

        // Hue derived from the slug, so each article is visually distinct but
        // the whole grid still reads as one publication. Matches the CSS
        // placeholder in blog/_cover.blade.php, which uses the same crc32.
        $hue = crc32($post->slug) % 360;
        [$r, $g, $b] = $this->hsl($hue, 0.55, 0.22);

        // Soft radial glow, top-left — the same visual language as the site's
        // hero sections.
        for ($rad = 1500; $rad > 0; $rad -= 8) {
            $t = $rad / 1500;
            $c = imagecolorallocatealpha(
                $img,
                (int) ($r * (1 - $t) + 5 * $t),
                (int) ($g * (1 - $t) + 6 * $t),
                (int) ($b * (1 - $t) + 9 * $t),
                (int) (108 * $t),
            );
            imagefilledellipse($img, 300, 120, $rad, $rad, $c);
        }

        // Accent rule down the left edge.
        imagefilledrectangle($img, 0, 0, 12, self::H, imagecolorallocate($img, 59, 130, 246));

        $white  = imagecolorallocate($img, 240, 246, 252);
        $dim    = imagecolorallocate($img, 150, 165, 185);
        $accent = imagecolorallocate($img, 96, 165, 250);

        $x = 86;

        // Brand mark + name.
        $logo = public_path('assets/dist/images/servai-icon.png');
        $brandX = $x;
        if (is_file($logo) && ($mark = @imagecreatefrompng($logo))) {
            imagealphablending($img, true);
            imagecopyresampled($img, $mark, $brandX, 70, 0, 0, 46, 46, imagesx($mark), imagesy($mark));
            imagedestroy($mark);
            $brandX += 60;
        }
        imagettftext($img, 22, 0, $brandX, 103, $white, $font, (string) tva_setting('content.brand_name', 'Serve AI'));

        // Category, as an eyebrow above the title.
        if (trim((string) $post->category) !== '') {
            imagettftext($img, 17, 0, $x, 210, $accent, $font, mb_strtoupper($post->category));
        }

        // Title — the actual subject of the image.
        $lastY = $this->wrap($img, $font, 46, $x, 275, self::W - 170, $white, $post->title, 62, 4);

        // Reading time, bottom-left.
        imagettftext($img, 19, 0, $x, min($lastY + 90, self::H - 60), $dim, $font, $post->reading_time . ' min read');

        imagepng($img, $out, 6);
        imagedestroy($img);
    }

    /** HSL → RGB, so the slug-derived hue produces an on-brand colour. */
    private function hsl(float $h, float $s, float $l): array
    {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;

        [$r, $g, $b] = match (true) {
            $h < 60  => [$c, $x, 0],
            $h < 120 => [$x, $c, 0],
            $h < 180 => [0, $c, $x],
            $h < 240 => [0, $x, $c],
            $h < 300 => [$x, 0, $c],
            default  => [$c, 0, $x],
        };

        return [(int) (($r + $m) * 255), (int) (($g + $m) * 255), (int) (($b + $m) * 255)];
    }

    /** Word-wrapped text; returns the y of the last line drawn. */
    private function wrap($img, string $font, int $size, int $x, int $y, int $maxWidth, int $colour, string $text, int $lineHeight, int $maxLines): int
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $line  = '';

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

        return $y + (max(1, count($lines)) - 1) * $lineHeight;
    }

    private function font(): ?string
    {
        foreach ([
            'assets/dist/fonts/roboto/Roboto-Bold.ttf',
            'assets/dist/fonts/roboto/Roboto-Black.ttf',
            'assets/dist/fonts/roboto/Roboto-Regular.ttf',
        ] as $rel) {
            if (is_file(public_path($rel))) {
                return public_path($rel);
            }
        }

        return null;
    }
}
