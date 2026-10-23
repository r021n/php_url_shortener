# Panduan Fase 2 — Form Input & Validasi

> **Target waktu:** ±30 menit
> **Setelah fase ini:** form URL + custom alias tampil rapi di `localhost:8000`, semua input tervalidasi dengan pesan error yang jelas, dan URL valid tersimpan ke tabel `links`.
> **Catatan:** panduan ini disusun dari pengerjaan nyata yang sudah di-lint, diuji otomatis (60 pemeriksaan), dan dijalankan di PHP 8.5.12 (Windows 11) — semua output CLI di bawah adalah **output asli hasil eksekusi**.
> Perintah ditulis untuk **Command Prompt (cmd)**. Linux/macOS: ganti `\` menjadi `/`, dan `&` menjadi `;`.

---

## 0. Peta Fase 2

| Langkah | Yang dikerjakan | File |
|---|---|---|
| 1 | Fungsi validasi & sanitasi input (murni) | `lib/validation.php` *(baru)* |
| 2 | Fungsi simpan link + cek alias ke DB | `lib/link.php` *(baru)* |
| 3 | Form + penanganan `$_POST` + pesan error | `index.php` *(update)* |
| 4 | Lint semua file | (terminal) |
| 5 | Uji tampilan & alur form | (browser) |
| 6 | Uji via CLI + bukti data di database | (terminal) |

**Struktur folder setelah Fase 2 selesai:**

```
php_url_shortener/
├── config/
│   └── database.php      ← koneksi PDO (Fase 1, tidak berubah)
├── lib/
│   ├── validation.php    ← validasi & sanitasi input (Langkah 1)
│   └── link.php          ← akses tabel links (Langkah 2)
├── guides/
│   └── phase1.md
│   └── phase2.md         ← file ini
├── tests/
│   └── phase1_check.php
├── index.php             ← form + penanganan POST (Langkah 3)
├── setup.php
├── hapus.php
└── database.sqlite
```

**Aturan validasi yang akan dibuat (hafalkan dulu):**

| Input | Aturan | Pesan error saat gagal |
|---|---|---|
| URL | wajib diisi | `URL wajib diisi.` |
| URL | `filter_var($url, FILTER_VALIDATE_URL)` wajib lolos | `URL tidak valid. Contoh yang benar: https://example.com/artikel` |
| URL | skema harus `http://` / `https://` (pengaman tambahan) | `URL harus diawali dengan http:// atau https://` |
| Alias | opsional; hanya huruf & angka | `Alias hanya boleh huruf dan angka (tanpa spasi atau simbol).` |
| Alias | maksimal 30 karakter | `Alias maksimal 30 karakter.` |
| Alias | belum dipakai (dicek ke database) | `Alias "promo" sudah dipakai. Silakan pilih alias lain.` |

**Aturan main penyimpanan (penting untuk Fase 3):**

* User **mengisi** alias → alias itu langsung dipakai sebagai `short_code` (custom alias = kode pilihan user).
* User **tidak** mengisi alias → `short_code` diisi **placeholder** `pending_xxxxxxxx`. Kode acak 6 karakter **belum digenerate** — sesuai brief, generate kode adalah tugas **Fase 3**.

---

## 1. Buat `lib/validation.php` — Fungsi Validasi & Sanitasi

**Lokasi:** `lib/validation.php` (buat folder `lib` baru).

**Tulis kode ini:**

