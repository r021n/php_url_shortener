# Panduan Fase 3 — Generate Short Link

> **Target waktu:** ±30 menit
> **Setelah fase ini:** setiap submit form menghasilkan short link unik 6 karakter (atau custom alias) dan menampilkannya ke pengguna dalam format `http://localhost:8000/?c=CODE`.
> **Catatan:** panduan ini disusun dari pengerjaan nyata yang sudah di-lint, diuji otomatis (29 pemeriksaan), dan dijalankan di PHP 8.5.12 (Windows 11). Semua output CLI di bawah adalah **output asli hasil eksekusi**.
> Perintah ditulis untuk **Command Prompt (cmd)**. Linux/macOS: ganti `\` menjadi `/`.

---

## 0. Peta Fase 3

| Langkah | Yang dikerjakan | File |
|---|---|---|
| 1 | Fungsi generator kode 6 karakter + cek tabrakan | `lib/link.php` *(update)* |
| 2 | Alur generate/simpan + tampilkan short link | `index.php` *(update)* |
| 3 | Lint semua file | (terminal) |
| 4 | Uji otomatis via CLI | `tests/phase3_check.php` *(baru)* |
| 5 | Uji tampilan di browser | (browser) |

**Struktur folder setelah Fase 3 selesai:**

```
php_url_shortener/
├── config/
│   └── database.php      ← koneksi PDO (tidak berubah)
├── lib/
│   ├── validation.php    ← validasi input (tidak berubah)
│   └── link.php          ← akses tabel links + generator (Langkah 1)
├── guides/
│   ├── phase1.md
│   ├── phase2.md
│   └── phase3.md         ← file ini
├── tests/
│   ├── phase1_check.php
│   ├── phase2_check.php
│   └── phase3_check.php  ← uji otomatis fase 3
├── index.php             ← form + generate + tampil short link (Langkah 2)
├── setup.php
├── hapus.php
└── database.sqlite
```

**Aturan main Fase 3 (hafalkan dulu):**

| Skenario | Yang terjadi |
|---|---|
| User **mengisi** custom alias | Alias dipakai sebagai `short_code` (setelah validasi & cek unik) |
| User **tidak** mengisi alias | Generator membuat kode acak 6 karakter heksadesimal |
| Kode hasil generate **sudah dipakai** | Generate ulang otomatis (maksimal 10 percobaan) |
| Semua percobaan generate gagal | Lempar `RuntimeException` (kasus sangat langka) |
| Berhasil disimpan | Tampilkan `http://localhost:8000/?c=CODE` di kotak hijau |

> **Penting:** Fase 3 **belum** mengimplementasikan redirect. Membuka `?c=CODE` baru akan berfungsi di **Fase 4**. Fokus Fase 3 adalah *membuat* dan *menampilkan* short link.

---

## 1. Tambah Generator di `lib/link.php`

**Lokasi:** `lib/link.php` (file yang sudah ada sejak Fase 2).

**Yang dilakukan:** tambahkan **satu fungsi baru** `generate_short_code()` di antara `alias_exists()` dan `save_link()`. Fungsi lama (`alias_exists`, `save_link`) **tidak diubah**.

**Tulis kode ini** (sisipkan setelah fungsi `alias_exists`):

```php
/**
 * Menghasilkan kode pendek 6 karakter heksadesimal yang unik.
 *
 * Kode dihasilkan dari bin2hex(random_bytes(3)) → 6 karakter.
 * Jika kode sudah dipakai (tabrakan), generate ulang hingga batas percobaan.
 *
 * @throws RuntimeException jika gagal mendapatkan kode unik.
 */
function generate_short_code(PDO $pdo, int $maxAttempts = 10): string
{
    for ($i = 0; $i < $maxAttempts; $i++) {
        $code = bin2hex(random_bytes(3));
        if (!alias_exists($pdo, $code)) {
            return $code;
        }
    }

    throw new RuntimeException('Gagal menghasilkan kode pendek unik. Coba lagi.');
}
```

**Kenapa begini (poin penting):**

