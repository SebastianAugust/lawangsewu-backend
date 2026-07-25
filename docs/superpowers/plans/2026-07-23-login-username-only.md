# Login Username-Only & Email Keluar dari Alur Akun — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Memperbaiki login di database lokal yang mati total, lalu mengeluarkan email dari alur pembuatan/penyuntingan akun kasir.

**Architecture:** Dukungan login-via-username sudah terpasang penuh sejak commit `ab7632a` — yang tertinggal hanya pengisian data. Perbaikan data lokal dilakukan lewat `tinker` (bukan migration) supaya mustahil merembet ke DB hosting yang sudah beres. Setelah itu email dilepas dari alur akun: kolomnya dibuat nullable (tidak di-drop), validasi dan field form-nya dihapus, dan audit log beralih mencatat `username`.

**Tech Stack:** Laravel 12.58 + Sanctum (backend), React + Vite + Tailwind (frontend), MySQL (lokal & hosting), SQLite in-memory (test).

**Spec:** `docs/superpowers/specs/2026-07-23-login-username-only-design.md`

## Global Constraints

- Dua repositori terpisah: backend `C:\codes\Semester 4\LawangSewu`, frontend `C:\codes\Semester 4\LawangSewu-FrontEnd`.
- **JANGAN COMMIT APA PUN.** Pemilik proyek yang melakukan commit sendiri. Tinggalkan seluruh perubahan di working tree. Dilarang menjalankan `git add`, `git commit`, `git checkout -b`, atau `git stash`. Pekerjaan tetap di branch `main` di kedua repo.
- Repo frontend punya perubahan yang belum di-commit di `src/pages/CashierPage.jsx` yang **tidak berkaitan** dengan pekerjaan ini. Jangan disentuh, jangan di-stage, jangan di-revert.
- **Perubahan ini tidak boleh mengubah data di DB hosting.** DB hosting sudah diisi `username` secara manual dan login di sana sudah berfungsi.
- Sebelum menjalankan perintah artisan apa pun yang menyentuh skema atau data, verifikasi `DB_HOST` di `.env` bernilai `127.0.0.1`. Perintahnya ada di Task 1 Step 1.
- `php artisan migrate:fresh` **tidak boleh** diarahkan ke hosting — perintah itu menghapus seluruh tabel beserta riwayat transaksi asli.
- Dilarang membuat migration backfill username atau migration `username` menjadi NOT NULL. Alasan lengkap ada di bagian "Yang Sengaja Tidak Dikerjakan" pada spec.
- Test memakai SQLite in-memory (`phpunit.xml:26-27`), terpisah total dari MySQL lokal maupun hosting. Menjalankan test selalu aman.
- Aturan username yang berlaku di seluruh sistem: minimal 3 karakter, maksimal 255, hanya `[a-zA-Z0-9_]`, unik. Sudah ditegakkan di `UserController.php:35,77`.
- Frontend belum punya test runner. Verifikasi frontend dilakukan manual lewat UI, dijelaskan di Task 7.

## Baseline Test yang Sudah Merah

Sebelum plan ini dikerjakan, `php artisan test --filter=UserManagementTest` menghasilkan **3 gagal, 6 lolos**. Ketiganya gagal karena persoalan yang persis ditangani plan ini:

| Test | Penyebab |
| --- | --- |
| `owner can create cabang and kasir together` | POST `/api/users` tanpa `username`, sementara `UserController.php:35` mewajibkannya → 422 |
| `creating cabang is one to one` | sama seperti di atas |
| `inactive kasir cannot login active can` | POST `/api/login` mengirim `email`, sementara `AuthController.php:16` mewajibkan `username` → 422 |

Jadi "test hijau" baru tercapai di akhir Task 5. Jangan tafsirkan kegagalan ini sebagai kerusakan yang ditimbulkan oleh pekerjaan ini.

Urutannya mengikuti TDD: Task 4 menyesuaikan test lebih dulu dan **sengaja** meninggalkannya merah, Task 5 yang membuatnya hijau lewat perubahan controller.

## Struktur File

Backend (`C:\codes\Semester 4\LawangSewu`):

