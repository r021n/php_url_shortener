# Panduan Fase 1 — Setup Proyek & Database

> **Target waktu:** ±30 menit
> **Setelah fase ini:** `database.sqlite` terbentuk, `index.php` tampil tanpa error koneksi, tabel `links` bisa di-query dengan struktur yang benar.
> **Catatan:** panduan ini disusun dari pengerjaan nyata yang sudah di-lint, diuji, dan dijalankan di PHP 8.5.12 (Windows 11) — semua output CLI di bawah adalah output asli hasil eksekusi.

---

## 0. Peta Fase 1

| Langkah | Yang dikerjakan | File |
|---|---|---|
| 1 | Cek lingkungan | (terminal) |
| 2 | Koneksi PDO ke SQLite | `config/database.php` |
| 3 | Skrip setup tabel | `setup.php` |
| 4 | Halaman utama minimal | `index.php` |
| 5 | Stub hapus (untuk Fase 6) | `hapus.php` |
| 6 | Jalankan setup | (terminal) |
| 7 | Verifikasi | `tests/phase1_check.php` + browser |

**Struktur folder setelah Fase 1 selesai:**

```
php_url_shortener/
├── config/
│   └── database.php      ← koneksi PDO (Langkah 2)
├── guides/
│   └── phase1.md         ← file ini
├── tests/
│   └── phase1_check.php  ← skrip verifikasi (SUDAH ADA, tinggal jalankan)
├── index.php             ← halaman utama (Langkah 4)
├── setup.php             ← skrip setup DB (Langkah 3)
├── hapus.php             ← stub (Langkah 5)
└── database.sqlite       ← dibuat OTOMATIS oleh setup (Langkah 6)
```

---

## 1. Cek Lingkungan (1 menit)

Pastikan PHP 8.2+ terpasang dan ekstensi SQLite aktif. Jalankan di terminal dari folder proyek:

```bash
php -v
```

**Yang muncul:**

```
PHP 8.5.12 (cli) (built: Oct 20 2026 04:19:35) (ZTS Visual C++ 2022 x64)
...
```

Lalu cek ekstensi database:

```bash
php -m | findstr /i sqlite
```

> Linux/macOS: `php -m | grep -i sqlite`

**Yang muncul** (harus ada **dua-duanya**):

```
pdo_sqlite
sqlite3
```

Jika `pdo_sqlite` tidak muncul → buka `php.ini`, hapus tanda `;` pada `extension=pdo_sqlite`, lalu cek `extension_dir` menunjuk ke folder `ext` PHP Anda. (Detail lengkap ada di `project.md` → "Perbaikan Konfigurasi yang Dilakukan".)

---

## 2. Buat `config/database.php` — Koneksi PDO

**Lokasi:** `config/database.php` (buat folder `config` baru).

**Tulis kode ini:**

```php
<?php

declare(strict_types=1);

/**
 * Koneksi PDO ke SQLite (Fase 1).
 *
 * File database: database.sqlite (di root proyek).
 * SQLite membuat file tersebut OTOMATIS saat pertama kali terhubung,
 * sehingga tidak perlu membuat database secara manual.
 *
 * Dokumentasi:
 * - https://www.php.net/manual/en/book.pdo.php
 * - https://www.php.net/manual/en/ref.pdo-sqlite.connection.php
 */

/**
 * Mengembalikan instance PDO yang sudah terhubung ke database.sqlite.
 *
 * Koneksi disimpan secara static agar hanya dibuat SATU KALI per request
 * meskipun fungsi ini dipanggil berkali-kali.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // Path absolut ke file database (folder root proyek).
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database.sqlite';
    $dsn  = 'sqlite:' . $path;

    try {
        $pdo = new PDO($dsn, null, null, [
            // Lempar PDOException saat query gagal (default sejak PHP 8.0,
            // disetel eksplisit agar perilakunya jelas & konsisten).
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            // Hasil SELECT langsung berupa array asosiatif.
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Prepared statement asli (bukan simulasi PDO).
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        // Detail error dicatat ke log server, TIDAK ditampilkan ke browser.
        error_log('Koneksi database gagal: ' . $e->getMessage());
        throw new RuntimeException(
            'Koneksi database gagal. Pastikan file database.sqlite bisa dibuat/ditulis di root proyek.',
            0,
            $e
        );
    }

    return $pdo;
}
```

