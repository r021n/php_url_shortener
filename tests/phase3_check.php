<?php

declare(strict_types=1);

/**
 * Verifikasi Fase 3 (alat bantu pengembang — TIDAK dibahas di panduan).
 *
 * Jalankan dari root proyek SETELAH Fase 3 diimplementasikan:
 *
 *   php tests/phase3_check.php
 *
 * Exit code 0 = semua lulus, 1 = ada yang gagal.
 * Data uji dibersihkan di akhir sehingga aman dijalankan berulang kali.
 *
 * Skrip ini juga bisa dipanggil dengan `--simulate <payload-base64>` untuk
 * menjalankan SATU simulasi request ke index.php di proses terpisah
 * (dipanggil otomatis oleh mode verifikasi di bawah).
 */

// ------------------------------------------------------------------
// Mode anak: simulasi request ke index.php di proses terpisah.
// ------------------------------------------------------------------
if (($argv[1] ?? '') === '--simulate') {
    $payload = json_decode((string) base64_decode((string) ($argv[2] ?? '')), true) ?: [];

    $_SERVER['REQUEST_METHOD'] = (string) ($payload['_method'] ?? 'POST');
    $_SERVER['HTTP_HOST']      = 'localhost:8000';
    $_POST                     = $payload['_post'] ?? [];

    ob_start();
    require dirname(__DIR__) . '/index.php';
    echo base64_encode((string) ob_get_clean());
    exit(0);
}

// ------------------------------------------------------------------
// Mode verifikasi
// ------------------------------------------------------------------
$root  = dirname(__DIR__);
$fails = 0;

function check(bool $ok, string $label): void
{
    global $fails;
    if ($ok) {
        echo '[PASS] ' . $label . PHP_EOL;
    } else {
        echo '[FAIL] ' . $label . PHP_EOL;
        $fails++;
    }
}

/** Menjalankan satu simulasi request ke index.php (proses anak) dan
 *  mengembalikan HTML hasilnya. */
function simulate(array $post, string $method = 'POST'): string
{
    $payload = base64_encode((string) json_encode(['_method' => $method, '_post' => $post]));
    $cmd     = escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg(__FILE__) . ' --simulate '
        . escapeshellarg($payload);

    return (string) base64_decode((string) shell_exec($cmd));
}

function row_count(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM links')->fetchColumn();
}

// ---------------------------------------------------------------
// 1. File & fungsi
// ---------------------------------------------------------------
check(is_file($root . '/index.php'), 'index.php ada');
check(is_file($root . '/lib/link.php'), 'lib/link.php ada');

require_once $root . '/config/database.php';
require_once $root . '/lib/validation.php';
require_once $root . '/lib/link.php';

foreach (['generate_short_code', 'alias_exists', 'save_link'] as $fn) {
    check(function_exists($fn), "fungsi {$fn}() terdefinisi");
}

$pdo = db();

// Baseline untuk memastikan data uji selalu dibersihkan.
$baseline = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM links')->fetchColumn();

// ---------------------------------------------------------------
// 2. generate_short_code()
// ---------------------------------------------------------------
$code = generate_short_code($pdo);
check(is_string($code), 'generate_short_code: mengembalikan string');
check(strlen($code) === 6, 'generate_short_code: panjang tepat 6 karakter');
check(ctype_xdigit($code), 'generate_short_code: hanya karakter heksadesimal');

// Pastikan 10 kode berurutan semuanya unik (tidak ada tabrakan).
$codes = [];
for ($i = 0; $i < 10; $i++) {
    $codes[] = generate_short_code($pdo);
}
check(count($codes) === count(array_unique($codes)), 'generate_short_code: 10 kode berurutan unik');

// Pastikan generator tidak mengembalikan kode yang sudah ada di database.
$existingCode = 'existing_' . bin2hex(random_bytes(3));
save_link($pdo, 'https://example.com/existing', $existingCode);
$generated = generate_short_code($pdo);
check($generated !== $existingCode, 'generate_short_code: menghindari kode yang sudah ada');

// ---------------------------------------------------------------
// 3. Simulasi request end-to-end ke index.php
// ---------------------------------------------------------------
$jumlahAwal = row_count($pdo);

