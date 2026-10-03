<?php
/**
 * GH#142 (Phase 155) - shared fixtures for the branding tests: REAL images
 * and REAL hostile files, generated here with GD and hand-built bytes, so the
 * upload pipeline is exercised with the shapes an attacker (or a phone camera)
 * actually produces and never with a hand-seeded "ideal" row.
 *
 * Leading underscore so tools/test_all.php's `test_*.php` glob does not try to
 * run this as a test file (same convention as tests/_test_admin.php).
 */

if (!function_exists('gh142_png')) {

    /** A small real PNG (RGBA, with a transparent corner) built by GD. */
    function gh142_png(int $w = 120, int $h = 60, bool $alpha = true): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        $clear = imagecolorallocatealpha($im, 0, 0, 0, 127);
        imagefilledrectangle($im, 0, 0, $w, $h, $clear);
        $blue = imagecolorallocate($im, 20, 90, 200);
        imagefilledrectangle($im, (int) ($w * 0.1), (int) ($h * 0.15), (int) ($w * 0.9), (int) ($h * 0.85), $blue);
        $white = imagecolorallocate($im, 255, 255, 255);
        imagefilledellipse($im, (int) ($w / 2), (int) ($h / 2), (int) ($h * 0.5), (int) ($h * 0.5), $white);
        if (!$alpha) {
            $bg = imagecreatetruecolor($w, $h);
            imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
            imagecopy($bg, $im, 0, 0, 0, 0, $w, $h);
            $im = $bg;
        }
        ob_start();
        imagepng($im);
        return (string) ob_get_clean();
    }

    /** A real JPEG built by GD. */
    function gh142_jpeg(int $w = 120, int $h = 60): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 240, 240, 240));
        imagefilledrectangle($im, 5, 5, $w - 6, $h - 6, imagecolorallocate($im, 200, 40, 40));
        ob_start();
        imagejpeg($im, null, 90);
        return (string) ob_get_clean();
    }

    /** A real palette PNG with a transparent index (the classic logo shape). */
    function gh142_palette_png(int $w = 80, int $h = 40): string
    {
        $im = imagecreate($w, $h);
        $bg = imagecolorallocate($im, 255, 0, 255);
        imagecolortransparent($im, $bg);
        imagefilledrectangle($im, 10, 5, $w - 10, $h - 5, imagecolorallocate($im, 0, 128, 0));
        ob_start();
        imagepng($im);
        return (string) ob_get_clean();
    }

    /** A real GIF (must be refused: GIF is not accepted). */
    function gh142_gif(): string
    {
        $im = imagecreate(40, 20);
        imagecolorallocate($im, 255, 255, 255);
        ob_start();
        imagegif($im);
        return (string) ob_get_clean();
    }

    /** A real WebP, or null when this GD cannot write one. */
    function gh142_webp(): ?string
    {
        if (!function_exists('imagewebp')) return null;
        $im = imagecreatetruecolor(60, 30);
        imagefill($im, 0, 0, imagecolorallocate($im, 10, 120, 60));
        ob_start();
        imagewebp($im, null, 80);
        $b = (string) ob_get_clean();
        return $b === '' ? null : $b;
    }

    /** One PNG chunk, with a correct CRC. */
    function gh142_png_chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    /** Insert a chunk immediately after IHDR. */
    function gh142_png_insert_after_ihdr(string $png, string $type, string $data): string
    {
        $ihdrEnd = 8 + 12 + 13;   // signature + IHDR chunk (len+type+13+crc)
        return substr($png, 0, $ihdrEnd) . gh142_png_chunk($type, $data) . substr($png, $ihdrEnd);
    }

    /** A valid PNG that ALSO carries a tEXt metadata chunk and a PHP/HTML trailer. */
    function gh142_png_polyglot(string $png, string $marker = 'GH142-META-MARKER'): string
    {
        $with = gh142_png_insert_after_ihdr($png, 'tEXt', "Comment\0" . $marker);
        return $with . "<?php echo 'GH142-PWNED'; ?><script>alert('GH142-XSS')</script>";
    }

    /** An animated PNG: a valid PNG with an acTL chunk. */
    function gh142_apng(string $png): string
    {
        return gh142_png_insert_after_ihdr($png, 'acTL', pack('N', 2) . pack('N', 0));
    }

    /**
     * A header-only decompression bomb: a structurally valid PNG whose IHDR
     * CLAIMS $w x $h but whose body is a few bytes. Anything that decodes it
     * would allocate gigabytes; the header guard must refuse it first.
     */
    function gh142_png_bomb(int $w = 30000, int $h = 30000): string
    {
        $ihdr = pack('N', $w) . pack('N', $h) . "\x08\x02\x00\x00\x00";
        return "\x89PNG\r\n\x1a\n"
            . gh142_png_chunk('IHDR', $ihdr)
            . gh142_png_chunk('IDAT', gzcompress(str_repeat("\0", 64)))
            . gh142_png_chunk('IEND', '');
    }

    /** A JPEG with a real EXIF GPS block (latitude 44 53 12.34 N) and a COM marker. */
    function gh142_jpeg_with_exif_gps(string $jpeg, string $marker = 'GH142-EXIF-MARKER'): string
    {
        $tiff  = "II" . pack('v', 42) . pack('V', 8);
        $tiff .= pack('v', 1) . pack('v', 0x8825) . pack('v', 4) . pack('V', 1) . pack('V', 26) . pack('V', 0);
        $tiff .= pack('v', 2)
              .  pack('v', 1) . pack('v', 2) . pack('V', 2) . "N\0\0\0"
              .  pack('v', 2) . pack('v', 5) . pack('V', 3) . pack('V', 56)
              .  pack('V', 0);
        $tiff .= pack('V', 44) . pack('V', 1) . pack('V', 53) . pack('V', 1) . pack('V', 123456) . pack('V', 10000);
        $app1 = "Exif\0\0" . $tiff . $marker;
        $seg  = "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1;
        $com  = "\xFF\xFE" . pack('n', strlen($marker) + 2 + 4) . 'COM-' . $marker;
        return substr($jpeg, 0, 2) . $seg . $com . substr($jpeg, 2);
    }

    /** A noisy RGB PNG that does not compress: used to force the stored-size cap. */
    function gh142_noise_png(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        mt_srand(142);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                imagesetpixel($im, $x, $y, imagecolorallocate_fast($im));
            }
        }
        ob_start();
        imagepng($im, null, 1);
        return (string) ob_get_clean();
    }

    /** Cheap random color for the noise image (truecolor: no palette limit). */
    function imagecolorallocate_fast($im): int
    {
        return (mt_rand(0, 255) << 16) | (mt_rand(0, 255) << 8) | mt_rand(0, 255);
    }

    /** An SVG, the thing that must never be stored. */
    function gh142_svg(): string
    {
        return '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'
            . '<script>alert(1)</script><rect width="10" height="10"/></svg>';
    }

    /** A VP8X WebP that claims animation (flag bit + an ANIM chunk). */
    function gh142_animated_webp(): string
    {
        $vp8x = 'VP8X' . pack('V', 10) . "\x02\x00\x00\x00" . substr(pack('V', 99), 0, 3) . substr(pack('V', 99), 0, 3);
        $anim = 'ANIM' . pack('V', 6) . "\x00\x00\x00\x00\x00\x00";
        $body = 'WEBP' . $vp8x . $anim;
        return 'RIFF' . pack('V', strlen($body)) . $body;
    }

    /** Does a binary string contain a needle? (byte-wise, never regex) */
    function gh142_contains(string $hay, string $needle): bool
    {
        return strpos($hay, $needle) !== false;
    }

    /** The EXIF GPS tag present in $bytes, via the exif extension when available. */
    function gh142_has_gps(string $bytes): ?bool
    {
        if (!function_exists('exif_read_data')) return null;
        $data = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes), 'GPS', true);
        return is_array($data) && !empty($data['GPS']);
    }
}
