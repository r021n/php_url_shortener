# Panduan Fase 5 — Dashboard Riwayat & Statistik

> **Target waktu:** ±30 menit
> **Setelah fase ini:** halaman utama menampilkan tabel semua link (URL asli, short link yang bisa diklik, waktu pembuatan, jumlah klik), terbaru di atas. Semua output di-escape agar aman dari XSS. Link baru dari form langsung muncul di tabel.
> **Catatan:** panduan ini disusun dari implementasi nyata yang sudah di-lint dan diuji end-to-end di PHP 8.5.12 (Windows 11). Semua perintah CLI di bawah ditulis untuk **Command Prompt (cmd)**. Linux/macOS: ganti `\` menjadi `/`.

---

## 0. Peta Fase 5

| Langkah | Yang dikerjakan | File |
|---|---|---|
| 1 | Fungsi query semua link, terbaru di atas | `lib/link.php` *(update)* |
| 2 | Siapkan `$baseUrl` sekali saja (hindari duplikasi) | `index.php` *(update)* |
| 3 | Ambil data link sebelum halaman dirender | `index.php` *(update)* |
| 4 | Render tabel dashboard + escape output | `index.php` *(update)* |
| 5 | Lint semua file | (terminal) |
| 6 | Jalankan server & uji manual di browser | (terminal + browser) |
| 7 | Verifikasi isi database via CLI | (terminal) |

**Struktur folder setelah Fase 5 selesai:**

```
php_url_shortener/
├── config/
│   └── database.php      ← koneksi PDO (tidak berubah)
├── lib/
│   ├── validation.php    ← validasi input (tidak berubah)
│   ├── link.php          ← + get_all_links() (Langkah 1)
│   └── redirect.php      ← redirect & 404 (tidak berubah)
├── guides/
│   └── phase5.md         ← file ini
├── tests/                ← uji otomatis fase 1–5 (tidak dibahas di panduan)
├── index.php             ← + dashboard riwayat (Langkah 2–4)
├── setup.php
├── hapus.php
└── database.sqlite
```

**Prinsip yang dipakai di fase ini:**

* **SRP (Single Responsibility):** query database tetap di `lib/link.php`; `index.php` hanya mengatur request dan merender HTML.
* **DRY (Don't Repeat Yourself):** `$baseUrl` didefinisikan **sekali** dan dipakai untuk pesan sukses maupun tabel dashboard.
* **KISS (Keep It Simple):** tidak perlu class, framework, atau template engine. Satu fungsi query + satu loop tabel sudah cukup.

---

## 1. Tambah `get_all_links()` di `lib/link.php`

**Lokasi:** `lib/link.php`

**Di mana menulis:** tambahkan fungsi baru **setelah** fungsi `increment_clicks()` dan **sebelum** `alias_exists()`. Jangan mengubah fungsi yang sudah ada.

**Tulis kode ini:**

```php
/**
 * Mengambil seluruh link untuk dashboard, yang terbaru lebih dulu.
 *
 * `id DESC` dipakai sebagai tie-breaker karena kolom created_at dari SQLite
 * hanya presisi detik — dua link yang dibuat pada detik yang sama tetap
 * memiliki urutan yang konsisten.
 *
 * @return array<int, array<string, mixed>>
 */
