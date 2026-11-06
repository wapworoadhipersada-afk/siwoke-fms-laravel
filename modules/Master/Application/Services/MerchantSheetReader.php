<?php

declare(strict_types=1);

namespace Modules\Master\Application\Services;

use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

/**
 * Reads the principal's list from an Excel (.xlsx) or CSV file into plain rows. The first row is the header;
 * columns are found by name, in any order, so a sheet with extra columns still works. Nothing is stored here.
 */
final class MerchantSheetReader
{
    public const MAX_ROWS = 2000;

    /**
     * Accepted header names (letters and digits only, lower case) for each field.
     *
     * @var array<string, list<string>>
     */
    private const HEADERS = [
        'merchant_name' => ['namamerchant', 'merchant', 'merchantname', 'namatoko', 'toko', 'nama'],
        'tid' => ['tid', 'terminalid', 'nomortid'],
        'mid' => ['mid', 'merchantid', 'nomormid'],
        'address' => ['alamat', 'address', 'alamatmerchant'],
        'province' => ['provinsi', 'province'],
        'service_point' => ['servicepoint', 'sp', 'namaservicepoint'],
        'area' => ['area', 'areakelolaan', 'areakelola'],
        'tid_note' => ['keterangan', 'keterangantid', 'ketdatatid', 'catatan', 'note', 'notes'],
    ];

    /**
     * @return list<array<string, string>> rows keyed by field name; empty lines are dropped
     */
    public function read(UploadedFile $file): array
    {
        $path = $file->getRealPath();

        if ($path === false) {
            throw ValidationException::withMessages(['file' => 'File tidak dapat dibaca.']);
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, ['xlsx', 'csv'], true)) {
            throw ValidationException::withMessages(['file' => 'Format file harus .xlsx atau .csv.']);
        }

        try {
            return $this->parse($path, $extension);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => 'File tidak dapat dibaca. Pastikan ini berkas Excel (.xlsx) atau CSV yang utuh.']);
        }
    }

    /**
     * @return list<array<string, string>>
     */
    private function parse(string $path, string $extension): array
    {
        $reader = $extension === 'csv' ? new CsvReader : new XlsxReader;
        $reader->open($path);

        $rows = [];
        $columns = null;

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = array_values(array_map($this->text(...), $row->toArray()));

                    if ($columns === null) {
                        $columns = $this->columnsOf($cells);

                        continue;
                    }

                    $line = [];

                    foreach ($columns as $index => $field) {
                        $line[$field] = $cells[$index] ?? '';
                    }

                    if (implode('', $line) === '') {
                        continue;
                    }

                    $rows[] = $line + array_fill_keys(array_keys(self::HEADERS), '');

                    if (count($rows) > self::MAX_ROWS) {
                        throw ValidationException::withMessages(['file' => 'Maksimal '.self::MAX_ROWS.' baris per dokumen. Pecah menjadi beberapa dokumen.']);
                    }
                }

                break; // only the first sheet
            }
        } finally {
            $reader->close();
        }

        if ($columns === null || ! in_array('tid', $columns, true) || ! in_array('merchant_name', $columns, true)) {
            throw ValidationException::withMessages(['file' => 'Baris pertama harus berisi judul kolom, minimal "Nama Merchant" dan "TID".']);
        }

        return $rows;
    }

    /**
     * @param  list<string>  $headers
     * @return array<int, string> column index => field
     */
    private function columnsOf(array $headers): array
    {
        $columns = [];

        foreach ($headers as $index => $header) {
            $key = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $header));

            foreach (self::HEADERS as $field => $names) {
                if (in_array($key, $names, true) && ! in_array($field, $columns, true)) {
                    $columns[$index] = $field;
                }
            }
        }

        return $columns;
    }

    private function text(mixed $cell): string
    {
        if ($cell instanceof DateTimeInterface) {
            return $cell->format('Y-m-d');
        }

        if (is_float($cell) && floor($cell) === $cell) {
            return number_format($cell, 0, '', '');
        }

        return trim(is_scalar($cell) ? (string) $cell : '');
    }
}
