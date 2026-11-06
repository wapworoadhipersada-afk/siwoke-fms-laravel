# Pengaturan peran, pengguna, dan menu

Fitur ini memperluas modul IAM dan instalasi Spatie Permission. Tabel `roles`, `permissions`, `model_has_roles`, dan
`role_has_permissions` milik Spatie tetap menjadi sumber kebenaran untuk hak akses.

## Yang tersedia

| Layar | Alamat | Izin |
|---|---|---|
| Peran (daftar, tambah, ubah, hapus, matriks izin) | `/iam/roles` | `iam.role.manage` |
| Pengguna (daftar, tambah, ubah, atur ulang password) | `/iam/users` | `iam.user.view` / `create` / `update` |
| Area kerja pengguna | `/master/user-scopes/{pengguna}` | `iam.user.update` |
| Pengaturan menu (per peran, pratinjau, semua menu, riwayat) | `/iam/navigation` | `iam.role.manage` |

Menu samping dibangun di server (`NavigationMenuService::forUser`) dan dikirim ke semua halaman sebagai prop
`navigation`. Tidak ada daftar menu yang ditulis tetap di Vue.

## Keputusan rancangan

- **Menu hanyalah tampilan, bukan otorisasi.** Setiap halaman dan aksi tetap memeriksa izinnya sendiri. Menu yang
  disembunyikan masih bisa dibuka lewat alamatnya bila orangnya berizin (ada tesnya), dan pengaturan menu tidak
  pernah menambah akses.
- **Halaman ditentukan kode, tampilan ditentukan admin.** `NavigationCatalog` menyatakan halaman apa yang ada, ke mana
  rutenya, dan izin apa yang diperlukan. Admin hanya mengubah nama, ikon, grup, judul bagian, urutan, aktif/mati, dan
  peran mana yang melihatnya. Tidak ada alamat bebas, sehingga menu tidak bisa diarahkan ke halaman yang tidak boleh
  dibuka dan tidak ada celah pengalihan terbuka.
- **Aturan tampil:** entri harus aktif, izin yang disyaratkan harus dimiliki, bila peran orang itu punya pengaturan
  untuk entri tersebut minimal satu harus menampilkannya, dan grup tanpa isi yang tampil ikut hilang. Entri yang tidak
  punya pengaturan peran sama sekali bersifat bersama (semua yang berizin melihatnya).
- **Urutan per peran** disimpan di `navigation_menu_role.sort_order`; tanpa pengaturan berlaku urutan bersama.
- **Katalog tidak menimpa pekerjaan admin.** `iam:sync-navigation` (juga tombol "Tambahkan halaman baru ke menu" dan
  `NavigationSeeder`) hanya menambah entri yang belum ada. Entri yang sudah ada tidak pernah diubah, jadi aman dijalankan
  di setiap deploy. Tabel kosong otomatis diisi dari katalog saat sidebar pertama kali dibangun.
- **Pratinjau** memakai izin peran yang dipilih (bukan izin admin yang melihat) dan tidak menulis apa pun.
- **Setiap perubahan menu** disimpan di `navigation_access_audits` dengan cuplikan sebelum dan sesudah.

## Peran

- Enam peran bawaan (`RoleName`) tidak bisa dihapus dan kodenya tidak berubah; labelnya boleh. Super Admin terkunci
  sepenuhnya.
- Peran baru memakai kode dari namanya (slug) dan bisa dihapus selama tidak ada yang memegangnya.
- Izin yang mengatur akses itu sendiri (`PermissionCatalog::privileged()`: kelola peran, tambah/ubah/hapus pengguna,
  semua area, klien integrasi) hanya bisa diberikan atau dicabut oleh Super Admin. Tanpa aturan ini, siapa pun yang
  boleh mengelola peran bisa memberi dirinya semua hak.
- Setelah admin mengubah izin sebuah peran, kolom `is_customized` menyala dan `RolesAndPermissionsSeeder` tidak lagi
  mengembalikannya ke bawaan saat deploy. Izin baru dari kode untuk peran seperti itu harus diberikan manual.

## Pengguna

- Password dibuat acak (14 karakter), ditampilkan satu kali di halaman jawaban, dan tidak disimpan di sesi atau log.
- Tidak boleh mengubah peran sendiri atau menonaktifkan diri sendiri; peran yang membawa izin pengatur akses hanya
  diberikan Super Admin; Super Admin aktif terakhir tidak bisa diturunkan atau dinonaktifkan.
- Menonaktifkan akun atau mengatur ulang password langsung mencabut sesi mobile.
- Tautan memakai ULID publik pengguna, bukan nomor internal.
- Pengguna tanpa area kerja tidak melihat data apa pun kecuali perannya punya `data.scope.all`; karena itu ada
  layar Area kerja.

## Batas yang diketahui

- Urutan diubah dengan tombol naik/turun, belum seret-lepas.
- Entri menu tidak bisa dihapus, hanya dimatikan; entri baru datang dari katalog di kode.
- Belum ada penghapusan pengguna (hanya nonaktifkan, supaya riwayat tiket dan audit tetap utuh).
- Belum ada undangan lewat email; admin menyampaikan password sementara lewat jalur aman.
