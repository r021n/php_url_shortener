# Panduan Fase 4 — Redirect & Click Counter

> **Target waktu:** ±30 menit
> **Setelah fase ini:** membuka `http://localhost:8000/?c=CODE` langsung **redirect (302)** ke URL asli, kolom `clicks` bertambah 1 setiap akses, dan kode yang tidak ada menampilkan halaman **404 "Link tidak ditemukan"**.
> **Catatan:** panduan ini disusun dari pengerjaan nyata yang sudah di-lint dan diuji end-to-end dengan request HTTP sungguhan (PHP Built-in Web Server + `curl`) di PHP 8.5.12 (Windows 11). Semua output CLI di bawah adalah **output asli hasil eksekusi**.
> Perintah ditulis untuk **Command Prompt (cmd)**. Linux/macOS: ganti `\` menjadi `/`.

---

## 0. Peta Fase 4

| Langkah | Yang dikerjakan | File |
|---|---|---|
| 1 | Dua fungsi: cari link berdasarkan kode + hitung klik | `lib/link.php` *(update)* |
| 2 | Fungsi response: redirect 302 + halaman 404 | `lib/redirect.php` *(baru)* |
| 3 | Route `/?c=CODE` di halaman utama | `index.php` *(update)* |
| 4 | Lint semua file | (terminal) |
| 5 | Uji CLI: `curl` + cek database | (terminal) |
| 6 | Uji tampilan di browser | (browser) |

**Struktur folder setelah Fase 4 selesai:**

```
php_url_shortener/
├── config/
│   └── database.php      ← koneksi PDO (tidak berubah)
├── lib/
│   ├── validation.php    ← validasi input (tidak berubah)
│   ├── link.php          ← akses tabel links + generator (Langkah 1)
│   └── redirect.php      ← response redirect 302 & halaman 404 (Langkah 2, BARU)
├── guides/
│   ├── phase1.md … phase3.md
│   └── phase4.md         ← file ini
├── tests/                ← uji otomatis fase 1–3 (tidak berubah)
├── index.php             ← + route /?c=CODE (Langkah 3)
├── setup.php
├── hapus.php
└── database.sqlite
```

**Aturan main Fase 4 (hafalkan dulu):**

| Request | Yang terjadi |
|---|---|
| `GET /?c=CODE` — kode ada, target valid | `clicks + 1`, kirim `Location: URL_ASLI` + status **302**, eksekusi berhenti (`exit`) |
| `GET /?c=CODE` — kode tidak ada | halaman "Link tidak ditemukan", status **404**, database **tidak** disentuh |
| `GET /?c=CODE` — target di database tidak valid/berbahaya (mis. `javascript:`) | sama seperti tidak ada: **404**, **tidak** redirect |
| `GET /?c=` (parameternya kosong) | halaman utama seperti biasa (status 200) |
| `GET /` (tanpa parameter `c`) | halaman utama seperti biasa (status 200) |
| `POST` form | tidak berubah dari Fase 2–3 |

> **Penting:** sebelum Fase 4, membuka `?c=CODE` masih menampilkan form. Setelah Fase 4, setiap request yang membawa parameter `c` (berisi) **selalu** diselesaikan sebagai redirect atau 404 — tidak pernah jatuh lagi ke halaman form.

---

## 1. Tambah Dua Fungsi di `lib/link.php`

**Lokasi:** `lib/link.php` (file yang sudah ada sejak Fase 2).

**Yang dilakukan:**

1. Sisipkan **dua fungsi baru** `find_link_by_code()` dan `increment_clicks()` di **bagian paling atas** file (tepat setelah `declare(strict_types=1);`).
2. Ganti isi fungsi `alias_exists()` (yang lama memakai query `SELECT 1 ...`) agar memakai `find_link_by_code()`. Fungsi `generate_short_code()` dan `save_link()` **tidak diubah**.

**Tulis kode ini** untuk dua fungsi baru:

```php
/**
 * Mengambil satu baris link berdasarkan short code.
 *
 * @return array<string, mixed>|null Baris link, atau null jika kode tidak ada.
 */
