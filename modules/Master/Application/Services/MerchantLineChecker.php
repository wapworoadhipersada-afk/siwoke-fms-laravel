<?php

declare(strict_types=1);

namespace Modules\Master\Application\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Checks one line of a merchant document against the master data: is the TID usable, and which Area Kelolaan
 * does the written province / service point / area point to? One checker serves a whole document so the
 * location list and the existing TIDs are read once.
 */
final class MerchantLineChecker
{
    /** @var list<array{id: int, area: string, sp: string, province: string, vendor: int|null}>|null */
    private ?array $areas = null;

    /** @var array<string, true>|null */
    private ?array $taken = null;

    public function __construct(private readonly int $vendorId) {}

    /**
     * @param  array<string, string|null>  $row
     * @return array{location_id: int|null, error: string|null, tid: string}
     */
    public function check(array $row): array
    {
        $tid = Str::upper(trim((string) ($row['tid'] ?? '')));

        if (trim((string) ($row['merchant_name'] ?? '')) === '') {
            return ['location_id' => null, 'error' => 'Nama merchant wajib diisi.', 'tid' => $tid];
        }

        if ($tid === '' || mb_strlen($tid) > 20) {
            return ['location_id' => null, 'error' => 'TID wajib diisi, maksimal 20 karakter.', 'tid' => $tid];
        }

        if (isset($this->taken()[mb_strtolower($tid)])) {
            return ['location_id' => null, 'error' => 'TID sudah terdaftar untuk vendor ini.', 'tid' => $tid];
        }

        [$locationId, $error] = $this->locate($row);

        return ['location_id' => $locationId, 'error' => $error, 'tid' => $tid];
    }

    /**
     * Remember a TID as used, so the same TID twice in one document is caught on its second line.
     */
    public function claim(string $tid): void
    {
        $this->taken();
        $this->taken[mb_strtolower($tid)] = true;
    }

    /**
     * @param  array<string, string|null>  $row
     * @return array{0: int|null, 1: string|null}
     */
    private function locate(array $row): array
    {
        $area = mb_strtolower(trim((string) ($row['area'] ?? '')));

        if ($area === '') {
            return [null, 'Area kelolaan wajib diisi.'];
        }

        $sp = mb_strtolower(trim((string) ($row['service_point'] ?? '')));
        $province = mb_strtolower(trim((string) ($row['province'] ?? '')));

        $found = array_values(array_filter($this->areas(), static fn (array $candidate): bool => $candidate['area'] === $area
            && ($sp === '' || $candidate['sp'] === $sp)
            && ($province === '' || $candidate['province'] === $province)));

        return match (count($found)) {
            0 => [null, "Area \"{$row['area']}\" tidak ditemukan untuk vendor ini. Periksa ejaan atau tambahkan dulu di menu Lokasi."],
            1 => [$found[0]['id'], null],
            default => [null, "Area \"{$row['area']}\" ada lebih dari satu. Isi juga service point atau provinsinya."],
        };
    }

    /**
     * @return list<array{id: int, area: string, sp: string, province: string, vendor: int|null}>
     */
    private function areas(): array
    {
        $this->areas ??= array_values(DB::table('locations as area')
            ->join('locations as sp', 'sp.id', '=', 'area.parent_id')
            ->join('locations as prov', 'prov.id', '=', 'sp.parent_id')
            ->where('area.type', 'area')
            ->where('area.is_active', true)
            ->whereNull('area.deleted_at')
            ->where(fn ($query) => $query->whereNull('sp.vendor_id')->orWhere('sp.vendor_id', $this->vendorId))
            ->get(['area.id', 'area.name as area', 'sp.name as sp', 'prov.name as province', 'sp.vendor_id as vendor'])
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'area' => mb_strtolower((string) $row->area),
                'sp' => mb_strtolower((string) $row->sp),
                'province' => mb_strtolower((string) $row->province),
                'vendor' => $row->vendor === null ? null : (int) $row->vendor,
            ])->all());

        return $this->areas;
    }

    /**
     * @return array<string, true>
     */
    private function taken(): array
    {
        if ($this->taken === null) {
            $this->taken = [];

            foreach (DB::table('terminals')->where('vendor_id', $this->vendorId)->whereNull('deleted_at')->pluck('tid') as $tid) {
                $this->taken[mb_strtolower((string) $tid)] = true;
            }
        }

        return $this->taken;
    }
}