| File | Tanggung jawab | Aksi |
| --- | --- | --- |
| `database/seeders/UserSeeder.php` | akun awal instalasi bersih | Modify |
| `database/migrations/2026_07_23_000001_make_email_nullable_on_users_table.php` | melonggarkan constraint email | Create |
| `app/Http/Controllers/UserController.php` | CRUD akun kasir oleh owner | Modify |
| `app/Http/Controllers/AuthController.php` | login/logout/me | Modify |
| `database/factories/UserFactory.php` | data user untuk test | Modify |
| `tests/Feature/UserManagementTest.php` | pengujian alur akun | Modify |

Frontend (`C:\codes\Semester 4\LawangSewu-FrontEnd`):

| File | Tanggung jawab | Aksi |
| --- | --- | --- |
| `src/pages/UserManagePage.jsx` | UI kelola cabang & akun kasir | Modify |

`src/pages/LoginPage.jsx` **tidak disentuh** — sudah sepenuhnya memakai username.

## Urutan Task

Task 1 berdiri sendiri dan langsung memulihkan login lokal. Task 2–5 adalah satu rantai backend: skema, factory, test merah, lalu implementasi yang menghijaukannya. Task 6 adalah frontend. Task 7 verifikasi manual menyeluruh.

Tidak ada task yang berakhir dengan commit. Seluruh perubahan menumpuk di working tree sampai pemilik proyek meninjau dan meng-commit sendiri.

---

### Task 1: Pulihkan login di DB lokal

Memperbaiki data lokal dan mencegah instalasi bersih mengulangi bug yang sama.

**Files:**
- Modify: `database/seeders/UserSeeder.php:12-36`

**Interfaces:**
- Consumes: —
- Produces: tiga akun lokal dengan `username` = `owner`, `kasir`, `kasir2`; password ketiganya `password123`. Task 7 memakai akun `owner` untuk login manual.

- [ ] **Step 1: Pastikan menunjuk DB lokal**

Ini pagar pengaman wajib. Jangan lanjut kalau hasilnya bukan `127.0.0.1`.

```bash
cd "C:/codes/Semester 4/LawangSewu" && grep -E "^DB_HOST|^DB_DATABASE" .env
```

Expected:
```
DB_HOST=127.0.0.1
DB_DATABASE=LawangSewu
```

- [ ] **Step 2: Lihat kondisi awal**

```bash
cd "C:/codes/Semester 4/LawangSewu" && php artisan tinker --execute="foreach(App\Models\User::all() as \$u){ echo \$u->id.' | '.\$u->name.' | username='.var_export(\$u->username,true).PHP_EOL; }"
```

Expected: ketiga baris menampilkan `username=NULL`.

- [ ] **Step 3: Isi username**

Hanya menyentuh baris yang `username`-nya masih NULL, jadi aman dijalankan ulang.

```bash
cd "C:/codes/Semester 4/LawangSewu" && php artisan tinker --execute="
\$map = [1 => 'owner', 2 => 'kasir', 3 => 'kasir2'];
foreach (\$map as \$id => \$uname) {
    \$u = App\Models\User::find(\$id);
    if (\$u && \$u->username === null) { \$u->username = \$uname; \$u->save(); echo \"set {\$id} -> {\$uname}\".PHP_EOL; }
    else { echo \"skip {\$id}\".PHP_EOL; }
}"
```

Expected:
```
set 1 -> owner
set 2 -> kasir
set 3 -> kasir2
```

- [ ] **Step 4: Verifikasi terisi**

```bash
cd "C:/codes/Semester 4/LawangSewu" && php artisan tinker --execute="foreach(App\Models\User::all() as \$u){ echo \$u->id.' | username='.var_export(\$u->username,true).PHP_EOL; }"
```

Expected: `username='owner'`, `username='kasir'`, `username='kasir2'` — tidak ada NULL tersisa.

- [ ] **Step 5: Tambahkan username ke seeder**

Ganti ketiga blok `User::create` di `database/seeders/UserSeeder.php` sehingga isinya persis seperti ini:

```php
        // Owner is not tied to a branch — branch_id null means "lihat semua cabang".
        User::create( [
            'name' => 'Owner',
            'username' => 'owner',
            'email' => 'owner@lawangsewu.com',
            'password' => Hash::make( 'password123' ),
            'role' => 'owner',
            'branch_id' => null,
        ] );

        // Each kasir is bound to one branch. Assumes BranchSeeder ran first
        // (branch 1 = Pusat, branch 2 = Cabang 2).
        User::create( [
            'name' => 'Kasir',
            'username' => 'kasir',
            'email' => 'kasir@lawangsewu.com',
            'password' => Hash::make( 'password123' ),
            'role' => 'kasir',
            'branch_id' => 1,
        ] );

        User::create( [
            'name' => 'Kasir Cabang 2',
            'username' => 'kasir2',
            'email' => 'kasir2@lawangsewu.com',
            'password' => Hash::make( 'password123' ),
            'role' => 'kasir',
            'branch_id' => 2,
        ] );
```

- [ ] **Step 6: Login manual**

Jalankan `php artisan serve` di backend dan `npm run dev` di frontend, buka halaman login, masuk dengan `owner` / `password123`.

Expected: berhasil masuk ke dashboard. Inilah bukti login lokal sudah pulih.

Jangan commit. Tinggalkan perubahan `UserSeeder.php` di working tree.

---

### Task 2: Migration email nullable

Satu-satunya perubahan skema. Wajib mendahului Task 3, karena begitu validasi email dilepas tanpa ini, insert akan gagal pada kolom NOT NULL.

**Files:**
- Create: `database/migrations/2026_07_23_000001_make_email_nullable_on_users_table.php`

**Interfaces:**
- Consumes: —
- Produces: kolom `users.email` nullable, sehingga `User::create` tanpa `email` berhasil. Task 3–5 bergantung pada ini.

- [ ] **Step 1: Buat file migration**

Buat `database/migrations/2026_07_23_000001_make_email_nullable_on_users_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Login memakai username; email tidak lagi diminta saat owner
            // membuat akun kasir. Kolomnya sengaja tidak di-drop — email lama
            // tetap tersimpan, dan password_reset_tokens masih memakai email
            // sebagai primary key.
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
```

Catatan: unique index pada `email` dibiarkan. MySQL mengizinkan banyak NULL di unique index, jadi tidak akan bentrok. `doctrine/dbal ^4.4` sudah ada di `composer.json:11`, jadi `->change()` berfungsi.

- [ ] **Step 2: Cek DB_HOST lagi sebelum migrate**

```bash
cd "C:/codes/Semester 4/LawangSewu" && grep -E "^DB_HOST" .env
```

Expected: `DB_HOST=127.0.0.1`

- [ ] **Step 3: Jalankan migration**

```bash
cd "C:/codes/Semester 4/LawangSewu" && php artisan migrate
```

Expected: baris `2026_07_23_000001_make_email_nullable_on_users_table .... DONE`

- [ ] **Step 4: Verifikasi kolom sudah nullable**

```bash
cd "C:/codes/Semester 4/LawangSewu" && php artisan tinker --execute="print_r(DB::select('SHOW COLUMNS FROM users WHERE Field = \"email\"'));"
```

Expected: `[Null] => YES`

Jangan commit. Perlu diingat saat pemilik proyek nanti meng-commit: berkas migration inilah satu-satunya yang berpengaruh ke hosting saat `php artisan migrate` dijalankan di sana, jadi layak dipisah ke commit tersendiri.

---

### Task 3: UserFactory hasilkan username unik

Dikerjakan sebelum test disentuh, karena factory yang belum menghasilkan username akan menjatuhkan test mana pun yang memakainya.

**Files:**
- Modify: `database/factories/UserFactory.php:27-33`

**Interfaces:**
- Consumes: —
- Produces: `User::factory()->create()` menghasilkan user dengan `username` unik.

- [ ] **Step 1: Tambahkan username ke definition**

Di `database/factories/UserFactory.php`, ganti isi `definition()` menjadi:

```php
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }
```

- [ ] **Step 2: Verifikasi factory menghasilkan username valid**

`fake()->userName()` bisa memuat titik atau tanda hubung, sementara aturan sistem hanya mengizinkan `[a-zA-Z0-9_]`. Cek apakah perlu disanitasi:

