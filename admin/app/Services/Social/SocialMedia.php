<?php

namespace App\Services\Social;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores media for Social Posts on the public disk, where Meta downloads it.
 *
 * Images are re-encoded to JPEG on the way in. Instagram's publishing API
 * accepts JPEG and nothing else — a PNG fails at publish time with a vague
 * "media type not supported", hours after the person scheduled it. Converting
 * here also bakes in the EXIF rotation, without which a portrait phone photo
 * is published sideways (Meta ignores the orientation tag).
 */
class SocialMedia
{
    /** Longest edge we keep. Instagram serves 1080px; this leaves headroom. */
    private const MAX_EDGE = 2160;

    /** @return array{path:string, type:string, mime:string, size:int} */
    public function store(UploadedFile $file, int $projectId): array
    {
        $dir  = "social/{$projectId}";
        $mime = (string) $file->getMimeType();

        if (str_starts_with($mime, 'video/')) {
            $ext  = $mime === 'video/quicktime' ? 'mov' : 'mp4';
            $path = $file->storeAs($dir, Str::random(32) . '.' . $ext, 'public');

            return ['path' => $path, 'type' => 'video', 'mime' => $mime, 'size' => (int) $file->getSize()];
        }

        $jpeg = $this->toJpeg($file);
        $path = $dir . '/' . Str::random(32) . '.jpg';
        Storage::disk('public')->put($path, $jpeg);

        return ['path' => $path, 'type' => 'image', 'mime' => 'image/jpeg', 'size' => strlen($jpeg)];
    }

    public function delete(array $items): void
    {
        $paths = array_values(array_filter(array_map(fn ($m) => $m['path'] ?? null, $items)));
        if ($paths) {
            Storage::disk('public')->delete($paths);
        }
    }

    private function toJpeg(UploadedFile $file): string
    {
        $bytes = (string) file_get_contents($file->getRealPath());
        $src   = @imagecreatefromstring($bytes);
        if (! $src) {
            throw new \RuntimeException('That image could not be read. Use a JPG, PNG or WebP file.');
        }

        $src = $this->applyOrientation($src, $file->getRealPath());

        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, self::MAX_EDGE / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        // White canvas: JPEG has no alpha, and a transparent PNG logo would
        // otherwise come out on black.
        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        imagejpeg($dst, null, 88);
        $out = (string) ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        return $out;
    }

    private function applyOrientation(\GdImage $img, string $path): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data($path);
        $o = (int) ($exif['Orientation'] ?? 1);

        $rotated = match ($o) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => null,
        };

        return $rotated ?: $img;
    }
}
