# Panduan Fase 6 — Hapus Link & Finishing

> **Target waktu:** ±20–30 menit
> **Setelah fase ini:** setiap baris di dashboard punya tombol **Hapus** dengan konfirmasi. Menghapus link benar-benar menghapus baris dari database dan dashboard langsung ter-update. Empty state "Belum ada link" tetap tampil rapi saat tabel kosong. **Proyek selesai.**
> **Catatan:** panduan ini disusun dari implementasi nyata yang sudah di-lint dan diuji end-to-end dengan request HTTP sungguhan di PHP 8.5.12 (Windows 11). Semua perintah CLI ditulis untuk **Command Prompt (cmd)**. Linux/macOS: ganti `\` menjadi `/`.

---

## 0. Peta Fase 6

| Langkah | Yang dikerjakan | File |
|---|---|---|
| 1 | Tambah fungsi hapus baris berdasarkan `id` | `lib/link.php` *(update)* |
| 2 | Buat handler POST untuk memproses penghapusan | `hapus.php` *(ganti isi)* |
| 3 | Tambah kolom "Aksi" + tombol hapus per baris | `index.php` *(update)* |
| 4 | Lint semua file | (terminal) |
| 5 | Jalankan server & uji manual di browser | (terminal + browser) |
| 6 | Verifikasi isi database via CLI | (terminal) |

**Struktur folder setelah Fase 6 selesai (PROYEK SELESAI):**

```
php_url_shortener/
├── config/
│   └── database.php      ← koneksi PDO (tidak berubah)
├── lib/
│   ├── validation.php    ← validasi input (tidak berubah)
│   ├── link.php          ← + delete_link() (Langkah 1)
│   └── redirect.php      ← redirect & 404 (tidak berubah)
├── guides/
│   ├── phase1.md … phase5.md
│   └── phase6.md         ← file ini
├── tests/                ← uji otomatis fase 1–6 (tidak dibahas di panduan)
├── index.php             ← + tombol hapus (Langkah 3)
├── setup.php
├── hapus.php             ← handler hapus (Langkah 2)
└── database.sqlite
```

**Prinsip yang dipakai di fase ini:**

* **SRP (Single Responsibility):** query `DELETE` tetap di `lib/link.php`; `hapus.php` hanya mengurus request, validasi, dan redirect; `index.php` hanya merender UI.
* **DRY (Don't Repeat Yourself):** semua akses database lewat `db()`; semua output dinamis lewat `e()`; tidak ada query hapus yang ditulis dua kali.
* **KISS (Keep It Simple):** hapus cukup lewat form POST biasa + `confirm()` bawaan browser. Tidak perlu AJAX, modal, atau library tambahan.

---

## 1. Tambah `delete_link()` di `lib/link.php`

**Lokasi:** `lib/link.php`

**Di mana menulis:** tambahkan fungsi baru **setelah** fungsi `save_link()` (paling bawah file). Jangan mengubah fungsi yang sudah ada.

**Tulis kode ini:**

```php
function delete_link(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare('DELETE FROM links WHERE id = :id');
    $stmt->execute([':id' => $id]);

    return $stmt->rowCount() > 0;
}
```

**Kenapa begini:**

* `prepare()` + parameter `:id` mencegah SQL injection. ID tidak pernah ditempel langsung ke string SQL.
* `DELETE FROM links WHERE id = :id` persis seperti brief.
* `rowCount() > 0` mengembalikan `true` jika ada baris yang benar-benar terhapus, dan `false` jika ID tidak ditemukan. Pemanggil jadi tahu hasilnya tanpa query tambahan.
* Parameter `int $id` memaksa tipenya angka sejak awal (fungsi memakai `declare(strict_types=1)`), sehingga input aneh tidak lolos diam-diam.

**Dokumentasi resmi:**

* <https://www.php.net/manual/en/pdostatement.rowcount.php>
* <https://www.php.net/manual/en/pdostatement.execute.php>
* <https://www.php.net/manual/en/pdo.prepare.php>

---

## 2. Buat handler `hapus.php`

**Lokasi:** `hapus.php` (file sudah ada, tetapi masih placeholder Fase 1)

**Di mana menulis:** **ganti seluruh isi** file `hapus.php` dengan kode di bawah.

**Tulis kode ini:**

```php
<?php

declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/lib/link.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'Metode tidak diizinkan. Gunakan form hapus dari dashboard.';
    exit;
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    echo 'ID link tidak valid.';
    exit;
}

delete_link(db(), $id);

header('Location: index.php', true, 303);
exit;
```

**Penjelasan tiap bagian:**

| Bagian | Fungsi |
|---|---|
| `require ... database.php` + `link.php` | Menyediakan `db()` dan `delete_link()`. |
| `$_SERVER['REQUEST_METHOD'] !== 'POST'` | Menolak akses langsung lewat URL (`GET hapus.php`), supaya penghapusan hanya bisa lewat form. Status **405 Method Not Allowed** + header `Allow: POST`. |
| `filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT)` | Mengambil `id` dari body form dan memastikan isinya bilangan bulat. `false` = gagal validasi, `null` = field tidak dikirim. |
| `$id < 1` | ID database dimulai dari 1; nilai 0 atau negatif pasti tidak valid. |
| `delete_link(db(), $id)` | Menjalankan `DELETE` lewat fungsi di `lib/link.php`. |
| `header('Location: index.php', true, 303)` + `exit` | Setelah hapus, browser diarahkan kembali ke dashboard dengan **303 See Other** — status yang tepat untuk redirect setelah POST (pola POST/Redirect/GET). |

> **Kenapa 303, bukan 302?** Setelah POST berhasil, 303 memastikan browser melakukan `GET` ke `index.php`, sehingga tombol "refresh" tidak mengirim ulang POST dan tidak mencoba menghapus dua kali. Ini pola standar POST/Redirect/GET.

**Dokumentasi resmi:**

* <https://www.php.net/manual/en/function.filter-input.php>
* <https://www.php.net/manual/en/filter.filters.validate.php>
* <https://www.php.net/manual/en/function.header.php>
* <https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/303>

---

## 3. Tambah tombol hapus di `index.php`

Ada **tiga** perubahan kecil di file yang sama. Kerjakan berurutan.

### 3a. Tambah kolom "Aksi" di header tabel

**Lokasi:** `index.php`, di dalam `<thead>` tabel dashboard.

**Cari baris:**

```php
<th class="px-4 py-3 font-semibold text-slate-700">Klik</th>
```

**Tambahkan satu baris tepat setelahnya:**

```php
<th class="px-4 py-3 font-semibold text-slate-700">Aksi</th>
```

### 3b. Sesuaikan `colspan` empty state

**Lokasi:** `index.php`, di dalam blok `if ($links === [])`.

**Ubah baris:**

```php
<td colspan="4" class="px-4 py-6 text-center text-slate-500">Belum ada link.</td>
```

**Menjadi:**

```php
<td colspan="5" class="px-4 py-6 text-center text-slate-500">Belum ada link.</td>
```

**Kenapa:** jumlah kolom sekarang 5 (URL Asli, Short Link, Waktu Pembuatan, Klik, Aksi). Kalau `colspan` tetap 4, pesan empty state tidak menutup seluruh lebar tabel dan tampilannya terlihat pincang.

### 3c. Tambah form hapus di setiap baris

**Lokasi:** `index.php`, di dalam `foreach ($links as $link)`, tepat **setelah** sel jumlah klik dan **sebelum** `</tr>`.

**Cari baris:**

```php
<td><?php echo (int) $link['clicks']; ?></td>
```

**Ubah bagian akhir baris menjadi:**

```php
<td class="px-4 py-3"><?php echo (int) $link['clicks']; ?></td>
<td class="px-4 py-3 text-right">
    <form method="post" action="hapus.php" onsubmit="return confirm('Hapus link ini? Tindakan ini tidak bisa dibatalkan.');">
        <input type="hidden" name="id" value="<?php echo (int) $link['id']; ?>">
        <button type="submit" class="rounded-md bg-red-600 px-3 py-1 text-xs font-semibold text-white hover:bg-red-700">Hapus</button>
    </form>