```bash
cd "C:/codes/Semester 4/LawangSewu" && php artisan tinker --execute="for(\$i=0;\$i<10;\$i++){ echo fake()->unique()->userName().PHP_EOL; }"
```

Kalau ada keluaran yang memuat karakter selain huruf/angka/underscore, ganti baris username di Step 1 menjadi:

```php
            'username' => fake()->unique()->bothify('user_#####'),
```

Aturan `[a-zA-Z0-9_]` hanya divalidasi di endpoint HTTP, bukan di model, jadi factory secara teknis tetap jalan. Tapi menyamakannya membuat data test mencerminkan data asli.

Jangan commit.

---

### Task 4: Sesuaikan test — tahap merah

Menutup 3 kegagalan baseline sekaligus menuliskan perilaku baru yang belum ada implementasinya. Task ini **berakhir dengan test merah, dan itu memang tujuannya.**

**Files:**
- Modify: `tests/Feature/UserManagementTest.php` (seluruh pembuatan user, dua test login, dua test baru)

**Interfaces:**
- Consumes: skema email nullable dari Task 2, factory dari Task 3.
- Produces: 4 test merah yang mendefinisikan kontrak untuk Task 5 — akun kasir dibuat tanpa `email`, dan `username` duplikat ditolak.

- [ ] **Step 1: Tambahkan username ke helper `owner()`**

```php
    private function owner(): User
    {
        return User::create([
            'name' => 'Owner',
            'username' => 'owner',
            'password' => Hash::make('secret123'),
            'role' => 'owner',
            'branch_id' => null,
        ]);
    }
```

- [ ] **Step 2: Perbaiki `test_owner_can_create_cabang_and_kasir_together`**

```php
    public function test_owner_can_create_cabang_and_kasir_together(): void
    {
        Sanctum::actingAs($this->owner());

        $res = $this->postJson('/api/users', [
            'branch_name' => 'Cabang Pandanaran',
            'name' => 'Budi',
            'username' => 'budi',
            'password' => 'rahasia123',
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('branches', ['name' => 'Cabang Pandanaran']);

        $branch = Branch::where('name', 'Cabang Pandanaran')->first();
        $this->assertDatabaseHas('users', [
            'username' => 'budi',
            'role' => 'kasir',
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
    }
```

- [ ] **Step 3: Perbaiki `test_creating_cabang_is_one_to_one`**

```php
    public function test_creating_cabang_is_one_to_one(): void
    {
        Sanctum::actingAs($this->owner());

        $this->postJson('/api/users', [
            'branch_name' => 'Cabang A',
            'name' => 'Budi',
            'username' => 'budi',
            'password' => 'rahasia123',
        ])->assertStatus(201);

        $this->postJson('/api/users', [
            'branch_name' => 'Cabang B',
            'name' => 'Siti',
            'username' => 'siti',
            'password' => 'rahasia123',
        ])->assertStatus(201);

        // Each kasir owns exactly one distinct branch — never shared.
        $branchIds = User::where('role', 'kasir')->pluck('branch_id');
        $this->assertCount(2, $branchIds);
        $this->assertCount(2, $branchIds->unique());
    }
```

- [ ] **Step 4: Perbaiki `test_kasir_cannot_manage_users`**

```php
    public function test_kasir_cannot_manage_users(): void
    {
        $branch = $this->branch();
        $kasir = User::create([
            'name' => 'Kasir',
            'username' => 'kasir',
            'password' => Hash::make('rahasia123'),
            'role' => 'kasir',
            'branch_id' => $branch->id,
        ]);
        Sanctum::actingAs($kasir);

        $this->getJson('/api/users')->assertStatus(403);
        $this->postJson('/api/users', [
            'name' => 'X',
            'username' => 'x_kasir',
            'password' => 'rahasia123',
            'branch_id' => $branch->id,
        ])->assertStatus(403);
    }
```

- [ ] **Step 5: Perbaiki `test_inactive_kasir_cannot_login_active_can`**

Login sekarang memakai `username`, dan error validasinya juga berada di key `username`.

