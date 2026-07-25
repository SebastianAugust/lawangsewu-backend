# Login Username-Only & Email Keluar dari Alur Akun

Tanggal: 2026-07-23
Status: disetujui, siap masuk plan implementasi

## Masalah

Login di database lokal mati total. `AuthController::login` mencari user lewat
`User::where('username', $request->username)` tanpa fallback ke email, sementara
ketiga akun di DB lokal masih punya `username = NULL`. Query itu tidak akan
pernah menemukan siapa pun.

Dukungan login-via-username sendiri sudah terpasang penuh sejak commit `ab7632a`:

| Bagian | Lokasi | Status |
| --- | --- | --- |
| Validasi & query login | `app/Http/Controllers/AuthController.php:15-26` | sudah username |
| Kolom `username` | `database/migrations/2026_06_29_000002_add_username_to_users_table.php` | sudah ada, nullable + unique |
| Mass assignment | `app/Models/User.php:16` | sudah ada |
| Validasi create/update kasir | `app/Http/Controllers/UserController.php:35,77` | sudah ada |
| Form login | `LawangSewu-FrontEnd/src/pages/LoginPage.jsx:7,20,78-85` | sudah username |
| Form kelola akun | `LawangSewu-FrontEnd/src/pages/UserManagePage.jsx:296-308` | sudah ada |

Jadi ini bukan pekerjaan mengganti mekanisme login. Mekanismenya sudah jadi;
yang tertinggal hanya pengisian data, ditambah satu perubahan lanjutan yang
diminta terpisah: mengeluarkan email dari alur pembuatan akun.

## Konteks Deployment

Ini menentukan bentuk solusinya, jadi dicatat eksplisit:

- DB lokal (`.env` → `DB_HOST=127.0.0.1`, `DB_DATABASE=LawangSewu`) adalah
  satu-satunya yang bermasalah.
- DB hosting **sudah diisi username secara manual** lewat phpMyAdmin, sehingga
  login di sana sudah berfungsi.
- Tidak ada pipeline deploy otomatis. Workflow di `.github/workflows/` adalah
  bawaan skeleton Laravel, bukan milik proyek ini. Migration hanya jalan kalau
  dijalankan manual.
- Persyaratan pemilik proyek: perubahan ini **tidak boleh mengubah data di DB
  hosting**. Pengerjaan dan pengujian dilakukan di lokal lebih dulu.

## Keputusan

1. Username tiga akun lokal: `owner`, `kasir`, `kasir2` — diturunkan dari bagian
   depan email yang sekarang.
2. Email dihapus total dari alur pembuatan/penyuntingan akun kasir.

## Yang Sengaja Tidak Dikerjakan

Dua hal ini sempat masuk rancangan awal lalu dicoret setelah konteks hosting
jelas. Alasannya dicatat supaya tidak diusulkan ulang:

**Migration backfill username.** Migration ikut ter-commit dan akan jalan begitu
`php artisan migrate` dieksekusi di hosting — persis yang harus dihindari.
Masalahnya cuma ada di lokal, dan hosting sudah beres. Migration adalah alat
yang salah untuk memperbaiki data yang hanya rusak di satu lingkungan.

**Migration `username` menjadi NOT NULL.** Kalau ternyata ada satu saja baris di
hosting yang `username`-nya masih NULL, migration ini gagal di tengah jalan.
Perlindungan terhadap username kosong sudah ada di lapisan validasi
(`UserController.php:35` — `required|min:3|unique`), jadi constraint DB-nya
tidak sepadan dengan risikonya saat ini.

## Bagian A — Perbaikan Data Lokal

Isi `username` untuk tiga akun di DB lokal lewat `php artisan tinker`:

| id | name | username |
| --- | --- | --- |
| 1 | Owner | `owner` |
| 2 | Kasir | `kasir` |
| 3 | Kasir Cabang 2 | `kasir2` |

Tidak ada file yang berubah dan tidak ada yang ter-commit, sehingga mustahil
merembet ke hosting.

`database/seeders/UserSeeder.php` ditambahi `username` untuk ketiga akun. File
ini memang ter-commit, tapi seeder tidak pernah jalan otomatis saat deploy — ia
hanya terpanggil kalau `db:seed` diketik manual. Tujuannya supaya
`migrate:fresh --seed` di lokal menghasilkan akun yang bisa login, bukan
mengulang bug yang sama pada instalasi baru.

## Bagian B — Email Keluar dari Alur Akun

### Migration (satu-satunya perubahan skema)

`email` diubah menjadi nullable. Ini tidak bisa dihindari:
`database/migrations/0001_01_01_000000_create_users_table.php:17` membuat kolom
itu NOT NULL, jadi begitu form berhenti mengirim email, pembuatan akun baru akan
langsung gagal.