function get_all_links(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT id, original_url, short_code, clicks, created_at
         FROM links
         ORDER BY created_at DESC, id DESC'
    );

    return $stmt->fetchAll();
}
```

**Kenapa begini:**

* `query()` dipakai karena tidak ada input user di SQL ini — aman dan sederhana.
* `ORDER BY created_at DESC` sesuai brief. `id DESC` hanya penentu urutan jika detiknya sama.
* `fetchAll()` mengembalikan semua baris sebagai array asosiatif (mode fetch sudah diatur di `config/database.php`).
* Kolom yang diambil eksplisit, bukan `SELECT *`, supaya jelas dan tidak ikut membawa kolom yang tidak perlu.

**Dokumentasi resmi:**

* <https://www.php.net/manual/en/pdostatement.fetchall.php>
* <https://www.php.net/manual/en/pdo.query.php>

---

## 2. Siapkan `$baseUrl` sekali di `index.php`

**Lokasi:** `index.php`

**Di mana menulis:** di bagian atas, tepat setelah baris-baris inisialisasi variabel (`$errors`, `$pesanSukses`, `$shortUrl`, `$urlInput`, `$aliasInput`).

**Tambahkan satu baris:**

```php
$baseUrl = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost:8000');
```

**Lalu hapus baris lama di dalam blok sukses POST.** Cari baris seperti ini:

```php
$baseUrl = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost:8000');
```

yang berada **di dalam** `if ($errors === [] && isset($shortCode)) { ... }`, dan hapus. Blok sukses POST cukup menjadi:

```php
if ($errors === [] && isset($shortCode)) {
    $shortUrl = $baseUrl . '/?c=' . $shortCode;
    $pesanSukses = 'URL berhasil disimpan ke database';
    $urlInput = '';
    $aliasInput = '';
}
```

**Kenapa begini:** `$baseUrl` sekarang bisa dipakai dua tempat — pesan sukses dan tabel dashboard — tanpa menulis ulang logika host. Ini penerapan DRY yang paling sederhana.

> Catatan: `http://` cukup untuk lokal. Jika nanti dipasang di HTTPS, sesuaikan menjadi `https://` atau deteksi skema dari `$_SERVER['HTTPS']`.

### 2b. Sekaligus escape teks short link di pesan sukses

Masih di `index.php`, pada kotak pesan sukses, cari baris:

```php
<a href="<?php echo e($shortUrl); ?>" class="font-mono text-sky-600 underline"><?php echo ($shortUrl); ?></a>
```

Ubah bagian teksnya agar ikut di-escape:

```php
<a href="<?php echo e($shortUrl); ?>" class="font-mono text-sky-600 underline"><?php echo e($shortUrl); ?></a>
```

Ini menutup celah XSS lewat header `Host`: `$shortUrl` dibentuk dari `$_SERVER['HTTP_HOST']`, yang secara teori bisa dimanipulasi. Atribut `href` sudah di-escape; sekarang teks yang terlihat juga di-escape. Prinsipnya sama: **semua output dinamis wajib lewat `e()`**.

---

## 3. Ambil semua link sebelum halaman dirender

**Lokasi:** `index.php`

**Di mana menulis:** tepat **setelah** blok `if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') { ... }` selesai, dan **sebelum** tag penutup PHP `?>` yang menuju HTML.

**Tambahkan satu baris:**

```php
$links = get_all_links($pdo);
```

**Kenapa di sini:**

* Query dijalankan **setelah** POST diproses, sehingga link yang baru disimpan langsung ikut tampil pada halaman yang sama.
* Query dijalankan **sebelum** HTML dirender, sehingga loop tabel di bawah punya data.
* Route redirect (`/?c=CODE`) sudah `exit` lebih awal, jadi query dashboard tidak ikut berjalan saat redirect.

---

## 4. Render tabel dashboard di `index.php`

**Lokasi:** `index.php`

**Di mana menulis:** di dalam `<main>`, tepat **setelah** `</form>` dan **sebelum** `</main>`.

**Tempel HTML + PHP ini:**

```php
<section class="mt-8">
    <h2 class="text-xl font-bold text-slate-800">Riwayat Link</h2>
    <p class="mt-1 text-sm text-slate-500">Semua link yang pernah dibuat, yang terbaru di atas.</p>

    <div class="mt-4 overflow-x-auto rounded-lg bg-white shadow-md">
        <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-3 font-semibold text-slate-700">URL Asli</th>
                    <th class="px-4 py-3 font-semibold text-slate-700">Short Link</th>
                    <th class="px-4 py-3 font-semibold text-slate-700">Waktu Pembuatan</th>
                    <th class="px-4 py-3 font-semibold text-slate-700">Klik</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                <?php if ($links === []): ?>
                    <tr>
                        <td colspan="4" class="px-4 py-6 text-center text-slate-500">Belum ada link.</td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($links as $link): ?>
                    <?php $shortUrl = $baseUrl . '/?c=' . $link['short_code']; ?>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 text-slate-700 break-all"><?php echo e((string) $link['original_url']); ?></td>
                        <td class="px-4 py-3">
                            <a href="<?php echo e($shortUrl); ?>" class="font-mono text-sky-600 underline break-all"><?php echo e($shortUrl); ?></a>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap text-slate-600"><?php echo e((string) $link['created_at']); ?></td>
                        <td class="px-4 py-3 text-slate-700"><?php echo (int) $link['clicks']; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
```