function find_link_by_code(PDO $pdo, string $code): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, original_url, short_code, clicks FROM links WHERE short_code = :code LIMIT 1'
    );
    $stmt->execute([':code' => $code]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/**
 * Menambah jumlah klik sebanyak 1 untuk short code tertentu.
 */
function increment_clicks(PDO $pdo, string $code): void
{
    $stmt = $pdo->prepare('UPDATE links SET clicks = clicks + 1 WHERE short_code = :code');
    $stmt->execute([':code' => $code]);
}
```

**Lalu ganti isi `alias_exists()`** yang lama:

```php
// LAMA (Fase 2–3)
function alias_exists(PDO $pdo, string $alias): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM links WHERE short_code = :code LIMIT 1');
    $stmt->execute([':code' => $alias]);

    return $stmt->fetch() !== false;
}
```

menjadi:

```php
// BARU (Fase 4)
function alias_exists(PDO $pdo, string $alias): bool
{
    return find_link_by_code($pdo, $alias) !== null;
}
```

**Kenapa begini (poin penting):**

* **SRP:** `lib/link.php` adalah satu-satunya tempat yang "tahu" struktur tabel `links`. `index.php` cukup memanggil fungsi — kalau kelak kolomnya berubah, yang diubah hanya file ini.
* **`?array` sebagai return type:** `null` berarti "kode tidak ada". Ini lebih jelas daripada mengembalikan `false` yang membingungkan saat dicek. `LIMIT 1` memastikan maksimal satu baris (kolom `short_code` sudah `UNIQUE`).
* **DRY:** aturan "cari baris berdasarkan `short_code`" kini ditulis **sekali** di `find_link_by_code()`. `alias_exists()` cukup memakainya — tidak ada lagi dua query yang nyaris sama.
* **`clicks = clicks + 1` dilakukan di SQL**, bukan `SELECT clicks` lalu `UPDATE` dengan nilai baru. Hasilnya: hanya **1 query**, lebih cepat, dan aman walau dua akses datang bersamaan (penambahan dilakukan oleh database secara atomik — tidak ada yang saling menimpa). Ini KISS sekaligus bebas *race condition*.
* **Prepared statement `:code`** tetap dipakai sehingga input dari URL tidak mungkin menjadi SQL injection.

---

## 2. Buat `lib/redirect.php` — Response Redirect & 404

**Lokasi:** `lib/redirect.php` (**file baru**, di dalam folder `lib/`).

**Yang dilakukan:** buat file baru berisi dua fungsi: `redirect_to()` (kirim redirect 302) dan `show_not_found()` (tampilkan halaman 404). Keduanya berhenti total dengan `exit`.

**Tulis kode ini** (isi seluruh file):

```php
<?php

declare(strict_types=1);

/**
 * Mengirim response redirect (302) ke URL tujuan lalu menghentikan eksekusi.
 *
 * Wajib dipanggil SEBELUM ada output apa pun, kalau tidak PHP akan
 * gagal mengirim header ("headers already sent").
 */
function redirect_to(string $url): never
{
    header('Location: ' . $url, true, 302);
    exit;
}

/**
 * Menampilkan halaman "Link tidak ditemukan" (404) lalu menghentikan eksekusi.
 */