Kolomnya **tidak di-drop**. Menghapus beneran akan menyeret tabel
`password_reset_tokens` yang memakai email sebagai primary key, tanpa manfaat
yang sepadan. Nullable juga menyimpan email lama kalau suatu saat dibutuhkan.

Sifat perubahannya melebarkan constraint, bukan menyempitkan: tidak menghapus
data, tidak menyentuh baris yang sudah ada, dan tidak bisa gagal karena data
existing. Aman kalau nanti ter-deploy, tapi waktu push tetap keputusan pemilik
proyek. Migration ini ditaruh di commit terpisah supaya gampang ditahan.

Unique index pada `email` dibiarkan — MySQL mengizinkan banyak NULL di unique
index, jadi tidak ada tabrakan.

### Perubahan kode

Backend (`LawangSewu`):

| File | Perubahan |
| --- | --- |
| `app/Http/Controllers/UserController.php:36` | hapus aturan validasi `email` di `store` |
| `app/Http/Controllers/UserController.php:49` | hapus `email` dari `User::create` |
| `app/Http/Controllers/UserController.php:78` | hapus aturan validasi `email` di `update` |
| `app/Http/Controllers/UserController.php:59,119,139,155` | audit log mencatat `username`, bukan `email` |
| `app/Http/Controllers/AuthController.php:42` | audit log login mencatat `username` |
| `database/factories/UserFactory.php:29` | tambah `username` unik |

Frontend (`LawangSewu-FrontEnd`):

| File | Perubahan |
| --- | --- |
| `src/pages/UserManagePage.jsx:23,40,50` | hapus state `email` dan reset-nya |
| `src/pages/UserManagePage.jsx:69` | hapus validasi "Email harus diisi" |
| `src/pages/UserManagePage.jsx:78,87` | hapus `email` dari payload update & create |
| `src/pages/UserManagePage.jsx:309-321` | hapus field Email dari form |
| `src/pages/UserManagePage.jsx:214-215` | baris info: email diganti penanda username |

Pada baris info daftar akun, penjagaan `{user.username && ...}` **dipertahankan**,
bukan disederhanakan. Rancangan awal sempat menyatakan sebaliknya — dengan alasan
"username wajib ada di setiap akun kasir" — dan itu keliru: migration
`2026_06_29_000002_add_username_to_users_table.php` sengaja membuat kolomnya
nullable untuk akun lama, dan baris lama tidak di-backfill. Hanya validasi
`POST`/`PUT` yang mewajibkannya.

Bentuk penjagaannya berubah karena email tidak lagi tersedia sebagai penanda
cadangan. Akun ber-`username` NULL **tidak bisa login sama sekali**
(`AuthController` mencarinya lewat `username`), jadi keadaan itu ditampilkan
eksplisit sebagai peringatan — "belum ada username — tidak bisa login" — supaya
owner bisa segera memperbaikinya lewat tombol Edit, bukan disembunyikan.

### Test

`tests/Feature/UserManagementTest.php` memakai email di 15 tempat dan harus
menyesuaikan. Satu yang butuh perhatian: assertion di baris 119,
`assertJsonValidationErrors('email')`, berada di
`test_inactive_kasir_cannot_login_active_can` — itu menguji penolakan login akun
nonaktif, dan kebetulan saja key error-nya `email` karena login dulu memakai
email. Assertion itu menyesuaikan ke key `username`.

Berkas ini **tidak pernah punya** test keunikan email, jadi tidak ada daya uji
yang hilang. Yang ditambahkan justru pengujian baru: **username duplikat
ditolak** — constraint keunikan yang sekarang benar-benar menentukan siapa yang
bisa login, dan sebelumnya tidak terjaga test sama sekali.

`UserFactory` harus menghasilkan `username` unik sebelum test apa pun yang
memakai factory bisa lolos.

## Verifikasi

Semua dijalankan di lokal:

1. `php artisan test` hijau.
2. `php artisan migrate:fresh --seed` lalu login manual dengan `owner` /
   `password123` — memastikan instalasi bersih menghasilkan akun yang berfungsi.
3. Lewat UI: owner membuat cabang + akun kasir baru tanpa isian email, lalu
   logout dan login memakai akun itu.

## Pagar Pengaman

- Sebelum menjalankan perintah artisan yang menyentuh skema atau data, isi
  `DB_HOST` di `.env` dicek dan ditunjukkan dulu. Harus `127.0.0.1`.
- `migrate:fresh` **tidak boleh** diarahkan ke hosting — perintah itu menghapus
  seluruh tabel beserta riwayat transaksi asli.
- Perbaikan data di Bagian A hanya lewat tinker ke DB lokal, tidak lewat
  migration.

## Catatan di Luar Scope

Password ketiga akun adalah `password123` dan ter-hardcode di `UserSeeder.php`.
Layak diganti sebelum dipakai di restoran beneran, tapi tidak dikerjakan di sini.