**Penjelasan baris penting:**

| Baris | Fungsi |
|---|---|
| `if ($links === [])` | Empty state sederhana saat belum ada link. |
| `$shortUrl = $baseUrl . '/?c=' . $link['short_code'];` | Membentuk short link yang bisa diklik. |
| `e((string) $link['original_url'])` | **Wajib**: escape URL asli sebelum dicetak. |
| `e($shortUrl)` | Escape di atribut `href` **dan** teks tautan. |
| `(int) $link['clicks']` | Klik adalah angka; cast ke integer supaya output bersih. |

**Helper `e()` yang sudah ada di `index.php`:**

```php
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
```

**Kenapa `htmlspecialchars()` wajib:** data di database bisa saja berisi karakter `<`, `>`, `"`, `'`, atau `&` — misalnya URL yang sengaja dibuat aneh. Tanpa escape, karakter itu bisa menjadi HTML/JavaScript dan memicu XSS. `ENT_QUOTES` meng-escape kutip tunggal dan ganda; `UTF-8` memastikan encoding benar.

**Dokumentasi resmi:**

* <https://www.php.net/manual/en/function.htmlspecialchars.php>
* <https://www.php.net/manual/en/function.htmlspecialchars-decode.php>

> Catatan tampilan: `created_at` dari SQLite berisi UTC (`YYYY-MM-DD HH:MM:SS`). Pada fase ini ditampilkan apa adanya. Konversi ke WIB adalah polish opsional — tidak wajib untuk memenuhi brief.

---

## 5. Lint semua file

Jalankan dari root proyek:

```cmd
php -l lib/link.php
php -l index.php
```

**Yang akan muncul jika tidak ada kesalahan sintaks:**

```
No syntax errors detected in lib/link.php
No syntax errors detected in index.php
```

Jika ada error, baris output akan menyebut file dan nomor barisnya. Perbaiki dulu sebelum lanjut.

---

## 6. Jalankan server & uji manual di browser

Dari root proyek:

```cmd
php -S localhost:8000
```

**Yang akan muncul (kurang lebih):**

```
PHP 8.5.12 Development Server (http://localhost:8000) started
```

Biarkan terminal ini terbuka. Buka browser ke <http://localhost:8000>.

**Checklist yang harus terlihat:**

1. Form input URL + custom alias masih tampil seperti fase sebelumnya.
2. Di bawah form muncul judul **Riwayat Link** dan tabel dengan kolom:
   * **URL Asli**
   * **Short Link**
   * **Waktu Pembuatan**
   * **Klik**
3. Semua link yang sudah ada di database tampil, **yang terbaru di atas**.
4. Short link tampil sebagai tautan biru bergaris bawah dan bisa diklik.
5. Klik salah satu short link → browser langsung redirect ke URL asli.
6. Kembali ke <http://localhost:8000> → angka di kolom **Klik** untuk link tersebut bertambah 1.
7. Submit URL baru lewat form → setelah halaman dimuat ulang, URL baru dan short link-nya **langsung muncul** di tabel paling atas.
8. Jika database masih kosong, tabel menampilkan pesan **Belum ada link.**

**Uji keamanan XSS secara manual (opsional):**

1. Hentikan server sementara (`Ctrl+C`).
2. Masukkan satu baris berbahaya langsung ke database lewat CLI:

   ```cmd
   php -r "require 'config/database.php'; db()->prepare('INSERT INTO links (original_url, short_code) VALUES (:u, :c)')->execute([':u' => 'https://example.com/\"><script>alert(1)</script>', ':c' => 'xsscek5']);"
   ```

