<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $ba['ba']['number'] }}</title>
    <style>
        @page { margin: 28px 34px 40px 34px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #111; }
        h1 { font-size: 15px; text-align: center; margin: 0 0 2px 0; letter-spacing: 1px; }
        .sub { text-align: center; font-size: 10px; margin-bottom: 10px; }
        h2 { font-size: 10.5px; background: #e8e8e8; padding: 4px 6px; margin: 12px 0 4px 0; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; padding: 3px 6px; }
        .grid td { border: 1px solid #bbb; }
        .grid th { border: 1px solid #bbb; background: #f4f4f4; text-align: left; }
        .label { width: 27%; color: #444; }
        .result { font-size: 13px; font-weight: bold; }
        .box { border: 1px solid #bbb; padding: 6px; min-height: 30px; }
        .photo { text-align: center; padding: 4px; }
        .photo img { max-width: 100%; max-height: 150px; }
        .caption { font-size: 8px; color: #555; }
        .sign { text-align: center; width: 50%; }
        .sign .space { height: 90px; border-bottom: 1px solid #333; }
        .sign img { max-height: 85px; max-width: 90%; }
        .note { font-size: 8px; color: #666; margin-top: 10px; }
    </style>
</head>
<body>
    <h1>BERITA ACARA {{ mb_strtoupper($ba['ticket']['type']) }}</h1>
    <div class="sub">
        No. {{ $ba['ba']['number'] }}@if ($ba['ba']['revision'] > 1) (revisi {{ $ba['ba']['revision'] }})@endif
        &nbsp;·&nbsp; {{ $ba['vendor']['name'] }}
    </div>

    <h2>Informasi Tiket</h2>
    <table class="grid">
        <tr><td class="label">No. tiket</td><td>{{ $ba['ticket']['ticket_no'] }}</td><td class="label">No. tiket vendor</td><td>{{ $ba['ticket']['vendor_ticket_no'] ?? '-' }}</td></tr>
        <tr><td class="label">Jenis pekerjaan</td><td>{{ $ba['ticket']['type'] }}</td><td class="label">Kunjungan ke</td><td>{{ $ba['visit']['visit_no'] }}</td></tr>
        <tr><td class="label">Diterima</td><td>{{ $when($ba['ticket']['received_at']) }}</td><td class="label">Batas SLA</td><td>{{ $when($ba['ticket']['sla_due_at']) }}</td></tr>
        @if (! empty($ba['ticket']['description']))
            <tr><td class="label">Keterangan tiket</td><td colspan="3">{{ $ba['ticket']['description'] }}</td></tr>
        @endif
    </table>

    <h2>Merchant dan Terminal</h2>
    <table class="grid">
        <tr><td class="label">Merchant</td><td>{{ $ba['merchant']['name'] }}</td><td class="label">Kode merchant</td><td>{{ $ba['merchant']['code'] }}</td></tr>
        <tr><td class="label">TID</td><td>{{ $ba['tid'] }}</td><td class="label">Service point</td><td>{{ $ba['site']['service_point'] ?? '-' }}</td></tr>
        <tr><td class="label">Area kelolaan</td><td>{{ $ba['site']['area'] ?? '-' }}</td><td class="label">Provinsi</td><td>{{ $ba['site']['province'] ?? '-' }}</td></tr>
        <tr><td class="label">Alamat</td><td colspan="3">{{ $ba['site']['address'] }}</td></tr>
        <tr><td class="label">PIC di lokasi</td><td>{{ $ba['site']['pic_name'] ?? '-' }}</td><td class="label">Telepon PIC</td><td>{{ $ba['site']['pic_phone'] ?? ($ba['merchant']['phone'] ?? '-') }}</td></tr>
    </table>

    <h2>Pelaksanaan</h2>
    <table class="grid">
        <tr><td class="label">Teknisi</td><td>{{ $ba['technician']['name'] }}@if (! empty($ba['technician']['employee_no'])) ({{ $ba['technician']['employee_no'] }})@endif</td><td class="label">Dijadwalkan</td><td>{{ $when($ba['visit']['scheduled_at']) }}</td></tr>
        <tr><td class="label">Berangkat</td><td>{{ $when($ba['visit']['departed_at']) }}</td><td class="label">Tiba di lokasi</td><td>{{ $when($ba['visit']['arrived_at']) }}</td></tr>
        <tr><td class="label">Mulai dikerjakan</td><td>{{ $when($ba['visit']['handling_started_at']) }}</td><td class="label">Selesai</td><td>{{ $when($ba['visit']['closed_at']) }}</td></tr>
    </table>

    <h2>Hasil</h2>
    <table class="grid">
        <tr><td class="label">Status akhir kunjungan</td><td class="result">{{ $ba['result']['outcome_label'] }}</td></tr>
        @if (! empty($ba['result']['reason']))
            <tr><td class="label">Alasan</td><td>{{ $ba['result']['reason'] }}</td></tr>
        @endif
        @foreach ($ba['result']['data'] as $line)
            <tr><td class="label">{{ $line['label'] }}</td><td>{{ $line['value'] }}</td></tr>
        @endforeach
    </table>
    @if (! empty($ba['result']['notes']))
        <div class="box" style="margin-top: 4px;"><strong>Catatan teknisi:</strong><br>{!! nl2br(e($ba['result']['notes'])) !!}</div>
    @endif

    @if (count($photos) > 0)
        <h2>Dokumentasi Foto</h2>
        <table>
            @foreach (array_chunk($photos, 2) as $row)
                <tr>
                    @foreach ($row as $photo)
                        <td class="photo" style="width: 50%;">
                            <img src="{{ $photo['src'] }}" alt="">
                            <div class="caption">{{ $photo['label'] }}@if ($photo['taken']) · {{ $photo['taken'] }}@endif</div>
                        </td>
                    @endforeach
                    @if (count($row) === 1)<td></td>@endif
                </tr>
            @endforeach
        </table>
        @if ($omittedPhotos > 0)
            <div class="caption">{{ $omittedPhotos }} foto lain tersimpan di sistem dan tidak dicetak di sini.</div>
        @endif
    @endif

    <h2>Pengesahan</h2>
    <table style="page-break-inside: avoid;">
        <tr>
            <td class="sign">Merchant / PIC<div class="space">@if ($merchantSignature)<img src="{{ $merchantSignature }}" alt="">@endif</div>{{ $ba['site']['pic_name'] ?? $ba['merchant']['name'] }}</td>
            <td class="sign">Teknisi<div class="space">@if ($technicianSignature)<img src="{{ $technicianSignature }}" alt="">@endif</div>{{ $ba['technician']['name'] }}</td>
        </tr>
    </table>

    <div class="note">
        Dokumen ini dibuat otomatis oleh sistem pada {{ $when($ba['ba']['generated_at']) }}. Isinya dibekukan saat dibuat; perubahan data setelahnya tidak mengubah dokumen ini.
    </div>
</body>
</html>
