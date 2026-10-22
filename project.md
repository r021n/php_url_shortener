**Personal URL Shortener & Click Tracker** 
Proyek ini menghindari kebosanan aplikasi *To-Do List* biasa, namun secara langsung memaksa pemahaman tentang cara kerja fundamental PHP: siklus *request-response*, manipulasi *header* HTTP, *superglobals* (`$_GET`, `$_POST`, `$_SERVER`), dan interaksi database yang aman menggunakan PDO (*PHP Data Objects*).

Dengan total waktu **6 jam (3 hari × 2 jam)**, Anda bisa membangun aplikasi yang utuh dan fungsional tanpa perlu mempelajari *framework* besar seperti Laravel terlebih dahulu.

---

## Teknologi yang Dipakai (Tech Stack)

Untuk efisiensi waktu, kita tidak perlu menginstal *web server* berat seperti XAMPP atau mengonfigurasi MySQL dari awal:

* **Bahasa:** PHP 8.2+ (Vanilla / Native PHP).
* **Database:** **SQLite 3** via ekstensi **PDO**. SQLite berbasis *file* tunggal (`database.sqlite`), sudah aktif secara bawaan di PHP, dan sintaks PDO-nya identik jika nanti Anda beralih ke MySQL/PostgreSQL.
* **Local Server:** **PHP Built-in Web Server**. Cukup jalankan perintah `php -S localhost:8000` di terminal—tidak perlu Apache atau Nginx.
* **Frontend:** HTML5 + **Tailwind CSS (via CDN)** agar tampilan langsung rapi tanpa membuang waktu menulis CSS manual.

---

## Fitur Utama & Komponen Sistem

### 1. Fitur yang Harus Ada

* **Generate Short Link:** Mengubah URL panjang menjadi kode pendek unik (6 karakter acak) atau *custom alias* (opsional).
* **Validasi Input & Sanitasi:** Memastikan string yang dimasukkan adalah URL valid menggunakan fungsi bawaan `filter_var($url, FILTER_VALIDATE_URL)`.
* **Instant Redirection & Click Counter:** Saat *short link* diakses (misal: `localhost:8000/?c=aB3x9Z`), sistem otomatis menambah jumlah klik (`clicks + 1`) di database lalu melakukan *redirect* ke URL asli menggunakan `header("Location: ...")`.
* **Dashboard Riwayat & Statistik:** Tabel di halaman utama yang menampilkan daftar URL asli, *short link* yang bisa diklik, waktu pembuatan, dan total jumlah klik.
* **Hapus Link:** Tombol untuk menghapus *link* tertentu dari database.

---

## Hasil Pengecekan Lingkungan (Sesi: 22 Oktober 2026)

### Spesifikasi Laptop

| Komponen | Detail | Status |
|---|---|---|
| Perangkat | Lenovo ThinkPad T14 Gen 1 (20R3S09B00) | ✅ |
| CPU | Intel Core i5-10310U, 4 core / 8 thread @ 1.7 GHz | ✅ Sangat cukup |
| RAM | 16 GB | ✅ Sangat cukup |
| Storage | NVMe SSD 256 GB (WDC PC SN730) | ✅ Sangat cepat |
| OS | Windows 11 Pro Education (Build 26200) | ✅ |

Project ini sangat ringan — spesifikasi di atas jauh melebihi kebutuhan.

### Tools yang Terpasang

* ✅ **PHP 8.5.12** (via winget) — syarat project: PHP 8.2+
* ✅ **VS Code** 1.143.0
* ✅ **Git** 2.55.0
* ✅ **Node.js** v24.20.0 + npm 11.19.0 *(opsional — Tailwind via CDN tidak membutuhkannya)*
* ❌ ~~XAMPP / Laragon / MySQL~~ — **tidak dibutuhkan** (pakai SQLite + built-in server)

### Perbaikan Konfigurasi yang Dilakukan

1. Membuat `php.ini` dari template `php.ini-development` — sebelumnya tidak ada `php.ini`, sehingga ekstensi tidak termuat sama sekali.
2. Mengaktifkan `extension=pdo_sqlite` dan `extension=sqlite3`.
3. Mengoreksi `extension_dir` agar menunjuk ke folder `ext` PHP instalasi (default salah ke `C:\php\ext`).

**Verifikasi akhir:** `php -m` menampilkan `pdo_sqlite` & `sqlite3`, dan tes koneksi PDO SQLite berhasil.
**Status lingkungan: SIAP 100% — tidak ada tools yang perlu diinstal lagi.**

---

## Fase Pengerjaan (6 Fase × ~30 Menit)

Setiap fase dirancang kecil, tidak overwhelming, dan punya "hasil wajib ada" yang bisa dicek sebelum lanjut ke fase berikutnya.

