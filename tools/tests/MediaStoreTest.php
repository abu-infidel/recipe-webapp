<?php
declare(strict_types=1);

namespace Tools\Tests;

use App\Domain\MediaStore;

/**
 * Uploaded images: the real type is read from the bytes, dimensions are
 * checked before decoding, and what is stored is re-encoded pixels only.
 */
final class MediaStoreTest extends TestCase
{
    public function run(): void {}

    private string $dir = '';

    private function scratch(): string
    {
        $this->dir = sys_get_temp_dir() . '/media-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o777, true);

        return $this->dir;
    }

    private function cleanup(): void
    {
        if ($this->dir !== '') {
            exec('rm -rf ' . escapeshellarg($this->dir));
            $this->dir = '';
        }
    }

    private function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, (int) ($width / 2), $height, (int) imagecolorallocate($image, 200, 80, 30));
        ob_start();
        imagejpeg($image, null, 90);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /**
     * A JPEG carrying an APP1 EXIF segment: Orientation = 6 (rotate 90° CW)
     * and a GPS IFD pointer with a latitude reference, the way a phone writes
     * them. GD cannot write EXIF, so the segment is assembled by hand.
     */
    private function jpegWithExif(int $width, int $height): string
    {
        $entries = [
            pack('nnNnn', 0x0112, 3, 1, 6, 0),        // Orientation, SHORT, 6
            pack('nnNN', 0x8825, 4, 1, 8 + 2 + 2 * 12 + 4), // GPS IFD pointer, LONG
        ];
        $ifd0 = pack('n', count($entries)) . implode('', $entries) . pack('N', 0);
        $gps = pack('n', 1) . pack('nnNa4', 0x0001, 2, 2, "N\0\0\0") . pack('N', 0);   // GPSLatitudeRef "N"
        $tiff = "MM\x00\x2A" . pack('N', 8) . $ifd0 . $gps;
        $app1 = "Exif\x00\x00" . $tiff;
        $segment = "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1;

        $plain = $this->jpeg($width, $height);

        return substr($plain, 0, 2) . $segment . substr($plain, 2);
    }

    private function write(string $name, string $bytes): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $bytes);

        return $path;
    }

    public function testAValidPhotoBecomesWebpVariantsWithoutMetadata(): void
    {
        $this->scratch();
        try {
            $file = $this->write('photo.jpg', $this->jpegWithExif(1200, 800));
            $this->assertSame(6, (int) (@exif_read_data($file)['Orientation'] ?? 0), 'fixture really carries EXIF orientation');

            $prepared = MediaStore::prepare($file);
            $this->assertTrue($prepared['ok'], $prepared['error'] ?? '');
            $this->assertSame([800, 1200], [imagesx($prepared['image']), imagesy($prepared['image'])], 'rotated upright before re-encoding');

            $out = $this->dir . '/out';
            mkdir($out);
            $written = MediaStore::writeVariants($prepared['image'], $out, 'abc');

            $this->assertSame(['abc-480.webp', 'abc-800.webp'], array_column($written, 'name'), 'smaller sizes plus the original width');
            foreach ($written as $variant) {
                $bytes = (string) file_get_contents($out . '/' . $variant['name']);
                $this->assertSame('image/webp', (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes));
                $this->assertStringNotContains('Exif', $bytes, 'EXIF stripped');
            }
            $this->assertSame(720, $written[0]['height'], 'aspect ratio kept: 480 × 720');
        } finally {
            $this->cleanup();
        }
    }

    public function testALargeImageIsCappedAt1600(): void
    {
        $this->scratch();
        try {
            $prepared = MediaStore::prepare($this->write('big.jpg', $this->jpeg(2400, 1200)));
            $out = $this->dir . '/out';
            mkdir($out);
            $written = MediaStore::writeVariants($prepared['image'], $out, 'big');

            $this->assertSame([480, 960, 1600], array_column($written, 'width'));
            $this->assertSame(800, end($written)['height']);
        } finally {
            $this->cleanup();
        }
    }

    public function testTheTypeComesFromTheBytesNotTheName(): void
    {
        $this->scratch();
        try {
            $result = MediaStore::prepare($this->write('shell.jpg', "<?php system(\$_GET['c']); ?>"));
            $this->assertFalse($result['ok']);
            $this->assertStringContains('Only JPEG, PNG, WebP and GIF', $result['error'] ?? '');

            $svg = MediaStore::prepare($this->write('icon.png', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'));
            $this->assertFalse($svg['ok'], 'SVG is not accepted as an image upload');
        } finally {
            $this->cleanup();
        }
    }

    public function testAPolyglotNeverSurvivesReEncoding(): void
    {
        $this->scratch();
        try {
            $image = imagecreatetruecolor(400, 300);
            ob_start();
            imagegif($image);
            $gif = (string) ob_get_clean();
            // A valid GIF with a script appended after the trailer.
            $result = MediaStore::prepare($this->write('poly.gif', $gif . '<?php echo "owned"; ?>'));

            if (!$result['ok']) {
                $this->assertTrue(true);   // refused outright: fine
                return;
            }
            $out = $this->dir . '/out';
            mkdir($out);
            foreach (MediaStore::writeVariants($result['image'], $out, 'poly') as $variant) {
                $this->assertStringNotContains('<?php', (string) file_get_contents($out . '/' . $variant['name']), 'payload gone');
            }
        } finally {
            $this->cleanup();
        }
    }

    public function testDimensionsAreCheckedBeforeDecoding(): void
    {
        $this->scratch();
        try {
            // A 60-byte PNG that declares a 20000 × 20000 canvas. Decoding it
            // would need about 1.6 GB.
            $ihdr = pack('NNCCCCC', 20000, 20000, 8, 2, 0, 0, 0);
            $chunk = static fn(string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
            $png = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', $ihdr) . $chunk('IEND', '');

            $result = MediaStore::prepare($this->write('bomb.png', $png));
            $this->assertFalse($result['ok']);
            $this->assertStringContains('too many pixels', $result['error'] ?? '');
        } finally {
            $this->cleanup();
        }
    }

    public function testTooSmallAndEmptyAreRefused(): void
    {
        $this->scratch();
        try {
            $this->assertStringContains('too small', MediaStore::prepare($this->write('tiny.jpg', $this->jpeg(100, 100)))['error'] ?? '');
            $this->assertStringContains('empty', MediaStore::prepare($this->write('empty.jpg', ''))['error'] ?? '');
            $this->assertStringContains('No file', MediaStore::prepare($this->dir . '/missing.jpg')['error'] ?? '');
        } finally {
            $this->cleanup();
        }
    }

    public function testTransparencySurvives(): void
    {
        $this->scratch();
        try {
            $image = imagecreatetruecolor(400, 300);
            imagesavealpha($image, true);
            imagealphablending($image, false);
            imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
            ob_start();
            imagepng($image);
            $prepared = MediaStore::prepare($this->write('clear.png', (string) ob_get_clean()));

            $out = $this->dir . '/out';
            mkdir($out);
            $written = MediaStore::writeVariants($prepared['image'], $out, 'clear');
            $reloaded = imagecreatefromwebp($out . '/' . end($written)['name']);
            $alpha = (imagecolorat($reloaded, 10, 10) >> 24) & 0x7F;

            $this->assertTrue($alpha > 100, 'a transparent PNG stays transparent, not black');
        } finally {
            $this->cleanup();
        }
    }
}