```php
    public function test_inactive_kasir_cannot_login_active_can(): void
    {
        $branch = $this->branch();
        $kasir = User::create([
            'name' => 'Kasir',
            'username' => 'kasir',
            'password' => Hash::make('rahasia123'),
            'role' => 'kasir',
            'branch_id' => $branch->id,
            'is_active' => false,
        ]);

        $this->postJson('/api/login', [
            'username' => 'kasir',
            'password' => 'rahasia123',
        ])->assertStatus(422)->assertJsonValidationErrors('username');

        $kasir->update(['is_active' => true]);

        $this->postJson('/api/login', [
            'username' => 'kasir',
            'password' => 'rahasia123',
        ])->assertStatus(200)->assertJsonStructure(['token', 'user']);
    }
```

- [ ] **Step 6: Perbaiki sisa pembuatan user**

Empat test berikut membuat user dengan `'email' => '...'`. Ganti setiap baris email itu menjadi `'username' => '...'` dengan nilai unik per test:

| Test | Ganti menjadi |
| --- | --- |
| `test_editing_kasir_without_branch_creates_and_assigns_one` | `'username' => 'kasir_lama',` |
| `test_owner_can_delete_cabang_and_kasir` | `'username' => 'kasir_hapus',` |
| `test_deleting_kasir_keeps_orders_but_unlinks_them` | `'username' => 'kasir_order',` |
| `test_owner_can_deactivate_kasir` | `'username' => 'kasir_nonaktif',` |

- [ ] **Step 7: Tambahkan test username duplikat ditolak**

Pengujian yang benar-benar baru — berkas ini tidak pernah punya test keunikan, baik untuk email maupun username. Username sekarang menentukan siapa yang bisa login, jadi keunikannya perlu dijaga test. Tambahkan sebelum penutup kelas:

```php
    public function test_duplicate_username_is_rejected(): void
    {
        Sanctum::actingAs($this->owner());

        $this->postJson('/api/users', [
            'branch_name' => 'Cabang A',
            'name' => 'Budi',
            'username' => 'budi',
            'password' => 'rahasia123',
        ])->assertStatus(201);

        $this->postJson('/api/users', [
            'branch_name' => 'Cabang B',
            'name' => 'Siti',
            'username' => 'budi',
            'password' => 'rahasia123',
        ])->assertStatus(422)->assertJsonValidationErrors('username');
    }
```

- [ ] **Step 8: Tambahkan test akun bisa dibuat tanpa email**

Ini yang membuktikan tujuan utama Task 2 dan 4 tercapai.

```php
    public function test_kasir_can_be_created_without_email(): void
    {
        Sanctum::actingAs($this->owner());

        $this->postJson('/api/users', [
            'branch_name' => 'Cabang Tanpa Email',
            'name' => 'Budi',
            'username' => 'budi',
            'password' => 'rahasia123',
        ])->assertStatus(201);

        $kasir = User::where('username', 'budi')->first();
        $this->assertNotNull($kasir);
        $this->assertNull($kasir->email);

        // Akun hasil pembuatan tanpa email tetap bisa login.
        $this->postJson('/api/login', [
            'username' => 'budi',
            'password' => 'rahasia123',
        ])->assertStatus(200)->assertJsonStructure(['token', 'user']);
    }
```

- [ ] **Step 9: Jalankan test — harus merah, dan merahnya harus yang benar**

```bash
cd "C:/codes/Semester 4/LawangSewu" && php artisan test --filter=UserManagementTest 2>&1 | tail -30
```

Expected: **7 lolos, 4 gagal**. Keempat yang gagal harus persis ini, dan semuanya gagal karena `UserController` masih mewajibkan `email`:

| Test | Kegagalan yang diharapkan |
| --- | --- |
| `owner can create cabang and kasir together` | 422 — `The email field is required.` |
| `creating cabang is one to one` | 422 — `The email field is required.` |
| `duplicate username is rejected` | 422 tapi error di key `email`, bukan `username` |
| `kasir can be created without email` | 422 — `The email field is required.` |

Ini tahap merah TDD. **Jangan perbaiki di task ini.** Task 5 yang menghijaukannya.

Kalau ada test yang gagal di luar keempat itu, atau gagal dengan alasan berbeda (misalnya kolom NOT NULL), berhenti dan laporkan — berarti ada asumsi yang meleset, bukan tahap merah yang diharapkan.

Jangan commit.

---

### Task 5: Lepas email dari UserController & AuthController

