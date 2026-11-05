<?php

declare(strict_types=1);

namespace Modules\Document\Domain\Enums;

enum AttachmentKind: string
{
    case PhotoBefore = 'photo_before';
    case PhotoAfter = 'photo_after';
    case PhotoEvidence = 'photo_evidence';
    case SignatureMerchant = 'signature_merchant';
    case SignatureTechnician = 'signature_technician';
    case BeritaAcara = 'ba_pdf';

    public function isSignature(): bool
    {
        return $this === self::SignatureMerchant || $this === self::SignatureTechnician;
    }

    public function isPhoto(): bool
    {
        return $this === self::PhotoBefore || $this === self::PhotoAfter || $this === self::PhotoEvidence;
    }

    /**
     * Kinds a person may upload. The Berita Acara is produced by the system, never uploaded.
     *
     * @return list<self>
     */
    public static function uploadable(): array
    {
        return [self::PhotoBefore, self::PhotoAfter, self::PhotoEvidence, self::SignatureMerchant, self::SignatureTechnician];
    }

    public function label(): string
    {
        return match ($this) {
            self::PhotoBefore => 'Foto sebelum pekerjaan',
            self::PhotoAfter => 'Foto sesudah pekerjaan',
            self::PhotoEvidence => 'Foto bukti kunjungan',
            self::SignatureMerchant => 'Tanda tangan merchant',
            self::SignatureTechnician => 'Tanda tangan teknisi',
            self::BeritaAcara => 'Berita Acara',
        };
    }
}