* **`bin2hex(random_bytes(3))`** = 3 byte acak → 6 karakter heksadesimal (`0-9`, `a-f`). `random_bytes()` adalah generator kriptografis yang aman (CSPRNG), bukan `rand()`/`mt_rand()` yang bisa diprediksi. Ini adalah praktik standar industri untuk token/ID acak di PHP modern.
* **SRP (Single Responsibility):** generator hanya bertugas *membuat kode unik*. Ia tidak menyimpan apa pun ke database — penyimpanan tetap tanggung jawab `save_link()`. Pengecekan tabrakan memakai `alias_exists()` yang sudah ada, sehingga aturan "kode sudah dipakai?" hanya ditulis **sekali** (DRY).
* **Loop + batas percobaan:** ruang kode 6 heksadesimal = 16^6 ≈ 16,7 juta kombinasi. Peluang tabrakan sangat kecil, tetapi loop memastikan aplikasi tidak diam saja jika tabrakan terjadi. Batas 10 percobaan mencegah *infinite loop* jika ada masalah tak terduga (KISS).
* **`RuntimeException`:** jika 10 percobaan gagal, lebih baik berhenti dengan error jelas daripada menggantung. Pemanggil bisa menangkapnya jika perlu.
* **`declare(strict_types=1)`** sudah ada di file ini, jadi parameter dan return type `string` dipatuhi ketat.

> **Catatan PHP 8.5:** `random_bytes()` melempar `Random\RandomException` jika sumber entropi sistem gagal. Pada praktiknya ini hampir tidak pernah terjadi di mesin normal. Dokumentasi resmi: <https://www.php.net/manual/en/function.random-bytes.php> dan <https://www.php.net/manual/en/function.bin2hex.php>.

---

## 2. Update `index.php` — Generate & Tampilkan Short Link

**Lokasi:** `index.php` (root proyek).

**Yang dilakukan:** ubah **blok penyimpanan** di dalam `if ($errors === [])` dan **kotak sukses** di HTML. Bagian validasi, form, dan error box **tidak diubah**.

### 2a. Tambah inisialisasi `$shortUrl`

Cari baris:

```php
$errors = [];
$pesanSukses = '';
$urlInput = '';
$aliasInput = '';
```

Ganti menjadi:

```php
$errors = [];
$pesanSukses = '';
$shortUrl = '';
$urlInput = '';
$aliasInput = '';
```

### 2b. Ganti blok penyimpanan

Cari blok lama ini (di dalam `if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST')`):

```php
    if ($errors === []) {
        $alias = sanitize_alias($aliasInput);

        if ($alias !== '' && alias_exists($pdo, $alias)) {
            $errors[] = 'Alias "' . $alias . '" sudah dipakai. Silakan pilih alias lain.';
        } else {
            save_link($pdo, $urlInput, $alias !== '' ? $alias : null);
            $pesanSukses = 'URL berhasil disimpan ke database.';
            $urlInput = '';
            $aliasInput = '';
        }
    }
```

Ganti dengan:

```php
    if ($errors === []) {
        $alias = sanitize_alias($aliasInput);

        if ($alias !== '') {
            // Custom alias: validasi unik, lalu simpan.
            if (alias_exists($pdo, $alias)) {
                $errors[] = 'Alias "' . $alias . '" sudah dipakai. Silakan pilih alias lain.';
            } else {
                try {
                    save_link($pdo, $urlInput, $alias);
                    $shortCode = $alias;
                } catch (PDOException $e) {
                    if (str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                        $errors[] = 'Alias "' . $alias . '" sudah dipakai. Silakan pilih alias lain.';
                    } else {
                        throw $e;
                    }
                }
            }
        } else {
            // Tanpa alias: generate kode unik 6 karakter.
            try {
                $shortCode = generate_short_code($pdo);
                save_link($pdo, $urlInput, $shortCode);
            } catch (PDOException $e) {
                if (str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                    $errors[] = 'Terjadi tabrakan kode. Silakan coba lagi.';
                } else {
                    throw $e;
                }
            }
        }

        if ($errors === [] && isset($shortCode)) {
            $baseUrl = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost:8000');
            $shortUrl = $baseUrl . '/?c=' . $shortCode;
            $pesanSukses = 'URL berhasil disimpan ke database.';
            $urlInput = '';
            $aliasInput = '';
        }
    }
```