</td>
```

**Penjelasan:**

| Bagian | Fungsi |
|---|---|
| `method="post" action="hapus.php"` | Mengirim permintaan hapus lewat POST ke handler. |
| `onsubmit="return confirm(...)"` | Konfirmasi bawaan browser sebelum form dikirim. Jika pengguna klik **Cancel**, form tidak dikirim. |
| `<input type="hidden" name="id" ...>` | Mengirim ID baris yang akan dihapus. Di-escape dengan `(int)` karena `id` selalu angka. |
| `(int) $link['id']` | Memastikan nilai yang dicetak bersih sebagai angka. |

**Catatan keamanan:** ID di-escape dengan `(int)` dan tidak ditampilkan ke pengguna. Validasi sesungguhnya tetap dilakukan di `hapus.php` (`FILTER_VALIDATE_INT`). Jangan mengandalkan validasi sisi klien saja.

---

## 4. Lint semua file

Jalankan dari root proyek:

```cmd
php -l lib/link.php
php -l hapus.php
php -l index.php
```

**Yang akan muncul jika tidak ada kesalahan sintaks:**

```
No syntax errors detected in lib/link.php
No syntax errors detected in hapus.php
No syntax errors detected in index.php
```

Jika ada error, baris output akan menyebut file dan nomor barisnya. Perbaiki dulu sebelum lanjut.

---

## 5. Jalankan server & uji manual di browser

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

1. Tabel dashboard sekarang punya **5 kolom**: URL Asli, Short Link, Waktu Pembuatan, Klik, **Aksi**.
2. Setiap baris punya tombol merah **Hapus** di kolom Aksi.
3. Klik **Hapus** pada salah satu baris → browser menampilkan dialog konfirmasi:
   > *Hapus link ini? Tindakan ini tidak bisa dibatalkan.*
4. Klik **OK** → halaman kembali ke dashboard, dan baris tersebut **hilang** dari tabel.
5. Klik **Cancel** → tidak terjadi apa-apa; baris tetap ada.
6. Buka short link yang sudah dihapus lewat address bar (`http://localhost:8000/?c=KODE`) → muncul halaman **404 "Link tidak ditemukan"**.
7. Kosongkan semua link (hapus satu per satu) → tabel menampilkan pesan **"Belum ada link."** yang menutup seluruh lebar tabel dengan rapi.
8. Tambah link baru lewat form → link langsung muncul di tabel paling atas, lengkap dengan tombol Hapus.

**Uji keamanan method (opsional, lewat terminal):**

Buka terminal baru (biarkan server tetap jalan), lalu jalankan:

```cmd
curl -i http://localhost:8000/hapus.php
```

**Yang akan muncul:**

```
HTTP/1.1 405 Method Not Allowed
Allow: POST
...
Metode tidak diizinkan. Gunakan form hapus dari dashboard.
```

Ini membuktikan `hapus.php` tidak bisa dipanggil langsung lewat URL.

---

## 6. Verifikasi isi database via CLI

Masih dari root proyek. Perintah ini menghitung total baris sebelum dan sesudah penghapusan.

**Hitung total link saat ini:**

```cmd
php -r "require 'config/database.php'; echo 'Total link: ' . db()->query('SELECT COUNT(*) FROM links')->fetchColumn() . PHP_EOL;"
```

**Contoh output:**

```
Total link: 3
```

**Hapus satu baris uji langsung lewat CLI (opsional):**

```cmd
php -r "require 'config/database.php'; require 'lib/link.php'; $id = (int) db()->query('SELECT id FROM links ORDER BY id DESC LIMIT 1')->fetchColumn(); var_export(delete_link(db(), $id)); echo PHP_EOL;"
```

**Contoh output:**

```
true
```

Jika dijalankan lagi dengan ID yang sama (sudah tidak ada):