Tahap hijau. Task 4 sudah meninggalkan 4 test merah; task ini yang menghijaukannya.

**Files:**
- Modify: `app/Http/Controllers/UserController.php:36,49,59,78,119,139,155`
- Modify: `app/Http/Controllers/AuthController.php:41-44`

**Interfaces:**
- Consumes: kolom email nullable dari Task 2, test merah dari Task 4.
- Produces: `POST /api/users` dan `PUT /api/users/{user}` tidak lagi menerima atau mewajibkan `email`. Audit log mencatat `username`.

- [ ] **Step 1: Hapus validasi email di `store`**

Di `app/Http/Controllers/UserController.php`, blok `$data = $request->validate([...])` dalam `store()` menjadi:

```php
        $data = $request->validate( [
            'branch_name' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'username' => 'required|string|min:3|max:255|regex:/^[a-zA-Z0-9_]+$/|unique:users,username',
            'password' => 'required|string|min:6',
        ] );
```

- [ ] **Step 2: Hapus email dari `User::create`**

Dalam `store()`, blok `return User::create([...])` menjadi:

```php
            return User::create( [
                'name' => $data['name'],
                'username' => $data['username'],
                'password' => Hash::make( $data['password'] ),
                'role' => 'kasir',
                'branch_id' => $branch->id,
                'is_active' => true,
            ] );
```

- [ ] **Step 3: Audit log `store` mencatat username**

Dalam `store()`, panggilan `AuditLog::record` menjadi:

```php
        AuditLog::record( $request->user()->id, 'create_user', 'User', $user->id, [
            'name' => $user->name,
            'username' => $user->username,
            'branch_id' => $user->branch_id,
        ] );
```

- [ ] **Step 4: Hapus validasi email di `update`**

Dalam `update()`, hapus baris aturan `'email' => [...]` sehingga blok validasinya menjadi:

```php
        $data = $request->validate( [
            'branch_name' => 'sometimes|required|string|max:255',
            'name' => 'sometimes|required|string|max:255',
            'username' => [ 'sometimes', 'required', 'string', 'min:3', 'max:255', 'regex:/^[a-zA-Z0-9_]+$/', Rule::unique( 'users', 'username' )->ignore( $user->id ) ],
            'password' => 'nullable|string|min:6',
            'is_active' => 'sometimes|boolean',
        ] );
```

- [ ] **Step 5: Audit log `update` mencatat username**

Dalam `update()`, panggilan `AuditLog::record` menjadi:

```php
        AuditLog::record( $request->user()->id, 'update_user', 'User', $user->id, [
            'name' => $user->name,
            'username' => $user->username,
            'branch_id' => $user->branch_id,
            'is_active' => $user->is_active,
        ] );
```

- [ ] **Step 6: Snapshot & audit log `destroy` mencatat username**

Dalam `destroy()`, ganti `$snapshot` dan `AuditLog::record` menjadi:

```php
        // Snapshot untuk audit log sebelum record-nya hilang.
        $snapshot = [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'branch_id' => $user->branch_id,
        ];
```

dan

```php
        AuditLog::record( $request->user()->id, 'delete_user', 'User', $snapshot['id'], [
            'name' => $snapshot['name'],
            'username' => $snapshot['username'],
            'branch_id' => $snapshot['branch_id'],
        ] );
```

- [ ] **Step 7: Audit log login mencatat username**

Di `app/Http/Controllers/AuthController.php`, panggilan `AuditLog::record` dalam `login()` menjadi:

```php
        AuditLog::record($user->id, 'login', 'User', $user->id, [
            'username' => $user->username,
            'role' => $user->role,
        ]);
```

- [ ] **Step 8: Pastikan tidak ada sisa referensi email**

```bash
cd "C:/codes/Semester 4/LawangSewu" && grep -n "email" app/Http/Controllers/UserController.php app/Http/Controllers/AuthController.php
```

Expected: tidak ada keluaran sama sekali.

- [ ] **Step 9: Jalankan seluruh test — sekarang harus hijau**

```bash
cd "C:/codes/Semester 4/LawangSewu" && php artisan test 2>&1 | tail -20
```