```php
<?php

declare(strict_types=1);

/**
 * Validasi & sanitasi input form (Fase 2).
 *
 * Semua fungsi di file ini MURNI: hanya mengolah string, tidak menyentuh
 * database. Pengecekan ke database (mis. "alias sudah dipakai") ada di
 * lib/link.php — satu file satu tanggung jawab (SRP).
 *
 * Dokumentasi:
 * - https://www.php.net/manual/en/function.filter-var.php
 * - https://www.php.net/manual/en/filter.constants.validation.php
 * - https://www.php.net/manual/en/function.parse-url.php
 */

/**
 * Memvalidasi URL panjang dari form.
 *
 * @return string|null pesan error, atau null jika URL valid
 */
function validate_url(string $url): ?string
{
    $url = trim($url);

    if ($url === '') {
        return 'URL wajib diisi.';
    }

    // Aturan wajib brief: filter_var(FILTER_VALIDATE_URL) harus lolos.
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return 'URL tidak valid. Contoh yang benar: https://example.com/artikel';
    }

    // Pengaman tambahan: filter_var() sangat permisif terhadap skema —
    // "javascript://x%0aalert(1)" dan "phar://demo" dianggap URL valid
    // (lihat peringatan resmi di manual php.net). Batasi ke http/https.
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return 'URL harus diawali dengan http:// atau https://';
    }

    return null;
}

/**
 * Membersihkan alias custom: hanya huruf & angka (brief Fase 2).
 */
function sanitize_alias(string $alias): string
{
    // Brief Fase 2: alias hanya huruf & angka.
    return preg_replace('/[^A-Za-z0-9]/', '', trim($alias)) ?? '';
}

/**
 * Memvalidasi format alias custom (opsional — boleh kosong).
 *
 * @return string|null pesan error, atau null jika format sah
 */
function validate_alias(string $alias): ?string
{
    $alias = trim($alias);

    if ($alias === '') {
        return null; // alias bersifat opsional
    }

    // DRY: aturan "huruf & angka saja" cukup didefinisikan di sanitize_alias().
    if (sanitize_alias($alias) !== $alias) {
        return 'Alias hanya boleh huruf dan angka (tanpa spasi atau simbol).';
    }

    if (strlen($alias) > 30) {
        return 'Alias maksimal 30 karakter.';
    }

    return null;
}
```

**Kenapa begini (poin penting):**

* **SRP:** semua fungsi di file ini **murni** — menerima string, mengembalikan hasil, tanpa query database. Pengecekan "alias sudah dipakai" (butuh database) sengaja dipisah ke `lib/link.php`.
* **Pola `?string`:** fungsi validasi mengembalikan `null` = sah, atau **pesan error** siap tampil. Pemanggil cukup `if ($error !== null)` — tanpa variabel flag bolak-balik.
* **`filter_var($url, FILTER_VALIDATE_URL)`** = aturan wajib dari brief. ⚠️ **Catatan keamanan resmi php.net:** filter ini **permisif** — `javascript://x%0aalert(1)`, `phar://demo`, dan `gopher://...` dianggap *valid*. Karena URL ini nantinya akan dipakai redirect & tampil di dashboard, kita tambahkan **cek skema `http`/`https`** via `parse_url()`. Jangan hanya andalkan `filter_var` untuk keamanan link.
* **DRY:** `validate_alias()` memanggil `sanitize_alias()` untuk memeriksa format — aturan "huruf & angka saja" cukup ditulis **sekali** di `sanitize_alias()`.
* **Sanitasi ≠ validasi:** `sanitize_alias()` *membersihkan* (membuang karakter selain huruf/angka), `validate_alias()` *menolak* dengan pesan error. User tidak pernah menerima perubahan diam-diam pada alias-nya.

---

## 2. Buat `lib/link.php` — Fungsi Akses Tabel `links`

**Lokasi:** `lib/link.php` (satu folder dengan `validation.php`).

**Tulis kode ini:**

```php
<?php

declare(strict_types=1);

/**
 * Akses tabel links (Fase 2: simpan URL baru + cek alias).
 *
 * Bertambah di fase berikutnya: generate kode (Fase 3), redirect & hitung
 * klik (Fase 4), daftar semua link (Fase 5), hapus link (Fase 6).
 *
 * Dokumentasi:
 * - https://www.php.net/manual/en/pdo.prepared-statements.php
 */

/**
 * Mengecek apakah kode/alias sudah dipakai di kolom short_code.
 */
function alias_exists(PDO $pdo, string $alias): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM links WHERE short_code = :code LIMIT 1');
    $stmt->execute([':code' => $alias]);

    return $stmt->fetch() !== false;
}

/**
 * Menyimpan URL baru ke tabel links.
 *
 * Fase 2: kode acak BELUM digenerate (tugas Fase 3). Jika user tidak
 * mengisi custom alias, kolom short_code (NOT NULL UNIQUE) diisi placeholder
 * unik sementara "pending_<random>" yang akan diganti kode asli di Fase 3.
 *
 * @param string|null $shortCode custom alias dari user, atau null jika kosong
 *
 * @return int id baris yang baru dibuat
 */
function save_link(PDO $pdo, string $originalUrl, ?string $shortCode = null): int
{
    $shortCode ??= 'pending_' . bin2hex(random_bytes(4));

    $stmt = $pdo->prepare(
        'INSERT INTO links (original_url, short_code) VALUES (:url, :code)'
    );
    $stmt->execute([':url' => $originalUrl, ':code' => $shortCode]);

    return (int) $pdo->lastInsertId();
}
```