```cmd
php -r "require 'config/database.php'; require 'lib/link.php'; var_export(delete_link(db(), 999999999)); echo PHP_EOL;"
```

**Contoh output:**

```
false
```

**Hitung ulang untuk memastikan berkurang:**

```cmd
php -r "require 'config/database.php'; echo 'Total link: ' . db()->query('SELECT COUNT(*) FROM links')->fetchColumn() . PHP_EOL;"
```

Angka harus berkurang 1 dari sebelumnya.

---

## 7. Hasil wajib Fase 6 (checklist akhir — PROYEK SELESAI)

Centang semuanya:

* [ ] `delete_link(PDO $pdo, int $id): bool` ada di `lib/link.php`.
* [ ] `delete_link()` memakai `DELETE FROM links WHERE id = :id` dengan prepared statement.
* [ ] `hapus.php` hanya menerima **POST**; akses `GET` ditolak dengan **405**.
* [ ] `hapus.php` memvalidasi `id` dengan `FILTER_VALIDATE_INT` dan menolak nilai < 1 dengan **400**.
* [ ] Setelah hapus berhasil, `hapus.php` mengirim **303** dan redirect ke `index.php`.
* [ ] Dashboard punya kolom **Aksi** dengan tombol **Hapus** per baris.
* [ ] Tombol hapus memakai `onsubmit="return confirm(...)"`.
* [ ] Baris yang dihapus langsung hilang dari dashboard setelah redirect.
* [ ] Empty state **"Belum ada link."** tampil dan `colspan`-nya 5.
* [ ] `php -l lib/link.php`, `php -l hapus.php`, dan `php -l index.php` bersih.
* [ ] Alur utuh berjalan: **Generate → Redirect + Hitung Klik → Statistik → Hapus**.

---

## 8. Troubleshooting cepat

| Gejala | Penyebab umum | Perbaikan |
|---|---|---|
| Klik Hapus → halaman kosong / error 500 | `hapus.php` belum mengganti placeholder atau `delete_link()` belum ada | Pastikan `hapus.php` sudah diisi ulang dan fungsi ada di `lib/link.php`; jalankan `php -l` |
| Muncul "Metode tidak diizinkan" saat klik Hapus | Form tidak memakai `method="post"` | Pastikan `<form method="post" action="hapus.php">` |
| Muncul "ID link tidak valid" | `name="id"` salah / `id` tidak terkirim | Pastikan `<input type="hidden" name="id" value="...">` ada di dalam form |
| Baris terhapus tetapi dashboard masih menampilkan data lama | Redirect tidak jalan / ada output sebelum `header()` | Pastikan tidak ada `echo`/spasi sebelum `header('Location: ...')`; gunakan `exit` setelah redirect |
| Konfirmasi tidak muncul | Atribut `onsubmit` salah ketik atau form di-submit lewat JS lain | Pastikan persis `onsubmit="return confirm('...');"` |
| Empty state tampil tidak selebar tabel | `colspan` masih 4 | Ubah menjadi `colspan="5"` |
| Hapus link yang sudah tidak ada → error | `delete_link()` melempar exception | Fungsi ini aman: `rowCount()` bernilai 0 dan mengembalikan `false`, bukan error |
| Tombol Hapus menghapus baris yang salah | `value` ID tidak sesuai baris | Pastikan `value="<?php echo (int) $link['id']; ?>"` berada di dalam `foreach` yang benar |

---

## 9. Ringkasan perubahan Fase 6

| File | Perubahan |
|---|---|
| `lib/link.php` | Tambah `delete_link(PDO $pdo, int $id): bool` |
| `hapus.php` | Ganti placeholder dengan handler POST: validasi method + ID, `DELETE`, redirect 303 |
| `index.php` | Tambah kolom **Aksi**, tombol **Hapus** + `confirm()`, `colspan` empty state menjadi 5 |

Setelah Fase 6 selesai, aplikasi memenuhi seluruh alur di brief:

**Generate → Redirect + Hitung Klik → Statistik → Hapus**

Semua fitur utama sudah lengkap dan proyek dinyatakan **selesai**.
