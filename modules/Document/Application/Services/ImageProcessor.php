<?php

declare(strict_types=1);

namespace Modules\Document\Application\Services;

use finfo;
use GdImage;
use Illuminate\Validation\ValidationException;
use Modules\Document\Application\DTOs\ProcessedImage;
use Modules\Document\Domain\Enums\AttachmentKind;

/**
 * Turns whatever a phone uploaded into a clean image we generated ourselves.
 *
 * The upload is never trusted and never stored: its type is judged from its content (not its name
 * or the type the client claims), its size is checked from the header before it is decoded, and then
 * it is decoded and re-encoded. Anything hidden in the original, such as EXIF data, an embedded
 * script, or bytes appended after the image, does not survive that.
 */
final class ImageProcessor
{
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    public function process(string $bytes, AttachmentKind $kind): ProcessedImage
    {
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if (! in_array($mime, self::ALLOWED_MIME, true)) {
            throw $this->fail('Hanya gambar JPG, PNG, atau WebP yang diterima.');
        }

        $header = @getimagesizefromstring($bytes);

        if ($header === false) {
            throw $this->fail('File gambar rusak atau tidak dapat dibaca.');
        }

        [$width, $height] = $header;

        $this->assertDimensions($width, $height);
        $this->assertFitsInMemory($width, $height);

        $image = @imagecreatefromstring($bytes);

        if (! $image instanceof GdImage) {
            throw $this->fail('File gambar rusak atau tidak dapat dibaca.');
        }

        if ($mime === 'image/jpeg') {
            $image = $this->applyOrientation($image, $bytes);
        }

        $isSignature = $kind->isSignature();
        $image = $isSignature ? $this->keepTransparency($image) : $this->flattenOnWhite($image);

        $image = $this->shrink($image, $isSignature ? (int) config('documents.signature_max_width') : (int) config('documents.photo_max_edge'), $isSignature);

        $thumbnail = $this->shrink($isSignature ? $this->flattenOnWhite($image) : $image, (int) config('documents.thumbnail_edge'), false);

        return new ProcessedImage(
            bytes: $isSignature ? $this->encodePng($image) : $this->encodeJpeg($image, (int) config('documents.photo_quality')),
            mime: $isSignature ? 'image/png' : 'image/jpeg',
            extension: $isSignature ? 'png' : 'jpg',
            width: imagesx($image),
            height: imagesy($image),
            thumbnailBytes: $this->encodeJpeg($thumbnail, (int) config('documents.thumbnail_quality')),
        );
    }

    /**
     * A profile picture: the same distrust of the upload as any other image, then cropped to a centred
     * square and shrunk, so every avatar is small, the same shape, and free of anything hidden in the original.
     */
    public function avatar(string $bytes, int $edge = 512): ProcessedImage
    {
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if (! in_array($mime, self::ALLOWED_MIME, true)) {
            throw $this->fail('Hanya gambar JPG, PNG, atau WebP yang diterima.');
        }

        $header = @getimagesizefromstring($bytes);

        if ($header === false) {
            throw $this->fail('File gambar rusak atau tidak dapat dibaca.');
        }

        [$width, $height] = $header;

        $this->assertDimensions($width, $height);
        $this->assertFitsInMemory($width, $height);

        $image = @imagecreatefromstring($bytes);

        if (! $image instanceof GdImage) {
            throw $this->fail('File gambar rusak atau tidak dapat dibaca.');
        }

        if ($mime === 'image/jpeg') {
            $image = $this->applyOrientation($image, $bytes);
        }

        $image = $this->squareOf($this->flattenOnWhite($image), $edge);
        $thumbnail = $this->squareOf($image, 96);

        return new ProcessedImage(
            bytes: $this->encodeJpeg($image, (int) config('documents.photo_quality')),
            mime: 'image/jpeg',
            extension: 'jpg',
            width: imagesx($image),
            height: imagesy($image),
            thumbnailBytes: $this->encodeJpeg($thumbnail, (int) config('documents.thumbnail_quality')),
        );
    }

    private function squareOf(GdImage $image, int $edge): GdImage
    {
        $side = min(imagesx($image), imagesy($image));
        $cropped = imagecrop($image, ['x' => intdiv(imagesx($image) - $side, 2), 'y' => intdiv(imagesy($image) - $side, 2), 'width' => $side, 'height' => $side]);

        if (! $cropped instanceof GdImage) {
            return $image;
        }

        if ($side <= $edge) {
            return $cropped;
        }

        $scaled = imagescale($cropped, $edge, $edge, IMG_BICUBIC);

        return $scaled instanceof GdImage ? $scaled : $cropped;
    }

    /**
     * Judged from the header, before a single pixel is decoded, so a tiny file that claims to be
     * enormous cannot exhaust memory.
     */
    private function assertDimensions(int $width, int $height): void
    {
        $min = (int) config('documents.min_image_edge');
        $max = (int) config('documents.max_image_edge');

        if ($width < $min || $height < $min) {
            throw $this->fail("Gambar terlalu kecil (minimal {$min} piksel pada sisi terpendek).");
        }

        if ($width > $max || $height > $max || $width * $height > (int) config('documents.max_image_pixels')) {
            throw $this->fail('Resolusi gambar terlalu besar. Perkecil gambar sebelum mengunggah.');
        }
    }

    /**
     * Decoding needs roughly four bytes a pixel plus a working copy; refuse what this process cannot hold.
     */
    private function assertFitsInMemory(int $width, int $height): void
    {
        $limit = $this->memoryLimit();

        if ($limit === null) {
            return;
        }

        if (memory_get_usage() + ($width * $height * 5) + 8_000_000 > $limit) {
            throw $this->fail('Resolusi gambar terlalu besar. Perkecil gambar sebelum mengunggah.');
        }
    }

    private function memoryLimit(): ?int
    {
        $value = trim((string) ini_get('memory_limit'));

        if ($value === '' || $value === '-1') {
            return null;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * Phones store the camera's rotation in EXIF instead of rotating the pixels. We drop EXIF,
     * so the rotation has to be applied to the pixels first or photos would come out sideways.
     */
    private function applyOrientation(GdImage $image, string $bytes): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $angle = match ($orientation) {
            3 => 180,
            6 => 270,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        return $rotated instanceof GdImage ? $rotated : $image;
    }

    private function flattenOnWhite(GdImage $image): GdImage
    {
        $canvas = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        return $canvas;
    }

    private function keepTransparency(GdImage $image): GdImage
    {
        $canvas = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 255, 255, 255, 127));
        imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        return $canvas;
    }

    private function shrink(GdImage $image, int $maxEdge, bool $keepAlpha): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if (max($width, $height) <= $maxEdge) {
            return $image;
        }

        $scaled = $width >= $height
            ? imagescale($image, $maxEdge, -1, IMG_BICUBIC)
            : imagescale($image, (int) round($width * $maxEdge / $height), $maxEdge, IMG_BICUBIC);

        if (! $scaled instanceof GdImage) {
            return $image;
        }

        if ($keepAlpha) {
            imagealphablending($scaled, false);
            imagesavealpha($scaled, true);
        }

        return $scaled;
    }

    private function encodeJpeg(GdImage $image, int $quality): string
    {
        ob_start();
        imagejpeg($image, null, $quality);

        return (string) ob_get_clean();
    }

    private function encodePng(GdImage $image): string
    {
        imagesavealpha($image, true);

        ob_start();
        imagepng($image, null, 6);

        return (string) ob_get_clean();
    }

    private function fail(string $message): ValidationException
    {
        return ValidationException::withMessages(['file' => $message]);
    }
}
