<?php

declare(strict_types=1);

namespace Modules\IAM\Domain\Enums;

enum EmploymentStatus: string
{
    case Freelance = 'freelance';
    case Pkwt = 'pkwt';
    case Permanent = 'tetap';
    case Contract = 'kontrak';

    public function label(): string
    {
        return match ($this) {
            self::Freelance => 'Freelance',
            self::Pkwt => 'PKWT',
            self::Permanent => 'Tetap',
            self::Contract => 'Kontrak',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $status): array => ['value' => $status->value, 'label' => $status->label()], self::cases());
    }
}