3. Jalankan lagi `php -S localhost:8000`, lalu buka <http://localhost:8000>.
4. **Yang harus terjadi:** teks `<script>alert(1)</script>` tampil sebagai **teks biasa** di kolom URL Asli; tidak ada popup alert. Jika popup muncul, berarti escape belum benar.
5. Hapus baris uji setelah selesai:

   ```cmd
   php -r "require 'config/database.php'; db()->prepare('DELETE FROM links WHERE short_code = :c')->execute([':c' => 'xsscek5']);"
   ```

---

## 7. Verifikasi isi database via CLI

Masih dari root proyek. Perintah ini membaca data yang sama dengan yang dipakai dashboard:

```cmd
php -r "require 'config/database.php'; require 'lib/link.php'; foreach (get_all_links(db()) as $row) { echo $row['short_code'] . ' | klik=' . $row['clicks'] . ' | ' . $row['created_at'] . PHP_EOL; }"
```

**Contoh output (isi akan berbeda sesuai data Anda):**

```
abc123 | klik=4 | 2026-10-25 08:15:00
promo2026 | klik=12 | 2026-10-25 08:20:00
```

Baris paling atas adalah link yang paling baru. Jika output kosong, berarti tabel `links` masih kosong — itu normal untuk database baru.

**Hitung total baris:**

```cmd
php -r "require 'config/database.php'; echo 'Total link: ' . db()->query('SELECT COUNT(*) FROM links')->fetchColumn() . PHP_EOL;"
```

**Contoh output:**

```
Total link: 2
```

---

## 8. Hasil wajib Fase 5 (checklist akhir)

Centang semuanya sebelum lanjut ke Fase 6:

* [ ] `get_all_links()` ada di `lib/link.php` dan mengembalikan semua baris.
* [ ] Query memakai `ORDER BY created_at DESC` (dengan `id DESC` sebagai tie-breaker).
* [ ] Tabel dashboard tampil di halaman utama dengan 4 kolom: URL Asli, Short Link, Waktu Pembuatan, Klik.
* [ ] Short link di dashboard bisa diklik, redirect ke URL asli, dan menambah klik.
* [ ] Link baru dari form langsung muncul di tabel tanpa refresh manual tambahan.
* [ ] Semua output dinamis di-escape dengan `htmlspecialchars()` melalui `e()`.
* [ ] `php -l lib/link.php` dan `php -l index.php` bersih.
* [ ] Halaman utama tetap bisa diakses tanpa error.

---

## 9. Troubleshooting cepat

| Gejala | Penyebab umum | Perbaikan |
|---|---|---|
| `Call to undefined function get_all_links()` | Fungsi belum ditambahkan atau file belum di-`require` | Pastikan fungsi ada di `lib/link.php` dan `index.php` sudah `require __DIR__ . '/lib/link.php';` |
| Tabel kosong padahal database berisi | `$links` diambil sebelum POST / salah urutan | Pastikan `$links = get_all_links($pdo);` berada setelah blok POST dan sebelum `?>` |
| Short link mengarah ke port/host yang salah | `$baseUrl` memakai host lama | Definisikan `$baseUrl` sekali di atas dari `$_SERVER['HTTP_HOST']`, hapus definisi lama di blok sukses |
| Muncul karakter aneh / layout rusak | Output tidak di-escape | Bungkus semua output dengan `e(...)`; jangan pernah `echo $row['...']` langsung |
| Klik tidak bertambah | Link dibuka lewat dashboard tapi route redirect bermasalah | Cek Fase 4: `/?c=CODE` harus `UPDATE clicks + 1` lalu `header('Location: ...')` dan `exit` |
| Urutan link sama detiknya tidak konsisten | `created_at` hanya presisi detik | Tambahkan `id DESC` pada `ORDER BY` |

---

## 10. Ringkasan perubahan Fase 5

| File | Perubahan |
|---|---|
| `lib/link.php` | Tambah `get_all_links(PDO $pdo): array` |
| `index.php` | `$baseUrl` dipindah ke atas (dipakai bersama); ambil `$links`; render tabel dashboard; escape semua output |

Setelah Fase 5 selesai, aplikasi sudah memenuhi alur **Generate → Redirect + Hitung Klik → Statistik**. Fase 6 tinggal menambahkan tombol hapus, konfirmasi, dan polish empty state.
