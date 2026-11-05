<?php

declare(strict_types=1);

namespace Modules\Document\Application\DTOs;

final readonly class ProcessedImage
{
    public function __construct(
        public string $bytes,
        public string $mime,
        public string $extension,
        public int $width,
        public int $height,
        public string $thumbnailBytes,
    ) {}
}
