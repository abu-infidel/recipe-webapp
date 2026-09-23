<?php
declare(strict_types=1);

namespace App\Domain;

use App\Core\Database;
use App\Core\Paths;

/**
 * Takes an uploaded image and stores it safely, in sizes a phone can afford.
 *
 * Nothing uploaded is served as-is. The file's real type is read from its
 * bytes (never from its name or the browser's claim), its dimensions are
 * checked before any pixel is decoded — a small file can declare a gigantic
 * canvas — and it is then re-encoded from pixels alone. Re-encoding is what
 * strips EXIF, including the GPS position a phone photo carries, and what
 * guarantees the stored file is an image and nothing else.
 *
 * Output: WebP at 480, 960 and 1600 px wide (only sizes smaller than the
 * original, plus the original width when it is under 1600), named
 * <base>-<width>.webp. The largest is the path recorded in `media`;
 * ViewModels builds srcset from the siblings.
 */
final class MediaStore
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const MAX_PIXELS = 40_000_000;
    public const MIN_WIDTH = 200;
    public const WIDTHS = [480, 960, 1600];

    private const DECODERS = [
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png'  => 'imagecreatefrompng',
        'image/webp' => 'imagecreatefromwebp',
        'image/gif'  => 'imagecreatefromgif',
    ];

    /**
     * @param array{kind?:string, alt_fa?:string, caption_fa?:string, is_ai_generated?:bool, attribution?:string, license?:string} $meta
     * @return array{ok:true, id:int, path:string, width:int, height:int}|array{ok:false, error:string}
     */
    public static function store(string $file, array $meta = []): array
    {
        $prepared = self::prepare($file);
        if (!$prepared['ok']) {
            return $prepared;
        }

        $relativeDir = date('Y/m');
        $dir = Paths::media() . '/' . $relativeDir;
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            imagedestroy($prepared['image']);
            return self::fail('The media folder is not writable.');
        }

        $written = self::writeVariants($prepared['image'], $dir, bin2hex(random_bytes(8)));
        imagedestroy($prepared['image']);

        if ($written === []) {
            return self::fail('This server could not encode the image.');
        }

        $main = end($written);
        $path = $relativeDir . '/' . $main['name'];

        $kind = in_array($meta['kind'] ?? '', ['hero', 'inline', 'step', 'ad'], true) ? $meta['kind'] : 'inline';
        $id = Database::insert('media', [
            'path'            => $path,
            'kind'            => $kind,
            'mime'            => $main['mime'],
            'width'           => $main['width'],
            'height'          => $main['height'],
            'bytes'           => $main['bytes'],
            'alt_fa'          => self::clip($meta['alt_fa'] ?? null, 400),
            'caption_fa'      => self::clip($meta['caption_fa'] ?? null, 500),
            'is_ai_generated' => !empty($meta['is_ai_generated']) ? 1 : 0,
            'attribution'     => self::clip($meta['attribution'] ?? null, 400),
            'license'         => self::clip($meta['license'] ?? null, 120),
        ]);

        return ['ok' => true, 'id' => $id, 'path' => $path, 'width' => $main['width'], 'height' => $main['height']];
    }

    /**
     * Validate and decode, without writing anything. Public so the checks
     * can be tested without a database.
     *
     * @return array{ok:true, image:\GdImage, mime:string}|array{ok:false, error:string}
     */
    public static function prepare(string $file): array
    {
        if (!is_file($file) || !is_readable($file)) {
            return self::fail('No file was received.');
        }

        $bytes = (int) filesize($file);
        if ($bytes === 0) {
            return self::fail('The file is empty.');
        }
        if ($bytes > self::MAX_BYTES) {
            return self::fail(sprintf('The image is %.1f MB; the limit is %d MB.', $bytes / 1048576, intdiv(self::MAX_BYTES, 1048576)));
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file);
        if (!isset(self::DECODERS[$mime])) {
            return self::fail('Only JPEG, PNG, WebP and GIF images can be uploaded.');
        }
        if (!function_exists(self::DECODERS[$mime])) {
            return self::fail("This server's PHP cannot read {$mime} images.");
        }

        // Header only: dimensions are known before a pixel is decoded.
        $size = @getimagesize($file);
        if ($size === false || ($size['mime'] ?? '') !== $mime) {
            return self::fail('The file is not a valid image.');
        }
        [$width, $height] = $size;
        if ($width < self::MIN_WIDTH || $height < 50) {
            return self::fail('The image is too small; it should be at least ' . self::MIN_WIDTH . ' pixels wide.');
        }
        if ($width * $height > self::MAX_PIXELS) {
            return self::fail('The image has too many pixels to process on this server.');
        }

        $image = @(self::DECODERS[$mime])($file);
        if (!$image instanceof \GdImage) {
            return self::fail('The image could not be decoded.');
        }

        if ($mime === 'image/jpeg') {
            $image = self::applyOrientation($image, $file);
        }

        return ['ok' => true, 'image' => $image, 'mime' => $mime];
    }

    /**
     * @return list<array{name:string, width:int, height:int, bytes:int, mime:string}> smallest first
     */
    public static function writeVariants(\GdImage $image, string $dir, string $base): array
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $targets = array_filter(self::WIDTHS, static fn(int $w) => $w < $width);
        if ($width <= max(self::WIDTHS)) {
            $targets[] = $width;
        } else {
            $targets[] = max(self::WIDTHS);
        }
        $targets = array_values(array_unique($targets));
        sort($targets);

        $webp = function_exists('imagewebp');
        $extension = $webp ? 'webp' : 'jpg';
        $written = [];

        foreach ($targets as $targetWidth) {
            $targetHeight = max(1, (int) round($height * $targetWidth / $width));
            $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            if (!$webp) {
                // JPEG has no alpha: flatten onto white rather than black.
                imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
                imagealphablending($canvas, true);
            }
            imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

            $name = "{$base}-{$targetWidth}.{$extension}";
            $ok = $webp ? imagewebp($canvas, "{$dir}/{$name}", 80) : imagejpeg($canvas, "{$dir}/{$name}", 82);
            imagedestroy($canvas);

            if ($ok) {
                $written[] = [
                    'name'   => $name,
                    'width'  => $targetWidth,
                    'height' => $targetHeight,
                    'bytes'  => (int) filesize("{$dir}/{$name}"),
                    'mime'   => $webp ? 'image/webp' : 'image/jpeg',
                ];
            }
        }

        return $written;
    }

    /** A phone photo stores "rotate me" in EXIF; re-encoding drops that, so apply it first. */
    private static function applyOrientation(\GdImage $image, string $file): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($file);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => null,
        };

        if ($rotated instanceof \GdImage) {
            imagedestroy($image);
            return $rotated;
        }

        return $image;
    }

    /** @return array<int,array{id:int,path:string,width:?int,height:?int,alt_fa:?string}> */
    public static function byIds(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $id) => $id > 0));
        if ($ids === []) {
            return [];
        }

        [$placeholders, $params] = Database::inClause($ids, 'm');
        $rows = Database::all("SELECT id, path, width, height, alt_fa, caption_fa, is_ai_generated FROM media WHERE id IN ({$placeholders})", $params);

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = $row;
        }

        return $out;
    }

    private static function clip(mixed $value, int $length): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : mb_substr($value, 0, $length, 'UTF-8');
    }

    private static function fail(string $error): array
    {
        return ['ok' => false, 'error' => $error];
    }
}