### 2c. Tampilkan short link di kotak sukses

Cari kotak sukses lama:

```php
        <?php if ($pesanSukses !== ''): ?>
            <div class="mt-6 rounded-lg border border-green-300 bg-screen-50 p-4">
                <p class="font-semibold text-green-800"><?php echo e($pesanSukses) ?></p>
            </div>
        <?php endif; ?>
```

Ganti dengan:

```php
        <?php if ($pesanSukses !== ''): ?>
            <div class="mt-6 rounded-lg border border-green-300 bg-green-50 p-4">
                <p class="font-semibold text-green-800"><?php echo e($pesanSukses) ?></p>
                <?php if ($shortUrl !== ''): ?>
                    <p class="mt-2">
                        <a href="<?php echo e($shortUrl); ?>" class="font-mono text-sky-600 underline"><?php echo e($shortUrl); ?></a>
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
```

**Kenapa begini (poin penting):**

* **Dua jalur yang jelas:** custom alias → cek unik → simpan; tanpa alias → generate → simpan. Ini menjaga alur mudah dibaca (KISS).
* **`generate_short_code($pdo)`** dipanggil **hanya** saat user tidak mengisi alias. Jika user mengisi alias, kita tidak perlu generate acak — alias itulah kodenya.
* **`try/catch PDOException`** adalah jaring pengaman untuk *race condition*: dua request bersamaan bisa lolos `alias_exists()` lalu salah satunya gagal saat `INSERT` karena kolom `short_code` ber-constraint `UNIQUE`. Pesan error yang ditampilkan tetap ramah, bukan halaman putih.
* **`str_contains($e->getMessage(), 'UNIQUE constraint failed')`** adalah cara sederhana mendeteksi pelanggaran UNIQUE di SQLite. (Jika ingin lebih portabel, cek `$e->getCode() === '23000'`.)
* **`$_SERVER['HTTP_HOST'] ?? 'localhost:8000'`** membuat link mengikuti host yang dipakai (mis. `localhost:8001` jika port 8000 sibuk), dengan fallback aman.
* **`e()` tetap dipakai** saat mencetak `$shortUrl` → konsisten anti-XSS, meskipun nilainya berasal dari server sendiri.
* **`save_link()` tidak diubah** — ia tetap menerima `?string $shortCode = null`. Parameter opsional ini menjaga kompatibilitas dengan Fase 2 dan test lamanya. Di Fase 3 kita selalu mengirim kode eksplisit, sehingga placeholder `pending_` tidak lagi dipakai pada alur normal.

---

## 3. Lint (Cek Sintaks)

**Perintah** (dari root proyek):

```bash
php -l lib\link.php & php -l index.php
```

**Yang muncul** (semua harus `No syntax errors`):

```
No syntax errors detected in lib\link.php
No syntax errors detected in index.php
```

> Ada error? Perhatikan nomor barisnya — biasanya kurung kurawal atau titik koma kurang saat menyalin kode.

---

## 4. Uji Otomatis via CLI

File `tests/phase3_check.php` sudah disediakan. Skrip ini:
* memeriksa fungsi generator ada dan menghasilkan 6 karakter heksadesimal,
* memastikan 10 kode berurutan unik dan tidak mengulang kode yang sudah ada di database,
* mensimulasikan request POST ke `index.php` (tanpa alias & dengan alias),
* memeriksa pesan sukses, format short link, jumlah baris database, dan penolakan alias duplikat/tidak sah,
* membersihkan data uji di akhir sehingga aman dijalankan berulang kali.

**Perintah** (dari root proyek):

```bash
php tests\phase3_check.php
```

**Yang muncul** (kurang lebih; semua harus `[PASS]`):