function show_not_found(): never
{
    http_response_code(404);
    ?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Link tidak ditemukan</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-slate-100 min-h-screen">
    <main class="max-w-3xl mx-auto px-4 py-10">
        <div class="rounded-lg border border-red-300 bg-red-50 p-6">
            <h1 class="text-2xl font-bold text-red-800">Link tidak ditemukan</h1>
            <p class="mt-2 text-red-700">
                Kode pendek yang Anda buka tidak tersedia di database.
            </p>
            <a href="index.php" class="mt-6 inline-block rounded-md bg-sky-600 px-4 py-2 font-semibold text-white hover:bg-sky-700">
                Kembali ke halaman utama
            </a>
        </div>
    </main>
</body>

</html>
    <?php
    exit;
}
```

**Kenapa begini (poin penting):**

* **`header('Location: ' . $url, true, 302)`** — sesuai dokumentasi resmi PHP, header `Location:` *otomatis* mengirim status **302** kecuali status 201/3xx sudah diset sebelumnya. Parameter kedua `true` berarti "ganti header `Location` sebelumnya (jangan dobel)", parameter ketiga menulis status secara **eksplisit** sehingga tidak bergantung pada perilaku default.
* **Kenapa 302, bukan 301?** 301 (*Moved Permanently*) akan disimpan di cache browser — akses berikutnya bisa langsung lompat ke tujuan **tanpa melewati server kita**, sehingga click counter berhenti menghitung. 302 (*Found* = sementara) memastikan **setiap** akses selalu mampir ke server dan terhitung. Untuk URL shortener, 302 adalah pilihan yang benar.
* **`exit;` setelah `header()` itu WAJIB.** Dokumentasi `header()` sendiri mencontohkannya: tanpa `exit`, sisa skrip tetap berjalan dan bisa mengirim output → response rusak atau muncul error *"headers already sent"*. Karena itu kedua fungsi diberi return type **`never`** (PHP 8.1+): mendokumentasikan sekaligus menjamin bahwa fungsi ini tidak pernah kembali ke pemanggil.
* **`http_response_code(404)`** adalah cara standar menyetel status HTTP (ada sejak PHP 5.4). Lebih aman daripada `header('HTTP/1.1 404 Not Found')` karena tidak perlu tahu versi protokol yang dipakai server.
* **Halaman 404 punya HTML sendiri** dengan tombol kembali — memenuhi syarat brief: *"menampilkan pesan error, bukan halaman kosong"*. Pesannya statis (tanpa output dari user/database) sehingga bebas risiko XSS.
* **Satu file = satu tanggung jawab (SRP):** `lib/redirect.php` khusus "membentuk response untuk route `/?c=CODE`" (redirect **atau** 404). `lib/link.php` tetap murni urusan database, `index.php` tetap urusan form.

> **Catatan PHP 8.5:** `header()` harus dipanggil **sebelum ada output apa pun** — termasuk spasi/baris kosong sebelum `<?php` dan karakter BOM. Simpan file sebagai **UTF-8 tanpa BOM** (default VS Code sudah benar).

---

## 3. Update `index.php` — Route `/?c=CODE`

**Lokasi:** `index.php` (root proyek).

**Yang dilakukan:** (3a) tambahkan satu baris `require`, (3b) sisipkan blok route tepat setelah `$pdo = db();` dan **sebelum** variabel `$errors` diinisialisasi. Bagian form, validasi, dan HTML **tidak diubah**.

### 3a. Tambah `require` untuk file baru

Cari baris:

```php
require __DIR__ . '/config/database.php';
require __DIR__ . '/lib/validation.php';
require __DIR__ . '/lib/link.php';
```

Ganti menjadi:

```php
require __DIR__ . '/config/database.php';
require __DIR__ . '/lib/validation.php';
require __DIR__ . '/lib/link.php';
require __DIR__ . '/lib/redirect.php';
```

### 3b. Sisipkan blok route setelah `$pdo = db();`

Cari baris:

```php
$pdo = db();
```

Ganti menjadi:

```php
$pdo = db();

// Route /?c=CODE: redirect ke URL asli sambil menghitung klik (Fase 4).
$kode = is_string($_GET['c'] ?? null) ? trim($_GET['c']) : '';

