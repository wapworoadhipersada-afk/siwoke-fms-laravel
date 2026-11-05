# Panduan operasional

## 1. Yang harus berjalan di produksi

| Komponen | Perintah / catatan |
|---|---|
| Web (Nginx + PHP-FPM) | PHP 8.3+, ekstensi `pdo_pgsql`, `redis`, `gd`, `mbstring`, `intl`, `bcmath` |
| Queue worker | `php artisan queue:work --tries=3 --max-time=3600` di bawah Supervisor/systemd, restart saat deploy: `php artisan queue:restart` |
| Scheduler | cron tiap menit: `* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1` |
| PostgreSQL 16+ | Backup harian (lihat bagian 3) |
| Redis | Sesi, cache, antrean |

Tanpa **queue worker**, Berita Acara tidak dibuat dan callback ke vendor tidak terkirim. Tanpa **scheduler**, callback yang
gagal tidak dicoba ulang dan pemeriksaan harian tidak jalan.

### Pengaturan PHP yang wajib dinaikkan

Foto dari HP besar. Di `php.ini` (FPM dan CLI): `upload_max_filesize=12M`, `post_max_size=16M`, `memory_limit=256M`.
Aplikasi sendiri membatasi satu unggahan 8 MB; aplikasi mobile sebaiknya mengecilkan foto sebelum mengirim.

### `.env` produksi (selain yang sudah ada di `.env.example`)

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://fms.contoh.co.id
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
TRUSTED_PROXIES=<alamat load balancer atau * bila di belakang proxy tepercaya>
SECURITY_CSP=enforce
BA_AUTO_GENERATE=true
BA_TIMEZONE=Asia/Jakarta
REPORT_TIMEZONE=Asia/Jakarta
INTEGRATION_ALLOW_INSECURE_WEBHOOKS=false
ATTACHMENTS_DISK=attachments        # atau disk S3 privat
```

Jangan menjalankan `DevUserSeeder` di produksi. Buat akun Super Admin pertama lewat tinker atau seeder terpisah dengan
password kuat, lalu aktifkan 2FA.

## 2. Jadwal otomatis

Sudah terdaftar di aplikasi (berjalan lewat scheduler di atas):

| Perintah | Kapan | Fungsi |
|---|---|---|
| `integration:deliver-due` | tiap menit | kirim/ulangi callback vendor yang jatuh tempo |
| `asset:reconcile` | harian 02:00 | cocokkan buku besar aset dengan posisi saat ini |
| `document:verify` | mingguan | pastikan semua berkas bukti utuh (hash sama) |
| `sanctum:prune-expired --hours=24` | harian | bersihkan token kedaluwarsa |
| `queue:prune-failed --hours=720` | harian | buang job gagal yang sudah lama |
| `activitylog:clean` | harian | hapus jejak audit melewati masa simpan (`config/activitylog.php`) |

Manual bila perlu: `document:prune-orphans` (daftar berkas tanpa catatan; `--delete` untuk menghapus).

## 3. Backup dan pemulihan

Yang harus dicadangkan: **database** dan **disk berkas bukti** (`storage/app/private/attachments` atau bucket S3).
Keduanya harus sama-sama dipulihkan; database tanpa berkas meninggalkan Berita Acara kosong.

```bash
pg_dump -Fc -h 127.0.0.1 -U fms fms > fms-$(date +%F).dump
rsync -a storage/app/private/attachments/ /backup/attachments/
```

Simpan minimal 30 hari, satu salinan di luar server. **Uji pemulihan** tiap kuartal ke server lain:

```bash
createdb fms_restore && pg_restore -d fms_restore fms-2026-11-10.dump
php artisan document:verify      # harus menyatakan semua berkas utuh
php artisan asset:reconcile      # harus menyatakan buku aset sinkron
```

Buku aset, riwayat status tiket, Berita Acara, dan log integrasi dilindungi trigger PostgreSQL agar tidak bisa diubah atau
dihapus. Pemulihan lewat `pg_restore` tetap bekerja; jangan menonaktifkan trigger untuk "memperbaiki" data.

## 4. Pemantauan

`php artisan fms:health` memeriksa database, cache, penyimpanan berkas, callback yang tersangkut, job gagal, dan
kesesuaian buku aset. Keluar dengan kode gagal dan daftar masalah bila ada yang salah. Jalankan tiap 5 menit dari alat
pemantau (cron + email, Uptime Kuma, dsb). Gunakan `--skip-ledger` jika buku aset sudah besar. `/up` tersedia untuk
pemeriksaan sederhana "aplikasi hidup".

Yang perlu ditangani manusia:

- **Callback gagal total**: halaman *Log integrasi*, tombol *Kirim ulang* setelah masalah di sisi vendor selesai.
- **Job gagal**: `php artisan queue:failed`, lalu `queue:retry all` setelah penyebabnya diperbaiki.
- **Ketidaksesuaian buku aset**: jangan diperbaiki dengan SQL. Tanyakan dulu mengapa; koreksi dilakukan lewat
  penyesuaian stok atau perpindahan yang tercatat.

## 5. On-boarding vendor baru

1. Master Data: buat Vendor, lalu merchant dan TID (impor CSV belum tersedia).
2. *Klien integrasi* → Tambah klien: pilih vendor, isi daftar IP (disarankan) dan alamat callback bila ada.
   Kirim Key ID dan Secret lewat kanal aman; arahkan tim vendor ke `docs/api-vendor.md`.
3. Isi pemetaan status vendor (tabel `status_mappings`) jika vendor memakai istilah sendiri. Belum ada layar untuk ini.
4. Tes di lingkungan staging dengan klien terpisah sebelum klien produksi dibuat.

## 6. Daftar periksa keamanan sebelum go-live

- [ ] `APP_DEBUG=false`, `APP_ENV=production`, `APP_KEY` unik dan disimpan di tempat aman (kehilangan kunci berarti secret klien tidak bisa dibaca).
- [ ] HTTPS aktif; `SESSION_SECURE_COOKIE=true`; HSTS aktif otomatis di produksi.
- [ ] PostgreSQL dan Redis tidak terbuka ke internet; password kuat; Redis berpassword.
- [ ] `DevUserSeeder` tidak dijalankan; akun Super Admin memakai 2FA.
- [ ] Akun teknisi yang berhenti bekerja dinonaktifkan dan perangkatnya dicabut.
- [ ] Backup berjalan dan sudah pernah diuji dipulihkan.
- [ ] Queue worker dan scheduler berjalan di bawah Supervisor/systemd dan hidup kembali setelah reboot.
- [ ] Daftar IP diisi untuk klien integrasi yang punya IP tetap.
- [ ] `composer audit` dan `npm audit` dijalankan sebelum tiap rilis.
- [ ] Antivirus untuk unggahan (ClamAV) dipertimbangkan bila aturan perusahaan mewajibkan. Saat ini gambar didekode
      dan dienkode ulang, sehingga muatan tersembunyi dibuang, tetapi itu bukan pemindaian virus.

## 7. Batas yang diketahui

- Satu templat Berita Acara untuk semua vendor; tata letak per vendor belum ada.
- Belum ada layar untuk: pemetaan status vendor, aturan bukti dan aset per jenis tiket, penyesuaian stok, impor CSV,
  penetapan area kerja per pengguna. Datanya sudah ada di database dan aksinya sudah ada di kode.
- Aplikasi mobile belum bisa mengunduh Berita Acara.
- Callback hanya untuk perubahan status; belum ada pengiriman file Berita Acara ke vendor.