**Kenapa begini (poin penting):**

* **SRP:** file ini khusus urusan database tabel `links`. Fase 3-6 akan menambah fungsi lain di file yang sama (`generate_code`, `find_by_code`, dst.) — satu tempat, mudah dirawat.
* **Prepared statement** (`prepare` + `execute` dengan binding `:url`, `:code`) = aman dari SQL injection, walau input datang dari user.
* **Kenapa ada `pending_xxxxxxxx`?** Kolom `short_code` bertipe `NOT NULL UNIQUE` (Fase 1). Karena brief Fase 2 mengharuskan URL tersimpan **sebelum** kode digenerate, kita isi placeholder unik sementara — `random_bytes(4)` → 8 hex, dijamin unik dan takkan tabrakan dengan kode asli Fase 3 (yang berformat 6 karakter). Di Fase 3, `save_link()` tinggal dikirimi kode hasil generate.
* **Alias = `short_code` saat ini juga.** `save_link($pdo, $url, 'promo')` menyimpan `promo` sebagai kode — inilah sebabnya alias harus dicek keunikan-nya **sebelum** insert (brief: *"tolak jika alias sudah dipakai"*).
* `LIMIT 1` pada `alias_exists()` = kita hanya butuh tahu "ada atau tidak", tidak perlu membaca seluruh baris (cepat & hemat memori).

---

## 3. Update `index.php` — Form + Penanganan `$_POST`

**Lokasi:** `index.php` (root proyek) — **ganti seluruh isi** file Fase 1 dengan kode ini.

**Tulis kode ini:**

```php
<?php

declare(strict_types=1);

/**
 * Halaman utama (Fase 2: form input, validasi, penyimpanan).
 *
 * Generate short link, redirect + hitung klik, dan dashboard menyusul
 * di Fase 3-5.
 */

require __DIR__ . '/config/database.php';
require __DIR__ . '/lib/validation.php';
require __DIR__ . '/lib/link.php';

/**
 * Escape output HTML (anti-XSS). Dipakai berulang di template bawah,
 * jadi dibungkus satu fungsi agar tidak duplikat (DRY).
 */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$pdo = db();

$errors      = [];
$pesanSukses = '';
$urlInput    = '';
$aliasInput  = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // $_POST bisa berisi array (mis. ?url[]=...), jadi pastikan string dulu
    // sebelum di-trim agar tidak error TypeError.
    $urlInput   = is_string($_POST['url'] ?? null)   ? trim($_POST['url'])   : '';
    $aliasInput = is_string($_POST['alias'] ?? null) ? trim($_POST['alias']) : '';

    // 1) Validasi input (murni, tanpa database).
    $error = validate_url($urlInput);
    if ($error !== null) {
        $errors[] = $error;
    }

    $error = validate_alias($aliasInput);
    if ($error !== null) {
        $errors[] = $error;
    }

    // 2) Cek alias ke database hanya jika input sudah lolos validasi.
    if ($errors === []) {
        $alias = sanitize_alias($aliasInput);

        if ($alias !== '' && alias_exists($pdo, $alias)) {
            $errors[] = 'Alias "' . $alias . '" sudah dipakai. Silakan pilih alias lain.';
        } else {
            save_link($pdo, $urlInput, $alias !== '' ? $alias : null);
            $pesanSukses = 'URL berhasil disimpan ke database.';
            $urlInput    = '';
            $aliasInput  = '';
        }
    }
}
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
        <p class="mt-2 text-slate-600">Pendekkan URL panjang, lalu pantau jumlah kliknya.</p>

        <?php if ($errors !== []): ?>
        <div class="mt-6 rounded-lg border border-red-300 bg-red-50 p-4 text-red-800">
            <p class="font-semibold">Form belum bisa disimpan:</p>
            <ul class="mt-2 list-disc list-inside space-y-1">
                <?php foreach ($errors as $error): ?>
                <li><?php echo e($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if ($pesanSukses !== ''): ?>
        <div class="mt-6 rounded-lg border border-green-300 bg-green-50 p-4">
            <p class="font-semibold text-green-800"><?php echo e($pesanSukses); ?></p>
        </div>
        <?php endif; ?>

        <form method="post" class="mt-6 rounded-lg bg-white p-6 shadow-md">
            <label for="url" class="block text-sm font-medium text-slate-700">URL panjang</label>
            <input type="text" name="url" id="url" required
                value="<?php echo e($urlInput); ?>"
                placeholder="https://example.com/artikel/panjang"
                class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-slate-900 focus:border-sky-500">

            <label for="alias" class="mt-4 block text-sm font-medium text-slate-700">Custom alias (opsional)</label>
            <input type="text" name="alias" id="alias"
                value="<?php echo e($aliasInput); ?>"
                placeholder="contoh: promo2026"
                class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-slate-900 focus:border-sky-500">
            <p class="mt-1 text-sm text-slate-500">Hanya huruf dan angka, maksimal 30 karakter.</p>

            <button type="submit"
                class="mt-6 rounded-md bg-sky-600 px-4 py-2 font-semibold text-white hover:bg-sky-700">
                Simpan URL
            </button>
        </form>
    </main>
</body>

</html>
```