**Kenapa begini (poin penting):**

* DSN SQLite = `sqlite:` + **path absolut file** — dari [PDO_SQLITE DSN](https://www.php.net/manual/en/ref.pdo-sqlite.connection.php). File dibuat otomatis jika belum ada.
* `dirname(__DIR__)` = folder root proyek, jadi path tetap benar walau file ini ada di dalam `config/`.
* `static $pdo` = koneksi dipakai ulang dalam satu request (hemat resource).
* `PDO::ATTR_ERRMODE => ERRMODE_EXCEPTION` memang sudah default sejak PHP 8.0, tapi disetel eksplisit supaya perilakunya jelas — kesalahan query melempar `PDOException`, tidak gagal diam-diam.
* `PDO::ATTR_EMULATE_PREPARES => false` = prepared statement **asli** (aman dari SQL injection).
* Pesan error detail **tidak** ditampilkan ke browser (cukup di `error_log`), sesuai praktik keamanan PHP.

---

## 3. Buat `setup.php` — Skrip Setup Database

**Lokasi:** `setup.php` (root proyek).

**Tulis kode ini:**

```php
<?php

declare(strict_types=1);

/**
 * Skrip setup database (Fase 1).
 *
 * Jalankan SEKALI dari terminal:
 *   php setup.php
 *
 * Atau buka http://localhost:8000/setup.php saat server berjalan.
 *
 * Efek:
 * 1. Membuat database.sqlite (jika belum ada) — otomatis oleh SQLite.
 * 2. Membuat tabel links dengan struktur yang disyaratkan brief.
 *
 * Aman dijalankan berulang kali (CREATE TABLE IF NOT EXISTS).
 */

require __DIR__ . '/config/database.php';

$pdo = db();

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS links (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        original_url TEXT    NOT NULL,
        short_code   TEXT    NOT NULL UNIQUE,
        clicks       INTEGER DEFAULT 0,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    )'
);

// UNIQUE pada short_code sudah otomatis membuat index, tidak perlu index tambahan.

echo 'Setup selesai: database.sqlite siap dan tabel links sudah dibuat.' . PHP_EOL;
```

**Kenapa begini:**

* `CREATE TABLE IF NOT EXISTS` → skrip **idempoten**, aman dijalankan berkali-kali tanpa merusak data.
* Struktur kolom **persis** seperti brief (`project.md` → Fase 1). `UNIQUE` pada `short_code` sekaligus menjadi index pencarian untuk fitur redirect nanti.
* `INTEGER PRIMARY KEY AUTOINCREMENT` = id auto-naik, tidak pernah terpakai ulang.
* `CURRENT_TIMESTAMP` = nilai default `created_at` diisi otomatis SQLite saat baris dibuat.

---

## 4. Buat `index.php` — Halaman Utama Minimal

**Lokasi:** `index.php` (root proyek).

**Tulis kode ini:**

```php
<?php

declare(strict_types=1);

/**
 * Halaman utama (Fase 1: baru sebatas "memuat tanpa error koneksi").
 * Form input, validasi, dan dashboard menyusul di Fase 2-5.
 */

require __DIR__ . '/config/database.php';

// Pastikan koneksi database berhasil dibuat (melempar exception jika gagal).
db();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>URL Shortener</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 min-h-screen">
    <main class="max-w-3xl mx-auto px-4 py-10">
        <h1 class="text-2xl font-bold text-slate-800">URL Shortener</h1>
        <p class="mt-2 text-slate-600">
            Koneksi database berhasil. Form input &amp; dashboard menyusul pada fase berikutnya.
        </p>
    </main>
</body>
</html>
```

**Kenapa begini:**

* `require` + `db()` di paling atas = koneksi divalidasi **sebelum** HTML dikirim. Kalau koneksi gagal, error langsung ketahuan (bukan halaman kosong).
* Tailwind via CDN sudah dipasang sekarang agar Fase 2 tinggal menambah form.
* `&amp;` pada `&` = escape HTML dasar (kebiasaan baik anti-XSS, dipakai massal di Fase 5).

---

## 5. Buat `hapus.php` — Stub untuk Fase 6

**Lokasi:** `hapus.php` (root proyek).

**Tulis kode ini** (baru placeholder — implementasi penuh menyusul di Fase 6):

```php
<?php

declare(strict_types=1);

/**
 * Penghapusan link (Fase 6).
 *
 * TODO Fase 6:
 * - Terima POST berisi id dari tabel links.
 * - Jalankan: DELETE FROM links WHERE id = :id (prepared statement).
 * - Redirect kembali ke index.php.
 */

// Belum ada aksi apa pun pada fase ini.
http_response_code(501);
echo 'Belum diimplementasikan (menyusul di Fase 6).';
```

> Sengaja dibuat sekarang agar struktur proyek sesuai brief sejak awal, dan Fase 6 tinggal mengisi isinya.

---

## 6. Jalankan Setup

**Perintah** (dari root proyek):

```bash
php setup.php
```

**Yang muncul:**

```
Setup selesai: database.sqlite siap dan tabel links sudah dibuat.
```

**Cek hasilnya:**

```bash
dir database.sqlite
```

**Yang muncul** (ada baris file-nya):

```
22/10/2026  17:07             8.192 database.sqlite
```

> Jalankan `php setup.php` sekali lagi untuk menguji idempoten — output tetap sama, data tidak rusak.

---

## 7. Verifikasi (Wajib Sebelum Lanjut)

### 7a. Lint (cek sintaks semua file)

```bash
php -l config\database.php & php -l setup.php & php -l index.php & php -l hapus.php
```

> Linux/macOS: ganti `&` menjadi `;` dan `\` menjadi `/`.

**Yang muncul** (semua harus `No syntax errors`):

```
No syntax errors detected in config\database.php
No syntax errors detected in setup.php
No syntax errors detected in index.php
No syntax errors detected in hapus.php
```

### 7b. Jalankan skrip verifikasi otomatis

File `tests/phase1_check.php` **sudah tersedia** di repo (tidak perlu dibuat ulang). Jalankan:

```bash
php tests\phase1_check.php
```

**Yang muncul** (semua `[PASS]`, exit code 0):

```
[PASS] config/database.php ada
[PASS] database.sqlite ada (jalankan: php setup.php)
[PASS] fungsi db() terdefinisi di config/database.php
[PASS] db() mengembalikan instance PDO
[PASS] driver PDO adalah sqlite
[PASS] ERRMODE_EXCEPTION aktif
[PASS] panggilan db() berikutnya memakai koneksi yang sama (static)
[PASS] SELECT * FROM links berhasil dieksekusi
[PASS] urutan kolom tabel links sesuai brief
[PASS] kolom id bertipe INTEGER
[PASS] kolom id NOT NULL = 0
[PASS] kolom id PRIMARY KEY = 1
[PASS] kolom id default = NULL
[PASS] kolom original_url bertipe TEXT
[PASS] kolom original_url NOT NULL = 1
[PASS] kolom original_url PRIMARY KEY = 0
[PASS] kolom original_url default = NULL
[PASS] kolom short_code bertipe TEXT
[PASS] kolom short_code NOT NULL = 1
[PASS] kolom short_code PRIMARY KEY = 0
[PASS] kolom short_code default = NULL
[PASS] kolom clicks bertipe INTEGER
[PASS] kolom clicks NOT NULL = 0
[PASS] kolom clicks PRIMARY KEY = 0
[PASS] kolom clicks default = '0'
[PASS] kolom created_at bertipe DATETIME
[PASS] kolom created_at NOT NULL = 0
[PASS] kolom created_at PRIMARY KEY = 0
[PASS] kolom created_at default = 'CURRENT_TIMESTAMP'
[PASS] short_code memiliki UNIQUE constraint
[PASS] INSERT berhasil dan lastInsertId() mengembalikan id
[PASS] baris yang di-insert bisa dibaca kembali
[PASS] nilai default clicks = 0
[PASS] created_at terisi otomatis (CURRENT_TIMESTAMP)
[PASS] INSERT short_code duplikat ditolak (UNIQUE bekerja)
[PASS] data uji berhasil dibersihkan

Semua pemeriksaan Fase 1 LULUS.
```

**Apa yang diuji skrip ini:**

1. File & koneksi: `config/database.php`, `database.sqlite`, fungsi `db()`, driver `sqlite`, `ERRMODE_EXCEPTION`, koneksi static (tidak dibuat ulang).
2. `SELECT * FROM links` berhasil.
3. Struktur kolom **persis** brief: nama, tipe, NOT NULL, default, PRIMARY KEY, dan UNIQUE `short_code`.
4. Uji tulis-baca: INSERT → cek `clicks` default `0` & `created_at` terisi → INSERT duplikat **ditolak** → data uji dibersihkan (aman diulang).

> **Catatan soal `id NOT NULL = 0`:** SQLite melaporkan flag `NOT NULL = 0` untuk `INTEGER PRIMARY KEY AUTOINCREMENT` karena kolom itu alias `rowid` (otomatis terisi, tidak bisa NULL). Ini normal, bukan kesalahan struktur.

### 7c. Uji via browser (built-in web server)

**Perintah** (dari root proyek):

```bash
php -S localhost:8000
```

**Yang muncul** (server berjalan, jangan ditutup):

```
[Thu Oct 22 17:07:50 2026] PHP 8.5.12 Development Server (http://localhost:8000) started
```

Buka **http://localhost:8000** di browser. **Yang muncul:** halaman dengan judul **"URL Shortener"** dan teks **"Koneksi database berhasil. Form input & dashboard menyusul pada fase berikutnya."** — **tanpa** warning/error PHP apa pun.

Di terminal server akan muncul log request:

```
[Thu Oct 22 17:07:58 2026] [::1]:50529 Accepted
[Thu Oct 22 17:07:58 2026] [::1]:50529 [200]: GET /index.php
[Thu Oct 22 17:07:58 2026] [::1]:50529 Closing
```

**Opsional — cek dari terminal** (terminal kedua, server tetap jalan):

```bash
curl -s -o NUL -w "%{http_code}" http://localhost:8000/index.php
```

**Yang muncul:** `"200"`

```bash
curl -s -o NUL -w "%{http_code}" http://localhost:8000/hapus.php
```

**Yang muncul:** `"501"` ← sengaja, karena `hapus.php` masih stub.

> Linux/macOS: ganti `-o NUL` menjadi `-o /dev/null`.

Selesai menguji, tekan **Ctrl + C** di terminal server untuk mematikannya.

---

## 8. Troubleshooting Cepat

| Gejala | Penyebab | Solusi |
|---|---|---|
| `could not find driver` | `pdo_sqlite` belum aktif | Buka `php.ini`, uncomment `extension=pdo_sqlite`, cek `extension_dir` ke folder `ext` |
| `Koneksi database gagal...` | Folder proyek tidak bisa ditulis | Pindahkan proyek ke lokasi yang bisa ditulis (mis. `D:\coding\...`) |
| `no such table: links` | `setup.php` belum dijalankan | `php setup.php` |
| Halaman putih | Error di lokasi lain | Cek `php -l` tiap file & lihat log terminal server |
| `Failed to listen on localhost:8000` | Port dipakai proses lain | Ganti port: `php -S localhost:8001` |

---

## 9. Checklist Akhir Fase 1

- [ ] `php -m` menampilkan `pdo_sqlite` dan `sqlite3`
- [ ] `config/database.php`, `setup.php`, `index.php`, `hapus.php` ada dan lolos `php -l`
- [ ] `php setup.php` → output "Setup selesai..." dan `database.sqlite` terbentuk
- [ ] `php tests\phase1_check.php` → semua `[PASS]`
- [ ] `http://localhost:8000` tampil **tanpa error koneksi**
- [ ] `SELECT * FROM links` berjalan (dibuktikan skrip verifikasi)

**Semua centang? → Lanjut ke Fase 2 (Form Input & Validasi).** Struktur `index.php` di atas sudah disiapkan untuk menerima form di fase berikutnya.

---

## Referensi Dokumentasi (dipakai saat menyusun panduan ini)

* [PHP Manual — PDO](https://www.php.net/manual/en/book.pdo.php)
* [PHP Manual — PDO_SQLITE DSN](https://www.php.net/manual/en/ref.pdo-sqlite.connection.php)
* [PHP Manual — SQLite PDO Driver](https://www.php.net/manual/en/ref.pdo-sqlite.php) (catatan Windows: `libsqlite3.dll` harus ada di PATH)
* [PHP Manual — Built-in web server](https://www.php.net/manual/en/features.commandline.webserver.php)
* [PHP.Watch — PDO default error mode PHP 8.0](https://php.watch/versions/8.0/PDO-default-errmode)