Expected: `UserManagementTest` 11 lolos, 0 gagal, dan tidak ada kegagalan di berkas lain. Bandingkan dengan 4 kegagalan di akhir Task 4 — keempatnya harus hilang.

Kalau masih ada yang merah, gunakan superpowers:systematic-debugging sebelum mengubah apa pun.

Jangan commit.

---

### Task 6: Hapus field email dari UserManagePage

**Files:**
- Modify: `src/pages/UserManagePage.jsx:23,40,50,69,78,87,206-216,309-321` (repo frontend)

**Interfaces:**
- Consumes: `POST /api/users` dan `PUT /api/users/{user}` tanpa `email` dari Task 4.
- Produces: form kelola akun tanpa isian email.

- [ ] **Step 1: Hapus state email**

Hapus baris 23 seluruhnya:

```jsx
  const [email, setEmail] = useState("");
```

- [ ] **Step 2: Hapus reset email di `openCreate`**

Hapus baris `setEmail("");` di dalam `openCreate`, sehingga fungsinya menjadi:

```jsx
  const openCreate = () => {
    setEditing(null);
    setBranchName("");
    setName("");
    setUsername("");
    setPassword("");
    setShowForm(true);
  };
```

- [ ] **Step 3: Hapus pengisian email di `openEdit`**

Hapus baris `setEmail(user.email || "");`, sehingga fungsinya menjadi:

```jsx
  const openEdit = (user) => {
    setEditing(user);
    setBranchName(user.branch?.name || "");
    setName(user.name || "");
    setUsername(user.username || "");
    setPassword("");
    setShowForm(true);
  };
```

- [ ] **Step 4: Hapus validasi & payload email di `handleSubmit`**

Hapus baris `if (!email.trim()) return alert("Email harus diisi");` dan kedua baris `email: email.trim(),`. Hasilnya:

```jsx
  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!branchName.trim()) return alert("Nama cabang harus diisi");
    if (!name.trim()) return alert("Nama kasir harus diisi");
    if (!username.trim()) return alert("Username harus diisi");
    if (username.trim().length < 3)
      return alert("Username minimal 3 karakter");
    if (!/^[a-zA-Z0-9_]+$/.test(username.trim()))
      return alert("Username hanya boleh berisi huruf, angka, dan underscore");
    if (!editing && !password) return alert("Password harus diisi");
    setSaving(true);
    try {
      if (editing) {
        const payload = {
          branch_name: branchName.trim(),
          name: name.trim(),
          username: username.trim(),
        };
        if (password) payload.password = password;
        await updateUser(editing.id, payload);
      } else {
        await createUser({
          branch_name: branchName.trim(),
          name: name.trim(),
          username: username.trim(),
          password,
        });
      }
      closeForm();
      loadUsers();
    } catch (err) {
      const errors = err.response?.data?.errors;
      const first = errors ? Object.values(errors)[0]?.[0] : null;
      alert(first || err.response?.data?.message || "Gagal menyimpan data");
    } finally {
      setSaving(false);
    }
  };
```

- [ ] **Step 5: Sederhanakan baris info di daftar akun**

Username **tidak** dijamin ada. Migration `2026_06_29_000002_add_username_to_users_table.php` sengaja membuat kolomnya nullable ("user lama belum punya username"), dan hanya validasi `POST`/`PUT` yang mewajibkannya — baris lama tidak di-backfill. Jadi penjagaan lamanya tetap perlu, hanya bentuknya berubah: dulu jatuh ke email sebagai penanda, sekarang email sudah tidak ada.

Akun ber-`username` NULL **tidak bisa login sama sekali** (`AuthController` mencari user lewat `username`), jadi keadaan itu harus terlihat jelas oleh owner, bukan disembunyikan. Ganti blok `<p className="text-xs text-slate-400 ...">` menjadi:

```jsx
                  <p className="text-xs text-slate-400 mt-1 flex items-center gap-1.5">
                    <UserRound className="w-3 h-3 shrink-0" />
                    {user.name}
                    <span className="text-slate-300">·</span>
                    {user.username ? (
                      <span className="font-medium text-slate-500">
                        @{user.username}
                      </span>
                    ) : (
                      <span className="font-semibold text-amber-600">
                        belum ada username — tidak bisa login
                      </span>
                    )}
                  </p>
```