```
[PASS] index.php ada
[PASS] lib/link.php ada
[PASS] fungsi generate_short_code() terdefinisi
[PASS] fungsi alias_exists() terdefinisi
[PASS] fungsi save_link() terdefinisi
[PASS] generate_short_code: mengembalikan string
[PASS] generate_short_code: panjang tepat 6 karakter
[PASS] generate_short_code: hanya karakter heksadesimal
[PASS] generate_short_code: 10 kode berurutan unik
[PASS] generate_short_code: menghindari kode yang sudah ada
[PASS] POST tanpa alias: pesan sukses tampil
[PASS] POST tanpa alias: short link tampil di HTML
[PASS] POST tanpa alias: tepat 1 baris baru
[PASS] POST tanpa alias: baris tersimpan
[PASS] POST tanpa alias: short_code 6 karakter
[PASS] POST tanpa alias: short_code bukan placeholder
[PASS] POST tanpa alias: short_code muncul di HTML
[PASS] POST tanpa alias: format short link benar
[PASS] POST dengan alias: pesan sukses tampil
[PASS] POST dengan alias: short link tampil di HTML
[PASS] POST dengan alias: tepat 1 baris baru
[PASS] POST dengan alias: short_code = alias user
[PASS] POST alias duplikat: pesan error tampil
[PASS] POST alias duplikat: tidak ada baris baru
[PASS] POST alias tidak sah: pesan error tampil
[PASS] POST alias tidak sah: tidak ada baris baru
[PASS] POST URL invalid: pesan error tampil
[PASS] POST URL invalid: tidak ada baris baru
[PASS] data uji berhasil dibersihkan

Semua pemeriksaan Fase 3 LULUS.
```

> Jika ada `[FAIL]`, baca labelnya: biasanya karena `generate_short_code()` belum ada, `index.php` belum menampilkan `?c=`, atau `short_code` masih `pending_`.

### 4a. Cek isi database secara manual (opsional)

Ingin melihat baris yang baru dibuat? Jalankan skrip kecil berikut **satu baris penuh**:

```bash
php -r "$p=new PDO('sqlite:database.sqlite');foreach($p->query('SELECT id, original_url, short_code, clicks FROM links ORDER BY id DESC LIMIT 5') as $r){echo $r['id'],' | ',$r['original_url'],' | ',$r['short_code'],' | ',$r['clicks'],PHP_EOL;}"
```

**Yang muncul** (contoh; kode & urutan bisa berbeda):

```
12 | https://example.com/panduan-fase3 | a3f9c1 | 0
11 | https://example.com/promo | promo2026 | 0
```

> Perhatikan kolom `short_code`: kini berisi kode 6 karakter atau custom alias — **bukan** `pending_...` lagi. `clicks` masih `0` karena penghitung klik baru dibuat di Fase 4.

---

## 5. Uji di Browser (opsional tetapi disarankan)

**Perintah** (dari root proyek):

```bash
php -S localhost:8000
```

**Yang muncul:**

```
[Sat Oct 24 17:15:50 2026] PHP 8.5.12 Development Server (http://localhost:8000) started
```

Buka **http://localhost:8000** lalu uji:

| # | Aksi | Yang harus muncul |
|---|---|---|
| 1 | Isi URL `https://example.com/artikel` tanpa alias → Simpan | Kotak hijau berisi **URL berhasil disimpan ke database.** dan link `http://localhost:8000/?c=xxxxxx` (6 karakter acak). |
| 2 | Isi URL `https://example.com/promo` + alias `promo2026` → Simpan | Kotak hijau dengan link `http://localhost:8000/?c=promo2026`. |
| 3 | Ulangi alias `promo2026` | Kotak merah `Alias "promo2026" sudah dipakai...`; tidak ada baris baru. |
| 4 | Isi alias `my-alias` | Kotak merah `Alias hanya boleh huruf dan angka...`. |

> Klik link short link yang muncul: pada Fase 3 halaman akan menampilkan form lagi (belum redirect). Itu **normal** — redirect diimplementasikan di Fase 4.