// 3a. POST tanpa alias -> kode 6 karakter, bukan pending_, short link tampil.
$html = simulate(['url' => 'https://example.com/fase3-tanpa-alias', 'alias' => '']);
check(str_contains($html, 'URL berhasil disimpan'), 'POST tanpa alias: pesan sukses tampil');
check(str_contains($html, '?c='), 'POST tanpa alias: short link tampil di HTML');
check(row_count($pdo) === $jumlahAwal + 1, 'POST tanpa alias: tepat 1 baris baru');

$stmt = $pdo->prepare('SELECT * FROM links WHERE original_url = :url');
$stmt->execute([':url' => 'https://example.com/fase3-tanpa-alias']);
$baris = $stmt->fetch();
$stmt->closeCursor();
check($baris !== false, 'POST tanpa alias: baris tersimpan');
if ($baris !== false) {
    $shortCode = (string) $baris['short_code'];
    check(strlen($shortCode) === 6, 'POST tanpa alias: short_code 6 karakter');
    check(!str_starts_with($shortCode, 'pending_'), 'POST tanpa alias: short_code bukan placeholder');
    check(str_contains($html, $shortCode), 'POST tanpa alias: short_code muncul di HTML');
    check(str_contains($html, 'http://localhost:8000/?c=' . $shortCode), 'POST tanpa alias: format short link benar');
}

// 3b. POST dengan custom alias -> kode = alias, short link tampil.
$html = simulate(['url' => 'https://example.com/fase3-alias', 'alias' => 'promo3uji']);
check(str_contains($html, 'URL berhasil disimpan'), 'POST dengan alias: pesan sukses tampil');
check(str_contains($html, '?c=promo3uji'), 'POST dengan alias: short link tampil di HTML');
check(row_count($pdo) === $jumlahAwal + 2, 'POST dengan alias: tepat 1 baris baru');

$stmt = $pdo->prepare('SELECT * FROM links WHERE short_code = :code');
$stmt->execute([':code' => 'promo3uji']);
$barisAlias = $stmt->fetch();
$stmt->closeCursor();
check((string) ($barisAlias['short_code'] ?? '') === 'promo3uji', 'POST dengan alias: short_code = alias user');

// 3c. Alias duplikat -> ditolak, tidak ada baris baru.
$html = simulate(['url' => 'https://example.com/fase3-duplikat', 'alias' => 'promo3uji']);
check(str_contains($html, 'sudah dipakai'), 'POST alias duplikat: pesan error tampil');
check(row_count($pdo) === $jumlahAwal + 2, 'POST alias duplikat: tidak ada baris baru');

// 3d. Alias tidak sah -> ditolak.
$html = simulate(['url' => 'https://example.com/fase3-alias-jelek', 'alias' => 'my alias!']);
check(str_contains($html, 'huruf dan angka'), 'POST alias tidak sah: pesan error tampil');
check(row_count($pdo) === $jumlahAwal + 2, 'POST alias tidak sah: tidak ada baris baru');

// 3e. URL tidak valid -> ditolak.
$html = simulate(['url' => 'bukan-url', 'alias' => '']);
check(str_contains($html, 'URL tidak valid'), 'POST URL invalid: pesan error tampil');
check(row_count($pdo) === $jumlahAwal + 2, 'POST URL invalid: tidak ada baris baru');

// ---------------------------------------------------------------
// 4. Bersihkan SEMUA data uji
// ---------------------------------------------------------------
$pdo->prepare('DELETE FROM links WHERE id > :base')->execute([':base' => $baseline]);
check(row_count($pdo) === (int) $pdo->query('SELECT COUNT(*) FROM links WHERE id <= ' . $baseline)->fetchColumn(), 'data uji berhasil dibersihkan');

// ---------------------------------------------------------------
// Ringkasan
// ---------------------------------------------------------------
echo PHP_EOL;
if ($fails === 0) {
    echo 'Semua pemeriksaan Fase 3 LULUS.' . PHP_EOL;
    exit(0);
}
echo "ADA {$fails} PEMERIKSAAN YANG GAGAL." . PHP_EOL;
exit(1);