if ($kode !== '') {
    $link   = find_link_by_code($pdo, $kode);
    $tujuan = $link === null ? '' : (string) $link['original_url'];

    if (validate_url($tujuan) !== null) {
        show_not_found(); // kode tidak ada / target tidak aman -> jangan redirect
    }

    increment_clicks($pdo, $kode);
    redirect_to($tujuan);
}
```

**Kenapa begini (poin penting):**

* **Alurnya 5 langkah berurutan (KISS):** ambil kode → cari di database → pastikan target aman → hitung klik → redirect. Mudah dibaca dari atas ke bawah.
* **`$_GET['c']`** adalah superglobal untuk query string `?c=...`. `is_string(...)` melindungi dari kiriman aneh seperti `?c[]=x` (array) yang akan membuat `trim()` error, dan `trim()` membersihkan spasi tak sengaja.
* **`$kode !== ''` bukan `empty()`.** Kode `"0"` itu sah dan tetap diproses (lalu 404 jika memang tidak ada) — `empty()` akan menganggap `"0"` sebagai kosong.
* **DRY: memakai ulang `validate_url()`** dari `lib/validation.php` (Fase 2). Sekali fungsi, dua jaminan: (1) kode yang tidak ada → `$tujuan` kosong → `validate_url()` mengembalikan error → 404; (2) isi database yang rusak/berbahaya (`javascript:`, `ftp:`, teks berisi karakter baris baru untuk *header injection*) ikut tertolak. Dua kasus pun menyatu dalam **satu** kondisi `if` yang ringkas.
* **`increment_clicks()` dipanggil sebelum `redirect_to()`** — klik dihitung pada saat request diterima, bukan pada saat browser sampai di tujuan (kita tidak bisa tahu apakah browser benar-benar meneruskan).
* **Blok ini harus ada di paling atas**, sebelum output apa pun — syarat mutlak `header()`. Pastikan tidak ada spasi/baris kosong sebelum `<?php` di awal file.
* **`exit` di dalam `redirect_to()`/`show_not_found()`** menjamin kode form di bawahnya tidak pernah ikut terkirim untuk request `?c=` (SRP: satu request, satu response).

---

## 4. Lint (Cek Sintaks)

**Perintah** (dari root proyek):

```bash
php -l lib\link.php & php -l lib\redirect.php & php -l index.php
```

**Yang muncul** (semua harus `No syntax errors`):

```
No syntax errors detected in lib\link.php
No syntax errors detected in lib\redirect.php
No syntax errors detected in index.php
```

> Ada error? Perhatikan nomor barisnya — biasanya kurung kurawal, tanda kutip, atau titik koma kurang saat menyalin kode.

---

## 5. Uji via CLI (Server + `curl`)

Bagian ini membuktikan redirect, hitungan klik, dan halaman 404 **tanpa browser** — semua terlihat apa adanya di terminal. `curl` sudah tersedia bawaan di Windows 10/11.

### 5a. Nyalakan server (Terminal 1)

```bash
php -S localhost:8000
```

**Yang muncul:**

```
[Sun Oct 25 11:19:31 2026] PHP 8.5.12 Development Server (http://localhost:8000) started
```

Biarkan terminal ini terbuka. Semua perintah 5b–5g dijalankan di **Terminal 2**.

### 5b. Siapkan satu link uji

Buka **http://localhost:8000** di browser, lalu simpan URL seperti biasa (Fase 2–3):

* URL panjang: `https://example.com/promo`
* Custom alias: `demo4`

Akan muncul short link `http://localhost:8000/?c=demo4`. (Panduan ini memakai kode `demo4` — jika Anda memakai link lain, sesuaikan kodenya di perintah berikut.)

### 5c. Buka short link dari CLI → lihat redirect-nya

**Perintah:**

```bash
curl -s -i http://localhost:8000/?c=demo4
```

**Yang muncul** (urutan header bisa sedikit berbeda; yang penting baris pertama dan `Location`):

```
HTTP/1.1 302 Found
Host: localhost:8000
Date: Sun, 25 Oct 2026 04:21:16 GMT
Connection: close
X-Powered-By: PHP/8.5.12
Location: https://example.com/promo
Content-type: text/html; charset=UTF-8
```

**Baca hasilnya:**

* `HTTP/1.1 302 Found` → status redirect (sementara) yang kita set di `redirect_to()`.
* `Location: https://example.com/promo` → tujuan yang diberikan ke browser. Inilah yang membuat browser "lompat" ke URL asli.
* Tidak ada body HTML sama sekali → `exit` bekerja, halaman form tidak ikut terkirim.

> Di browser, redirect ini berjalan otomatis (Anda tidak melihat halaman perantara). `curl` sengaja **tidak** mengikuti redirect agar kita bisa melihat headernya. Tambahkan `-L` jika ingin `curl` ikut melompat: `curl -s -L http://localhost:8000/?c=demo4`.

### 5d. Cek kolom `clicks` di database

**Perintah** (dari root proyek, satu baris penuh):

```bash
php -r "$p=new PDO('sqlite:database.sqlite');foreach($p->query('SELECT id, original_url, short_code, clicks FROM links ORDER BY id DESC LIMIT 5') as $r){echo $r['id'],' | ',$r['original_url'],' | ',$r['short_code'],' | ',$r['clicks'],PHP_EOL;}"
```

**Yang muncul** (output asli; id & isi tabel Anda bisa berbeda — perhatikan angka terakhir baris `demo4`):

```
34 | https://example.com/promo | demo4 | 1
17 | https://example.com/promo | promo2026 | 0
16 | https://example.com/artikel | 0daab5 | 0
5 | https://example.com/promo2 | promo2 | 0
4 | https://example.com/promo | promo | 0
```

`demo4 | 1` → satu akses = satu klik. Tercatat!

### 5e. Ulangi akses → klik bertambah

**Perintah** (ulangi perintah 5c):

```bash
curl -s -i http://localhost:8000/?c=demo4
```

**Yang muncul:** response `302` yang sama persis. Lalu cek database lagi dengan perintah 5d:

```
34 | https://example.com/promo | demo4 | 2
17 | https://example.com/promo | promo2026 | 0
16 | https://example.com/artikel | 0daab5 | 0
5 | https://example.com/promo2 | promo2 | 0
4 | https://example.com/promo | promo | 0
```

`demo4 | 2` → setiap akses selalu menambah tepat 1.

### 5f. Kode yang tidak ada → 404

**Perintah:**

```bash
curl -s -i http://localhost:8000/?c=kode-salah
```

**Yang muncul:**

```
HTTP/1.1 404 Not Found
Host: localhost:8000
Date: Sun, 25 Oct 2026 04:21:16 GMT
Connection: close
X-Powered-By: PHP/8.5.12
Content-type: text/html; charset=UTF-8

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Link tidak ditemukan</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-slate-100 min-h-screen">
    <main class="max-w-3xl mx-auto px-4 py-10">
        <div class="rounded-lg border border-red-300 bg-red-50 p-6">
            <h1 class="text-2xl font-bold text-red-800">Link tidak ditemukan</h1>
            <p class="mt-2 text-red-700">
                Kode pendek yang Anda buka tidak tersedia di database.
            </p>
            <a href="index.php" class="mt-6 inline-block rounded-md bg-sky-600 px-4 py-2 font-semibold text-white hover:bg-sky-700">
                Kembali ke halaman utama
            </a>
        </div>
    </main>
</body>

</html>
```

**Baca hasilnya:** status `404 Not Found`, **tidak ada** header `Location`, dan body berisi pesan "Link tidak ditemukan" — persis seperti yang diminta brief.

### 5g. Kode salah tidak mengubah database

Ulangi perintah pengecekan database (5d):

```
34 | https://example.com/promo | demo4 | 2
17 | https://example.com/promo | promo2026 | 0
16 | https://example.com/artikel | 0daab5 | 0
5 | https://example.com/promo2 | promo2 | 0
4 | https://example.com/promo | promo | 0
```

Angkanya **tidak berubah** — akses ke kode yang tidak ada tidak mencatat klik apa pun, dan tidak membuat baris baru.

### 5h. (Opsional) Lihat catatan request di Terminal 1

Terminal server mencatat status setiap request:

```
[Sun Oct 25 11:21:16 2026] [::1]:63131 [302]: GET /?c=demo4
[Sun Oct 25 11:21:16 2026] [::1]:63133 [404]: GET /?c=kode-salah
```

Angka `302` dan `404` di sini adalah bukti status yang benar-benar dikirim server.

Tekan **Ctrl + C** di Terminal 1 untuk mematikan server setelah selesai.

---

## 6. Uji di Browser

**Perintah** (jika server belum menyala):

```bash
php -S localhost:8000
```

Buka **http://localhost:8000** lalu uji:

| # | Aksi | Yang harus muncul |
|---|---|---|
| 1 | Buka `http://localhost:8000/?c=demo4` | Browser **langsung pindah** ke `https://example.com/promo` (tidak ada halaman perantara). |
| 2 | Buka `http://localhost:8000/?c=kode-salah` | Halaman merah **"Link tidak ditemukan"** dengan tombol *Kembali ke halaman utama*. |
| 3 | Klik tombol *Kembali ke halaman utama* | Form seperti biasa, tanpa error. |
| 4 | Buka `http://localhost:8000/?c=` | Halaman utama seperti biasa (parameter kosong bukan request redirect). |
| 5 | Simpan URL baru lewat form | Berhasil seperti Fase 3, dan link barunya langsung bisa dipakai redirect. |

> Ingin melihat status 302/404 di browser? Buka **DevTools → tab Network**, muat ulang halamannya, lalu lihat kolom *Status* pada request pertama. Di tab Network tersebut Anda juga akan melihat baris kedua (request ke URL tujuan) yang muncul karena browser mengikuti redirect.

---

## 7. Troubleshooting Cepat

| Gejala | Penyebab | Solusi |
|---|---|---|
| `Warning: Cannot modify header information - headers already sent` | Ada output sebelum `header()` — spasi/baris kosong sebelum `<?php`, file UTF-8 **dengan BOM**, atau `echo` terlanjur jalan | Pastikan blok route ada di **paling atas** `index.php`, tidak ada karakter sebelum `<?php`, dan simpan file sebagai *UTF-8 without BOM* |
| Membuka `?c=CODE` malah menampilkan form | Blok route belum ditulis / parameter bukan `c` | Cek Langkah 3b — `$kode` harus diambil dari `$_GET['c']` |
| Response kosong, browser diam saja | `header()` gagal atau `exit` belum ada | Pastikan `redirect_to()` berisi `header(...)` **dan** `exit;` |
| Status tetap 200 untuk kode yang salah | `http_response_code(404)` belum dipanggil | Pastikan `show_not_found()` memanggil `http_response_code(404)` **sebelum** menampilkan HTML |
| `clicks` selalu 0 | `increment_clicks()` belum dipanggil / query `UPDATE` salah | Cek Langkah 3b dan 1 — pastikan memakai `clicks = clicks + 1` |
| Kode valid tetapi tetap "Link tidak ditemukan" | Database yang dibaca berbeda / `short_code` salah ketik | Jalankan perintah 5d untuk melihat isi `short_code` yang sebenarnya |
| `curl` tidak dikenal | `curl` belum ada di PATH | Pakai Git Bash, atau ganti dengan browser + DevTools (tab Network) untuk melihat status/`Location` |
| Tampilan 404 tidak rapi | Tailwind CDN belum termuat | Butuh koneksi internet; pastikan `<script src="https://cdn.tailwindcss.com">` ada di `<head>` |
| `database is locked` | Koneksi lain memegang database (DB Browser, skrip lain) | Tutup aplikasi/koneksi lain lalu ulangi |

---

## 8. Checklist Akhir Fase 4

- [ ] `lib/link.php` punya `find_link_by_code()` dan `increment_clicks()` dan lolos `php -l`
- [ ] `alias_exists()` memakai `find_link_by_code()` (aturan pencarian tidak diduplikasi)
- [ ] `lib/redirect.php` berisi `redirect_to()` + `show_not_found()` dan lolos `php -l`
- [ ] `index.php` me-`require` `lib/redirect.php` dan punya blok route `/?c=CODE` di paling atas
- [ ] Buka `?c=KODE_VALID` → browser **redirect** ke URL asli (status 302)
- [ ] Kolom `clicks` **+1 setiap kali** short link diakses
- [ ] Buka `?c=KODE_SALAH` → halaman **"Link tidak ditemukan"** dengan status **404**
- [ ] Kode yang tidak ada **tidak** mengubah database (tidak ada klik, tidak ada baris baru)
- [ ] `curl -s -i http://localhost:8000/?c=demo4` menampilkan `302` + `Location: ...`
- [ ] Tidak ada error *"headers already sent"*
- [ ] Form (Fase 2–3) tetap berfungsi normal

**Semua centang? → Fase 4 selesai.** Lanjut ke **Fase 5 (Dashboard Riwayat & Statistik)**: menampilkan semua link dalam tabel lengkap dengan waktu pembuatan dan jumlah klik.

---

## Referensi Dokumentasi (dipakai saat menyusun panduan ini)

* [PHP Manual — header()](https://www.php.net/manual/en/function.header.php) — kasus khusus header `Location:` (otomatis 302), kewajiban `exit`, dan larangan output sebelum header
* [PHP Manual — http_response_code()](https://www.php.net/manual/en/function.http-response-code.php) — cara standar menyetel status 404
* [PHP Manual — PDO prepared statements](https://www.php.net/manual/en/pdo.prepared-statements.php) — query aman untuk `SELECT`/`UPDATE`
* [PHP Manual — exit](https://www.php.net/manual/en/function.exit.php) — menghentikan eksekusi setelah mengirim header
* [RFC 7231 §7.1.2 — Location Header Field](https://datatracker.ietf.org/doc/html/rfc7231#section-7.1.2) — spesifikasi header `Location`
* [PHP 8.5 Release Announcement](https://www.php.net/releases/8.5/en.php) — versi PHP yang dipakai proyek ini (8.5.x stabil per Oktober 2026)