Di terminal server, request tercatat seperti:

```
[Sat Oct 24 17:16:02 2026] [::1]:56656 [200]: POST /index.php
```

Tekan **Ctrl + C** untuk mematikan server.

---

## 6. Troubleshooting Cepat

| Gejala | Penyebab | Solusi |
|---|---|---|
| `Call to undefined function generate_short_code()` | Fungsi belum ditambahkan / `lib/link.php` tidak ter-require | Pastikan `require __DIR__ . '/lib/link.php';` ada di `index.php` dan fungsi disisipkan di file itu |
| `short_code` masih `pending_...` | Blok `index.php` belum diubah; masih memanggil `save_link(..., null)` | Terapkan Langkah 2b dengan benar |
| Short link tidak muncul di kotak hijau | `$shortUrl` belum diinisialisasi / HTML belum diubah | Cek Langkah 2a dan 2c |
| `Class "Random\RandomException" not found` | PHP terlalu lama (< 8.2) | Proyek ini menargetkan PHP 8.5; perbarui PHP |
| `UNIQUE constraint failed: links.short_code` muncul mentah | `try/catch` tidak menangkap dengan benar | Pastikan `catch (PDOException $e)` ada dan memeriksa pesan `UNIQUE constraint failed` |
| `Failed to listen on localhost:8000` | Port dipakai proses lain | Ganti port: `php -S localhost:8001`, atau matikan proses lama |
| Tampilan tidak rapi | Tailwind CDN belum termuat | Butuh koneksi internet; pastikan `<script src="https://cdn.tailwindcss.com">` ada di `<head>` |
| `database is locked` | Koneksi lain memegang database (DB Browser, skrip lain) | Tutup aplikasi/koneksi lain lalu ulangi |

---

## 7. Checklist Akhir Fase 3

- [ ] `lib/link.php` memiliki fungsi `generate_short_code()` dan lolos `php -l`
- [ ] `index.php` lolos `php -l`
- [ ] Submit **tanpa alias** → `short_code` berisi 6 karakter heksadesimal (bukan `pending_`)
- [ ] Submit **dengan custom alias** → `short_code` = alias yang diisi
- [ ] Setiap submit tanpa alias menghasilkan kode **berbeda** (tidak duplikat)
- [ ] Short link tampil di kotak hijau dengan format `http://localhost:8000/?c=CODE`
- [ ] Alias duplikat ditolak dan **tidak** menambah baris database
- [ ] `php tests\phase3_check.php` berakhir dengan `Semua pemeriksaan Fase 3 LULUS.`
- [ ] Output `$shortUrl` di HTML tetap melewati `e()` / `htmlspecialchars()` (anti-XSS)

**Semua centang? → Lanjut ke Fase 4 (Redirect & Click Counter).** Di Fase 4 Anda akan menangani route `/?c=CODE`, mencari `short_code` di database, menambah `clicks`, lalu `header("Location: ...")` ke URL asli.

---

## Referensi Dokumentasi (dipakai saat menyusun panduan ini)

* [PHP Manual — random_bytes()](https://www.php.net/manual/en/function.random-bytes.php) — CSPRNG; melempar `Random\RandomException` jika entropi gagal (PHP ≥ 8.2)
* [PHP Manual — bin2hex()](https://www.php.net/manual/en/function.bin2hex.php) — konversi byte acak menjadi string heksadesimal
* [PHP Manual — PDO prepared statements](https://www.php.net/manual/en/pdo.prepared-statements.php) — query aman untuk `INSERT`/`SELECT`
* [PHP Manual — PDO::lastInsertId()](https://www.php.net/manual/en/pdo.lastinsertid.php) — dipakai `save_link()`
* [PHP Manual — Exceptions](https://www.php.net/manual/en/language.exceptions.php) — dasar `try/catch` untuk pelanggaran UNIQUE
* [PHP 8.5 Release Announcement](https://www.php.net/releases/8.5/en.php) — versi PHP yang dipakai proyek ini (8.5.x stabil per Oktober 2026)