### Fase 1 — Setup Proyek & Database

**Yang dikerjakan:**

* Buat struktur proyek: `index.php`, `config/database.php`, `hapus.php`.
* Buat koneksi PDO ke SQLite (`database.sqlite`) di `config/database.php`.
* Buat tabel `links`:
  * `id` INTEGER PRIMARY KEY AUTOINCREMENT
  * `original_url` TEXT NOT NULL
  * `short_code` TEXT NOT NULL UNIQUE
  * `clicks` INTEGER DEFAULT 0
  * `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP

**Hasil yang harus ada setelah fase ini:**

* ✅ File `database.sqlite` otomatis terbentuk saat skrip setup dijalankan.
* ✅ Memuat `index.php` tidak menampilkan error koneksi.
* ✅ Tabel `links` bisa ditampilkan (misal `SELECT * FROM links` via skrip kecil) dengan struktur kolom di atas.

### Fase 2 — Form Input & Validasi

**Yang dikerjakan:**

* Form di `index.php`: input URL panjang + input opsional *custom alias* (Tailwind via CDN).
* Tangani `$_POST` dan validasi: `filter_var($url, FILTER_VALIDATE_URL)` wajib lolos.
* Sanitasi input alias (huruf/angka saja), tolak jika alias sudah dipakai.
* Tampilkan pesan error yang jelas saat validasi gagal.

**Hasil yang harus ada setelah fase ini:**

* ✅ Form tampil rapi di `localhost:8000` (jalankan `php -S localhost:8000`).
* ✅ URL invalid → muncul pesan error dan **tidak** tersimpan ke database.
* ✅ URL valid → tersimpan ke tabel `links` (short code belum digenerate).

### Fase 3 — Generate Short Link

**Yang dikerjakan:**

* Fungsi generator kode pendek 6 karakter acak (misal `bin2hex(random_bytes(3))`).
* Cek ke database apakah kode sudah dipakai; ulangi generate jika tabrakan.
* Jika user mengisi *custom alias*, pakai itu (setelah validasi & cek unik).
* Simpan pasangan URL asli + kode, lalu tampilkan short link hasil generate: `localhost:8000/?c=CODE`.

**Hasil yang harus ada setelah fase ini:**

* ✅ Submit form menghasilkan short link unik yang ditampilkan ke pengguna.
* ✅ Setiap submit selalu menghasilkan kode berbeda (tidak duplikat).
* ✅ Baris di tabel `links` kini memiliki `short_code` yang terisi.

### Fase 4 — Redirect & Click Counter

**Yang dikerjakan:**

* Route `/?c=CODE`: cari `short_code` di database.
* Jika ketemu: `UPDATE links SET clicks = clicks + 1 WHERE short_code = :code`, lalu `header("Location: " . $original_url); exit;`.
* Jika tidak ketemu: tampilkan pesan "Link tidak ditemukan" (404).

**Hasil yang harus ada setelah fase ini:**

* ✅ Membuka short link langsung **redirect** ke URL asli di browser.
* ✅ Kolom `clicks` bertambah 1 setiap kali short link diakses.
* ✅ Kode yang tidak ada menampilkan pesan error, bukan halaman kosong.

### Fase 5 — Dashboard Riwayat & Statistik

**Yang dikerjakan:**

* Query semua link `ORDER BY created_at DESC` di halaman utama.
* Tabel dashboard: URL asli, short link (bisa diklik), waktu pembuatan, total klik.
* Escape semua output dengan `htmlspecialchars()` untuk mencegah XSS.

**Hasil yang harus ada setelah fase ini:**

* ✅ Semua link tampil di tabel lengkap dengan waktu dan statistik klik.
* ✅ Short link di dashboard bisa diklik dan berfungsi (terhitung & redirect).
* ✅ Data yang dihapus/ditambah langsung terpantul di tampilan tabel.

### Fase 6 — Hapus Link & Finishing

**Yang dikerjakan:**

* Tombol hapus per baris (form POST ke `hapus.php`), jalankan `DELETE FROM links WHERE id = :id`.
* Konfirmasi sederhana sebelum menghapus (`onsubmit="return confirm(...)"`).
* Polish akhir: *empty state* "Belum ada link", rapikan tampilan, cek ulang semua alur.

**Hasil yang harus ada setelah fase ini (PROYEK SELESAI):**

* ✅ Tombol hapus menghapus baris dari database dan dashboard langsung ter-update.
* ✅ Aplikasi utuh: **Generate → Redirect + Hitung Klik → Statistik → Hapus**.
* ✅ Semua fitur di bagian "Fitur Utama & Komponen Sistem" di atas terpenuhi.