- [ ] **Step 6: Hapus field Email dari form**

Hapus seluruh blok `<div>` yang memuat label "Email" beserta input `type="email"` (baris 309-321), yaitu dari `<div>` tepat sesudah blok Username sampai `</div>` penutupnya. Blok Username langsung disusul blok Password.

- [ ] **Step 7: Pastikan tidak ada sisa referensi email**

```bash
cd "C:/codes/Semester 4/LawangSewu-FrontEnd" && grep -rn "email" src/
```

Expected: tidak ada keluaran sama sekali.

- [ ] **Step 8: Pastikan build lolos**

```bash
cd "C:/codes/Semester 4/LawangSewu-FrontEnd" && npm run build
```

Expected: build selesai tanpa error. Ini yang menangkap sisa variabel `email` yang belum terhapus.

Jangan commit. `src/pages/CashierPage.jsx` di repo frontend punya perubahan lain yang tidak berkaitan — jangan disentuh sama sekali.

---

### Task 7: Verifikasi menyeluruh

**Files:** tidak ada perubahan berkas.

**Interfaces:**
- Consumes: seluruh task sebelumnya.
- Produces: bukti bahwa instalasi bersih maupun alur UI berfungsi.

- [ ] **Step 1: Cek DB_HOST sebelum migrate:fresh**

`migrate:fresh` menghapus seluruh tabel. Wajib dipastikan menunjuk lokal.

```bash
cd "C:/codes/Semester 4/LawangSewu" && grep -E "^DB_HOST|^DB_DATABASE" .env
```

Expected: `DB_HOST=127.0.0.1` dan `DB_DATABASE=LawangSewu`. **Jangan lanjut ke Step 2 kalau bukan itu.**

- [ ] **Step 2: Instalasi bersih**

```bash
cd "C:/codes/Semester 4/LawangSewu" && php artisan migrate:fresh --seed
```

Expected: seluruh migration `DONE`, seeder selesai tanpa error.

- [ ] **Step 3: Verifikasi akun hasil seeder**

```bash
cd "C:/codes/Semester 4/LawangSewu" && php artisan tinker --execute="foreach(App\Models\User::all() as \$u){ echo \$u->id.' | '.\$u->name.' | username='.var_export(\$u->username,true).PHP_EOL; }"
```

Expected: `username='owner'`, `username='kasir'`, `username='kasir2'`.

- [ ] **Step 4: Login manual sebagai owner**

Jalankan backend dan frontend, login dengan `owner` / `password123`.

Expected: masuk ke dashboard.

- [ ] **Step 5: Buat akun kasir baru tanpa email**

Sebagai owner, buka halaman Kelola Cabang & Akun, tambah cabang + akun kasir baru. Perhatikan form sudah tidak punya isian Email.

Expected: tersimpan, muncul di daftar dengan format `Nama · @username`.

- [ ] **Step 6: Login memakai akun yang baru dibuat**

Logout, lalu login memakai username dan password akun kasir barusan.

Expected: berhasil masuk. Ini menutup alur end-to-end: dibuat tanpa email, tetap bisa login.

- [ ] **Step 7: Suite test terakhir**

```bash
cd "C:/codes/Semester 4/LawangSewu" && php artisan test 2>&1 | tail -10
```

Expected: seluruhnya hijau, tanpa satu pun kegagalan.

---

## Catatan Commit & Push

Seluruh pekerjaan ditinggalkan di working tree kedua repo — pemilik proyek yang meninjau dan meng-commit sendiri.

Saat nanti di-commit, `database/migrations/2026_07_23_000001_make_email_nullable_on_users_table.php` layak dipisah ke commit tersendiri. Berkas itu satu-satunya yang berpengaruh ke hosting: begitu `php artisan migrate` dijalankan di sana, migration itu ikut jalan. Sifatnya melonggarkan constraint sehingga tidak menghapus atau mengubah data yang sudah ada, tapi tetap keputusan sadar yang harus diambil, bukan efek samping.

Di repo frontend, `src/pages/CashierPage.jsx` sudah termodifikasi sejak sebelum pekerjaan ini dan tidak berkaitan — jangan ikut di-stage.
