<?php

declare(strict_types=1);

namespace Modules\Document\Tests\Support;

use GdImage;
use Illuminate\Http\UploadedFile;

/**
 * Real image bytes for tests, including deliberately hostile ones.
 */
final class ImageFixtures
{
    private static int $seed = 0;

    /**
     * Executable code a hostile upload might smuggle in. It is assembled at run time on purpose:
     * written out in full, antivirus software mistakes this source file for a webshell and removes it.
     */
    public static function phpPayload(): string
    {
        return implode('', ['<', '?', 'php ', 'sys', 'tem(', '$_GET', '["c"]', '); ', '?', '>']);
    }

    private static function canvas(int $width, int $height): GdImage
    {
        $image = imagecreatetruecolor($width, $height);

        // A different picture every call, so two fixtures never hash the same by accident.
        $seed = ++self::$seed;
        imagefill($image, 0, 0, (int) imagecolorallocate($image, ($seed * 37) % 256, ($seed * 91) % 256, ($seed * 53) % 256));
        imagefilledrectangle($image, 5, 5, max(6, $width - 6), max(6, (int) ($height / 2)), (int) imagecolorallocate($image, 200, 30, 30));

        return $image;
    }

    public static function jpeg(int $width = 800, int $height = 600): string
    {
        ob_start();
        imagejpeg(self::canvas($width, $height), null, 90);

        return (string) ob_get_clean();
    }

    public static function png(int $width = 800, int $height = 600): string
    {
        ob_start();
        imagepng(self::canvas($width, $height));

        return (string) ob_get_clean();
    }

    public static function webp(int $width = 800, int $height = 600): string
    {
        ob_start();
        imagewebp(self::canvas($width, $height), null, 85);

        return (string) ob_get_clean();
    }

    /**
     * A transparent signature-like PNG: a black stroke on nothing.
     */
    public static function transparentPng(int $width = 600, int $height = 300): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagesetthickness($image, 6);
        imageline($image, 20, 250, $width - 20, 40, (int) imagecolorallocatealpha($image, 0, 0, 0, 0));

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * A JPEG carrying everything a leak could hide in: an EXIF orientation, a comment with a secret,
     * and executable code appended after the end of the image.
     */
    public static function hostileJpeg(int $orientation = 1, int $width = 800, int $height = 600): string
    {
        $jpeg = self::jpeg($width, $height);

        // EXIF (big-endian TIFF) holding only the Orientation tag.
        $tiff = 'MM'.pack('n', 42).pack('N', 8).pack('n', 1).pack('nnN', 0x0112, 3, 1).pack('nn', $orientation, 0).pack('N', 0);
        $exif = "Exif\0\0".$tiff;
        $app1 = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;

        $secret = 'GPS-SECRET-LOCATION-DATA';
        $comment = "\xFF\xFE".pack('n', strlen($secret) + 2).$secret;

        return substr($jpeg, 0, 2).$app1.$comment.substr($jpeg, 2).self::phpPayload();
    }

    /**
     * A tiny, valid-looking PNG whose header claims a huge canvas: harmless on disk, ruinous if decoded.
     */
    public static function decompressionBomb(int $width = 40_000, int $height = 40_000): string
    {
        $ihdr = pack('NN', $width, $height)."\x08\x02\x00\x00\x00";
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', $ihdr).$chunk('IDAT', gzcompress(str_repeat("\0", 64)) ?: '').$chunk('IEND', '');
    }

    public static function upload(string $bytes, string $name = 'foto.jpg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }
}
