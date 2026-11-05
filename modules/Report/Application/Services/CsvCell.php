<?php

declare(strict_types=1);

namespace Modules\Report\Application\Services;

/**
 * Makes a value safe to put in a spreadsheet. A cell that starts with = + - @ (or a tab or carriage return) is
 * run as a formula when opened in Excel or Sheets, so a merchant named "=HYPERLINK(...)" could attack whoever
 * opens the export. Such cells are prefixed with an apostrophe so they stay text.
 */
final class CsvCell
{
    public static function safe(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    /**
     * @param  list<string>  $row
     * @return list<string>
     */
    public static function row(array $row): array
    {
        return array_map(self::safe(...), $row);
    }
}
