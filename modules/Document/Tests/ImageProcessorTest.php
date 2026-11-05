<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Modules\Document\Application\Services\ImageProcessor;
use Modules\Document\Domain\Enums\AttachmentKind;
use Modules\Document\Tests\Support\ImageFixtures as Img;

function processor(): ImageProcessor
{
    return app(ImageProcessor::class);
}

describe('what it accepts', function () {
    it('turns a photo of any allowed format into a clean JPEG', function (string $format) {
        $bytes = match ($format) {
            'jpeg' => Img::jpeg(),
            'png' => Img::png(),
            'webp' => Img::webp(),
        };

        $result = processor()->process($bytes, AttachmentKind::PhotoBefore);

        expect($result->mime)->toBe('image/jpeg')
            ->and($result->extension)->toBe('jpg')
            ->and(substr($result->bytes, 0, 3))->toBe("\xFF\xD8\xFF")
            ->and([$result->width, $result->height])->toBe([800, 600]);
    })->with(['jpeg', 'png', 'webp']);

    it('shrinks a large photo to the configured edge and keeps its proportions', function () {
        $result = processor()->process(Img::jpeg(3200, 2400), AttachmentKind::PhotoAfter);

        expect($result->width)->toBe(2560)
            ->and($result->height)->toBe(1920);
    });

    it('shrinks a tall photo by its height', function () {
        $result = processor()->process(Img::jpeg(2400, 3200), AttachmentKind::PhotoAfter);

        expect([$result->width, $result->height])->toBe([1920, 2560]);
    });

    it('leaves a photo that is already small alone', function () {
        $result = processor()->process(Img::jpeg(1200, 900), AttachmentKind::PhotoEvidence);

        expect([$result->width, $result->height])->toBe([1200, 900]);
    });

    it('makes a thumbnail that is much smaller than the photo', function () {
        $result = processor()->process(Img::jpeg(2000, 1500), AttachmentKind::PhotoBefore);
        $thumb = getimagesizefromstring($result->thumbnailBytes);

        expect(max($thumb[0], $thumb[1]))->toBe(400)
            ->and(strlen($result->thumbnailBytes))->toBeLessThan(strlen($result->bytes));
    });

    it('keeps a signature as a PNG and keeps its transparency', function () {
        $result = processor()->process(Img::transparentPng(), AttachmentKind::SignatureMerchant);
        $image = imagecreatefromstring($result->bytes);

        expect($result->mime)->toBe('image/png')
            ->and($result->extension)->toBe('png');

        // The corner was empty in the original and must still be see-through.
        $corner = imagecolorat($image, 2, 2);

        expect(($corner >> 24) & 0x7F)->toBeGreaterThan(100);
    });

    it('limits the width of a signature', function () {
        $result = processor()->process(Img::transparentPng(2000, 800), AttachmentKind::SignatureTechnician);

        expect($result->width)->toBe(1024);
    });
});

describe('what it removes', function () {
    it('drops EXIF, comments and anything appended after the image', function () {
        $hostile = Img::hostileJpeg();

        // Prove the fixture really carries the payload before claiming it is gone.
        expect($hostile)->toContain('GPS-SECRET-LOCATION-DATA')->toContain(Img::phpPayload())->toContain('Exif');

        $clean = processor()->process($hostile, AttachmentKind::PhotoBefore)->bytes;

        expect($clean)->not->toContain('GPS-SECRET-LOCATION-DATA')
            ->and($clean)->not->toContain(Img::phpPayload())
            ->and($clean)->not->toContain('system(')
            ->and($clean)->not->toContain('Exif');
    });

    it('applies the camera rotation to the pixels before dropping it', function (int $orientation, bool $swapsSides) {
        $result = processor()->process(Img::hostileJpeg($orientation, 800, 600), AttachmentKind::PhotoBefore);

        expect([$result->width, $result->height])->toBe($swapsSides ? [600, 800] : [800, 600]);
    })->with([
        'upright' => [1, false],
        'upside down' => [3, false],
        'rotated right' => [6, true],
        'rotated left' => [8, true],
    ]);

    it('produces a file that is itself a plain image with no orientation left', function () {
        $clean = processor()->process(Img::hostileJpeg(6), AttachmentKind::PhotoBefore)->bytes;

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($clean));

        expect($exif === false || ! isset($exif['Orientation']))->toBeTrue();
    });
});

describe('what it refuses', function () {
    it('rejects files that are not images, whatever they are called', function (string|Closure $content) {
        processor()->process($content instanceof Closure ? $content() : $content, AttachmentKind::PhotoBefore);
    })->with([
        'plain text' => ["just some text, not a picture\n"],
        'a script' => [fn () => Img::phpPayload()],
        'html' => ['<html><script>alert(1)</script></html>'],
        'svg' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
        'a pdf' => ["%PDF-1.4\n1 0 obj\n<< >>\nendobj\n"],
        'empty' => [''],
    ])->throws(ValidationException::class, 'JPG, PNG, atau WebP');

    it('rejects a GIF', function () {
        ob_start();
        imagegif(imagecreatetruecolor(300, 300));

        processor()->process((string) ob_get_clean(), AttachmentKind::PhotoBefore);
    })->throws(ValidationException::class, 'JPG, PNG, atau WebP');

    it('rejects a truncated image', function () {
        processor()->process(substr(Img::jpeg(), 0, 400), AttachmentKind::PhotoBefore);
    })->throws(ValidationException::class);

    it('rejects an image far too small to be a real photo', function () {
        processor()->process(Img::jpeg(40, 40), AttachmentKind::PhotoBefore);
    })->throws(ValidationException::class, 'terlalu kecil');

    it('rejects a tiny file that claims a huge canvas, before decoding it', function () {
        $bomb = Img::decompressionBomb();

        // The file itself is a few dozen bytes; only the header lies.
        expect(strlen($bomb))->toBeLessThan(200);

        processor()->process($bomb, AttachmentKind::PhotoBefore);
    })->throws(ValidationException::class, 'terlalu besar');

    it('rejects an image whose side exceeds the limit even if the pixel count is modest', function () {
        processor()->process(Img::decompressionBomb(13_000, 100), AttachmentKind::PhotoBefore);
    })->throws(ValidationException::class, 'terlalu besar');

    it('rejects an image too large for the memory this process has', function () {
        $original = ini_get('memory_limit');
        // Relative to what is in use now: PHP refuses a limit below current usage, and a full suite uses a lot.
        ini_set('memory_limit', (string) (memory_get_usage() + 30_000_000));

        try {
            // 20 megapixels needs ~100 MB to decode, far beyond the 30 MB of headroom left.
            processor()->process(Img::decompressionBomb(5000, 4000), AttachmentKind::PhotoBefore);
        } finally {
            ini_set('memory_limit', (string) $original);
        }
    })->throws(ValidationException::class, 'terlalu besar');
});