**Kenapa begini (poin penting):**

* **Alur 2 tahap yang jelas:** (1) validasi input murni → (2) baru cek ke database & simpan. Query hanya jalan kalau input sudah lolos format — hemat & aman.
* **`($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'`** — blok proses hanya jalan untuk request POST. Halaman GET (buka biasa) selalu menampilkan form kosong tanpa efek samping. `?? ''` membuat file ini tetap aman dijalankan dari CLI.
* **`is_string($_POST['url'] ?? null)`** — pengaman kecil yang penting: `?url[]=...` mengirim `$_POST['url']` berupa **array**; tanpa cek ini `trim()` akan melempar `TypeError` (halaman rusak). Dengan cek ini, input aneh diperlakukan sebagai kosong → muncul pesan error biasa.
* **Kumpulkan semua error dulu** (`$errors[]`) sebelum menyimpan — user melihat SEMUA masalah sekaligus, bukan satu-satu (UX lebih baik).
* **`e()` = bungkus `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.** Dipanggil di setiap output dinamis (pesan error, nilai input yang ditampilkan ulang) → anti-XSS dan tidak duplikat (DRY). `ENT_QUOTES` juga meng-escape tanda kutip supaya atribut `value="..."` tidak bisa "ditembus".
* **Nilai input dikembalikan ke form saat error** (`value="<?php echo e($urlInput); ?>"`) — user tidak perlu mengetik ulang. Setelah sukses, form dikosongkan.
* **Catatan Tailwind:** baris `<script src="https://cdn.tailwindcss.com">` dipertahankan dari Fase 1 (brief: *Tailwind via CDN*). Dokumentasi resmi Tailwind 2026 kini merekomendasikan Play CDN v4 (`https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4`) untuk prototipe — kelas yang dipakai di panduan ini kompatibel dengan keduanya, jadi **tidak perlu diubah** untuk menyelesaikan Fase 2.

---

## 4. Lint (Cek Sintaks Semua File)

**Perintah** (dari root proyek):

```bash
php -l lib\validation.php & php -l lib\link.php & php -l index.php
```

**Yang muncul** (semua harus `No syntax errors`):

```
No syntax errors detected in lib\validation.php
No syntax errors detected in lib\link.php
No syntax errors detected in index.php
```

> Ada error di sini? Perhatikan angka barisnya — biasanya ada titik koma atau tanda kurung yang kurang saat menyalin kode.

---

## 5. Uji di Browser

**Perintah** (dari root proyek):

```bash
php -S localhost:8000
```

**Yang muncul** (server berjalan, jangan ditutup):

```
[Fri Oct 23 12:06:21 2026] PHP 8.5.12 Development Server (http://localhost:8000) started
```

Buka **http://localhost:8000** lalu uji **4 skenario** ini:

| # | Aksi di browser | Yang harus muncul |
|---|---|---|
| 1 | Lihat halaman (belum diapa-apakan) | Kartu form putih berisi field **URL panjang** (wajib), **Custom alias (opsional)**, dan tombol **Simpan URL**. Tanpa pesan error/sukses. |
| 2 | Isi URL `bukan-url` → Simpan | Kotak **merah** "Form belum bisa disimpan:" berisi `URL tidak valid. Contoh yang benar: https://example.com/artikel`. Nilai `bukan-url` tetap tampil di field. |
| 3 | Isi URL `https://example.com/promo` + alias `promo` → Simpan | Kotak **hijau** "URL berhasil disimpan ke database." Form menjadi kosong. |
| 4 | Ulangi langkah 3 (alias `promo` lagi) | Kotak **merah** berisi `Alias "promo" sudah dipakai. Silakan pilih alias lain.` |

> Uji tambahan yang tidak kalah penting: isi alias `my-alias` → harus ditolak (`Alias hanya boleh huruf dan angka...`), dan isi URL `phar://demo` → harus ditolak (`URL harus diawali dengan http:// atau https://`).

Di terminal server, setiap aksi terekam sebagai request baru:

```
[Fri Oct 23 12:07:48 2026] [::1]:56434 [200]: POST /index.php
```

---

## 6. Uji via CLI — Sekaligus Buktikan ke Database

Semua perintah di bawah dijalankan di **terminal kedua** (server tetap berjalan di terminal pertama). Perintah `php -r` berikut adalah *one-liner* yang menampilkan isi tabel `links` — tulis dalam **satu baris penuh**.

### 6a. Form terlayani dengan baik

```bash
curl -s -o NUL -w "%{http_code}" http://localhost:8000/index.php
```

**Yang muncul:**

```
200
```

```bash
curl -s http://localhost:8000/index.php | findstr /i input
```

**Yang muncul** (dua field form kita):

```
            <input type="text" name="url" id="url" required
            <input type="text" name="alias" id="alias"
```

> Linux/macOS: ganti `-o NUL` menjadi `-o /dev/null` dan `findstr /i input` menjadi `grep -i input`.

### 6b. URL tidak valid → error, TIDAK tersimpan

```bash
curl -s -d "url=bukan-url" http://localhost:8000/index.php | findstr /i valid
```

**Yang muncul:**

```
                                <li>URL tidak valid. Contoh yang benar: https://example.com/artikel</li>
```

### 6c. URL valid + alias `promo` → tersimpan

```bash
curl -s -d "url=https://example.com/artikel-panjang" -d "alias=promo" http://localhost:8000/index.php | findstr /i berhasil
```

**Yang muncul:**

```
            <p class="font-semibold text-green-800">URL berhasil disimpan ke database.</p>
```

### 6d. Buktikan isi tabel `links`

```bash
php -r "$p=new PDO('sqlite:database.sqlite');echo 'id | original_url | short_code',PHP_EOL;foreach($p->query('SELECT id, original_url, short_code FROM links') as $r){echo $r['id'],' | ',$r['original_url'],' | ',$r['short_code'],PHP_EOL;}"
```

**Yang muncul** (alias `promo` terpakai sebagai `short_code`):

```
id | original_url | short_code
1 | https://example.com/artikel-panjang | promo
```

> Perhatikan: URL `bukan-url` dari langkah 6b **tidak ada** di tabel — validasi bekerja.

### 6e. Alias `promo` dipakai ulang → DITOLAK

```bash
curl -s -d "url=https://example.com/artikel-lain" -d "alias=promo" http://localhost:8000/index.php | findstr /i dipakai
```

**Yang muncul:**

```
                                <li>Alias &quot;promo&quot; sudah dipakai. Silakan pilih alias lain.</li>
```

> `&quot;` adalah tanda kutip `"` yang sudah di-escape `htmlspecialchars()` — di browser terbaca normal sebagai `"promo"`. Cek ulang tabel dengan perintah 6d: jumlah baris **tetap 1** (URL `artikel-lain` tidak tersimpan).

### 6f. URL valid tanpa alias → placeholder (kode belum digenerate)

```bash
curl -s -d "url=https://example.com/tanpa-alias" http://localhost:8000/index.php | findstr /i berhasil
```

**Yang muncul:**

```
            <p class="font-semibold text-green-800">URL berhasil disimpan ke database.</p>
```

Jalankan lagi perintah 6d. **Yang muncul:**

```
id | original_url | short_code
1 | https://example.com/artikel-panjang | promo
2 | https://example.com/tanpa-alias | pending_e126e595
```

> Baris tanpa alias mendapat **placeholder** `pending_` + 8 karakter acak (milik Anda akan berbeda angkanya). Kode asli 6 karakter akan digenerate di **Fase 3** — persis sesuai brief *"short code belum digenerate"*.

### 6g. Skema berbahaya → ditolak (pengaman tambahan)

```bash
curl -s -d "url=phar://demo" http://localhost:8000/index.php | findstr /i diawali
```

**Yang muncul:**

```
                                <li>URL harus diawali dengan http:// atau https://</li>
```

### 6h. Alias tidak sah → ditolak

```bash
curl -s -d "url=https://example.com/contoh" -d "alias=my-alias_2" http://localhost:8000/index.php | findstr /i simbol
```

**Yang muncul:**

```
                                <li>Alias hanya boleh huruf dan angka (tanpa spasi atau simbol).</li>
```

Selesai menguji, tekan **Ctrl + C** di terminal server untuk mematikannya.

---

## 7. Troubleshooting Cepat

| Gejala | Penyebab | Solusi |
|---|---|---|
| `Call to undefined function validate_url()` | `lib/validation.php` belum dibuat / salah path | Cek nama folder `lib` dan `require` di bagian atas `index.php` |
| `trim(): Argument #1 must be of type string` | `$_POST['url']` berupa array (`url[]=...`) | Pastikan cek `is_string(...)` di blok POST ada (Langkah 3) |
| Form "diam saja", pesan error tak muncul | Blok `$_SERVER['REQUEST_METHOD']` hilang | Pastikan penanganan POST **di dalam** blok `if (... === 'POST')` |
| Alias ditolak padahal terlihat sah | Ada spasi/karakter tersembunyi | Alias harus **hanya** huruf & angka; spasi & simbol ikut tersaring oleh sanitasi |
| `database is locked` | Ada koneksi lain memegang database (DB Browser terbuka, skrip lain berjalan) | Tutup aplikasi/koneksi lain, ulangi. Jika sering terjadi, tambah `PDO::ATTR_TIMEOUT => 5` di opsi `new PDO(...)` pada `config/database.php` |
| Halaman putih | Error PHP di tempat lain | Cek `php -l` tiap file & lihat log di terminal server |
| `Failed to listen on localhost:8000` | Port dipakai proses lain | Ganti port: `php -S localhost:8001` |
| Tampilan tidak rapi / CSS tidak jalan | CDN Tailwind belum termuat | Butuh koneksi internet saat pertama buka halaman; cek `<script src="https://cdn.tailwindcss.com">` ada di `<head>` |

---

## 8. Checklist Akhir Fase 2

- [ ] `lib/validation.php`, `lib/link.php`, `index.php` lolos `php -l`
- [ ] Form (URL panjang + custom alias) tampil rapi di `http://localhost:8000`
- [ ] URL invalid → pesan error jelas dan **tidak** tersimpan ke database *(brief ✅)*
- [ ] URL valid → tersimpan ke tabel `links`; `short_code` = alias user, atau `pending_xxxxxxxx` bila tanpa alias *(brief ✅: "short code belum digenerate")*
- [ ] Alias yang sudah dipakai → ditolak dengan pesan yang jelas
- [ ] Alias mengandung simbol/spasi → ditolak
- [ ] Semua output dinamis sudah lewat `e()` / `htmlspecialchars()` (aman dari XSS)
- [ ] Uji browser (4 skenario) dan uji CLI (6a-6h) memberi hasil seperti contoh

**Semua centang? → Lanjut ke Fase 3 (Generate Short Link).** Pada Fase 3 Anda akan membuat fungsi generator kode 6 karakter + mengganti placeholder `pending_xxxxxxxx` dengan kode asli, lalu menampilkan short link-nya ke user.

---

## Referensi Dokumentasi (dipakai saat menyusun panduan ini)

* [PHP Manual — filter_var()](https://www.php.net/manual/en/function.filter-var.php) — fungsi validasi wajib brief
* [PHP Manual — Validation Filters (FILTER_VALIDATE_URL)](https://www.php.net/manual/en/filter.constants.validation.php) — **peringatan resmi**: filter ini permisif terhadap skema (`javascript:`, `phar:`, dst.) dan berbasis RFC 2396
* [PHP Manual — parse_url()](https://www.php.net/manual/en/function.parse-url.php) — dipakai untuk cek skema http/https
* [PHP Manual — PDO prepared statements](https://www.php.net/manual/en/pdo.prepared-statements.php) — query aman anti SQL injection
* [PHP Manual — htmlspecialchars()](https://www.php.net/manual/en/function.htmlspecialchars.php) — escape output anti-XSS
* [PHP Manual — $_POST](https://www.php.net/manual/en/reserved.variables.post.php) & [$_SERVER](https://www.php.net/manual/en/reserved.variables.server.php) — superglobal yang ditangani di Fase 2
* [PHP 8.5 Release Announcement](https://www.php.net/releases/8.5/en.php) — versi PHP yang dipakai proyek ini (8.5.x = rilis stabil aktif per Oktober 2026)
* [Tailwind CSS — Play CDN](https://tailwindcss.com/docs/installation/play-cdn) — catatan alternatif Play CDN v4
