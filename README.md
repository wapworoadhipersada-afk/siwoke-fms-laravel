# Field Managed Service (FMS)

Sistem layanan lapangan multi-vendor untuk mesin EDC: tiket dari bank/vendor, penugasan teknisi, aplikasi mobile
teknisi, pelacakan aset, Berita Acara PDF otomatis, dan integrasi dua arah dengan sistem vendor.

Laravel 13 · PostgreSQL · Redis · Vue 3 + Inertia · Sanctum · Spatie Permission. Monolit modular di `modules/`.

## Modul

| Modul | Isi |
|---|---|
| `IAM` | Login web (2FA, passkey), login mobile (token + refresh), peran dan izin, perangkat |
| `Master` | Vendor, lokasi (hierarki), merchant, TID, cakupan area per pengguna |
| `Asset` | Katalog aset, buku besar perpindahan (tidak bisa diubah), serah-terima dua langkah, stok |
| `Ticket` | Alur status tiket, kunjungan, aksi teknisi (offline-aman), aset per kunjungan, Berita Acara |
| `Document` | Foto dan tanda tangan bukti (diproses ulang, disimpan privat), berkas buatan sistem |
| `Integration` | API untuk vendor (bertanda tangan HMAC), callback status (outbox), log, klien |
| `Report` | Dashboard, laporan tiket, ekspor CSV, pemeriksaan kesehatan |
| `Shared` | Dasar bersama: audit, error API, header keamanan |

Aturan ketergantungan antar modul dijaga tes arsitektur (`tests/Arch`). Modul operasional tidak boleh bergantung pada
`Integration` atau `Report`.

## Menjalankan di komputer sendiri (Windows + Herd + Docker)

```bash
docker compose up -d          # PostgreSQL dan Redis
cp .env.example .env          # lalu php artisan key:generate
php artisan migrate --seed    # peran, katalog, dan akun dev <peran>@fms.test
npm install && npm run dev
php artisan queue:work        # wajib agar Berita Acara dan callback vendor diproses
```

Akun dev dan password-nya ada di `modules/IAM/Database/Seeders/DevUserSeeder.php`. Jangan pernah menjalankan seeder
itu di produksi.

## Pemeriksaan sebelum commit

```bash
vendor/bin/pint --test        # gaya kode
vendor/bin/phpstan analyse    # analisis statis (Larastan level 7)
npx vue-tsc --noEmit          # tipe frontend
vendor/bin/pest               # tes (butuh PostgreSQL, database fms_testing)
```

## Dokumen lain

- [docs/operasional.md](docs/operasional.md): pemasangan produksi, scheduler, backup, pemantauan, panduan on-boarding vendor.
- [docs/api-vendor.md](docs/api-vendor.md): cara vendor memanggil API kita dan menerima callback.
