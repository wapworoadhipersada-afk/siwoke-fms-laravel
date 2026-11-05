# API untuk vendor

Dokumen ini untuk tim teknis vendor (bank). Ada dua arah: vendor memanggil kita untuk membuka dan memantau tiket,
dan kita memanggil vendor setiap kali status tiket berubah.

## 1. Kredensial

Administrator FMS membuat satu **klien integrasi** per sistem vendor dan memberikan:

- `Key ID` (publik), misalnya `fms_k3j...`
- `Secret` (rahasia, 48 karakter) untuk menandatangani permintaan ke kita
- `Secret callback` (jika alamat callback diisi) untuk memeriksa tanda tangan kiriman kita

Secret hanya ditampilkan sekali. Simpan di tempat aman. Jika hilang atau bocor, minta administrator mengganti kuncinya;
yang lama langsung berhenti berlaku.

## 2. Menandatangani permintaan

Setiap permintaan membawa empat header:

| Header | Isi |
|---|---|
| `X-Key-Id` | Key ID klien |
| `X-Timestamp` | Waktu sekarang, detik Unix. Selisih lebih dari 5 menit ditolak |
| `X-Nonce` | Teks acak unik per permintaan (8 sampai 80 karakter). Nonce yang sama ditolak |
| `X-Signature` | `HMAC-SHA256` heksadesimal huruf kecil dari teks di bawah, kunci = Secret |

Teks yang ditandatangani, empat baris dipisah `\n`:

```
{timestamp}
{nonce}
{METHOD huruf besar}
{request-uri, termasuk query string}
{sha256 heksadesimal dari body mentah}
```

Untuk permintaan tanpa body, hash yang dipakai adalah SHA-256 dari teks kosong.

Contoh PHP:

```php
$body = json_encode($data);
$timestamp = time();
$nonce = bin2hex(random_bytes(16));
$uri = '/api/vendor/v1/tickets';
$signature = hash_hmac('sha256', implode("\n", [$timestamp, $nonce, 'POST', $uri, hash('sha256', $body)]), $secret);
```

Jawaban 401 `invalid_credentials` selalu sama apa pun penyebabnya (kunci salah, tanda tangan salah, waktu lewat, nonce
terpakai). Itu disengaja. Jawaban 403 `ip_not_allowed` berarti alamat IP pengirim tidak ada di daftar izin klien.

## 3. Endpoint

Alamat dasar: `https://<domain>/api/vendor/v1`. Semua berformat JSON.

### Membuka tiket: `POST /tickets`

```json
{
  "vendor_ticket_no": "BRI-2026-000123",
  "tid": "TID00123",
  "type": "MAINTENANCE",
  "priority": "normal",
  "scheduled_at": "2026-11-10T09:00:00+07:00",
  "description": "EDC tidak bisa baca kartu"
}
```

`type`: `INSTALL`, `MAINTENANCE`, `PULLOUT`, `REPLACE`. `priority` (opsional): `low`, `normal`, `high`, `urgent`.

- `201` tiket dibuat. `200` jika `vendor_ticket_no` yang sama sudah pernah dikirim (aman diulang saat timeout).
- `409 vendor_ticket_conflict` jika nomor yang sama dikirim untuk TID berbeda.
- `422` jika TID bukan milik vendor Anda atau jenis tidak dikenal.

### Melihat status: `GET /tickets/{vendor_ticket_no}`

### Membatalkan: `POST /tickets/{vendor_ticket_no}/cancel` dengan `{"reason": "..."}`

Hanya bisa sebelum teknisi tiba. Setelahnya `409 cannot_cancel`.

Isi `data` pada semua jawaban:

```json
{
  "ticket_no": "TK-20261110-000001",
  "vendor_ticket_no": "BRI-2026-000123",
  "type": "MAINTENANCE",
  "tid": "TID00123",
  "status": "OPEN",
  "internal_status": "received",
  "result": null,
  "received_at": "2026-11-10T08:00:00+07:00",
  "sla_due_at": "2026-11-12T08:00:00+07:00",
  "closed_at": null
}
```

`status` memakai istilah vendor jika administrator sudah mengisi pemetaan status; jika belum, sama dengan `internal_status`.
Status internal: `received`, `assigned`, `accepted`, `on_the_way`, `arrived`, `handling`, `pending`, `finished`,
`done_negative`, `cancelled`.

Setiap jawaban membawa header `X-Correlation-Id`; sebutkan itu saat melapor masalah.

## 4. Callback status dari kita

Jika klien punya alamat callback, setiap perubahan status tiket yang memakai nomor tiket vendor dikirim ke sana:
`POST` JSON, tanda tangan dengan skema yang sama (memakai Secret callback; `X-Key-Id` berisi Key ID klien, dan
`X-Event-Id` identitas kejadian).

```json
{
  "event": "ticket.status_changed",
  "event_id": "01J...",
  "occurred_at": "2026-11-10T10:15:00+07:00",
  "ticket": {
    "vendor_ticket_no": "BRI-2026-000123",
    "ticket_no": "TK-20261110-000001",
    "type": "MAINTENANCE",
    "tid": "TID00123",
    "status": "DONE",
    "internal_status": "finished",
    "change": "finished"
  },
  "visit": {
    "visit_no": 1,
    "result": "finish",
    "reason": null,
    "notes": "EDC diganti",
    "closed_at": "2026-11-10T10:15:00+07:00"
  }
}
```

Aturan penerimaan di sisi vendor:

1. Periksa tanda tangan dan waktu sebelum memproses.
2. Jawab `2xx` jika sudah diterima. Jawaban lain dianggap gagal.
3. Kegagalan dicoba ulang setelah 1 menit, 5 menit, 30 menit, 2 jam, 12 jam. Setelah itu kejadian ditandai gagal dan
   ditangani manual oleh tim kami.
4. Kejadian bisa tiba dua kali atau tidak berurutan. Gunakan `event_id` untuk membuang duplikat dan `occurred_at`
   untuk mengurutkan.
5. Alamat callback harus https dan tidak boleh mengarah ke jaringan internal. Kami tidak mengikuti pengalihan (redirect).

Data pribadi teknisi (nama, telepon) tidak pernah dikirim.
