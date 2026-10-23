<?php

declare(strict_types=1);

/**
 * Verifikasi Fase 2 (alat bantu pengembang — TIDAK dibahas di panduan).
 *
 * Jalankan dari root proyek SETELAH Fase 2 diimplementasikan:
 *
 *   php tests/phase2_check.php
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
check(is_file($root . '/lib/validation.php'), 'lib/validation.php ada');
check(is_file($root . '/lib/link.php'), 'lib/link.php ada');

require_once $root . '/config/database.php';
require_once $root . '/lib/validation.php';
require_once $root . '/lib/link.php';

foreach (['validate_url', 'sanitize_alias', 'validate_alias', 'alias_exists', 'save_link'] as $fn) {
    check(function_exists($fn), "fungsi {$fn}() terdefinisi");
}

$pdo = db();

// Baseline untuk memastikan data uji selalu dibersihkan.
$baseline = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM links')->fetchColumn();

// ---------------------------------------------------------------
// 2. validate_url()
// ---------------------------------------------------------------
check(validate_url('') !== null, 'validate_url: kosong -> error');
check(validate_url('    ') !== null, 'validate_url: spasi saja -> error');
check(validate_url('bukan url') !== null, 'validate_url: "bukan url" -> error');
check(validate_url('example.com') !== null, 'validate_url: tanpa skema -> error');
check(validate_url('javascript://x%0aalert(1)') !== null, 'validate_url: skema javascript: -> ditolak');
check(validate_url('phar://demo') !== null, 'validate_url: skema phar: -> ditolak');
check(validate_url('ftp://files.example.com') !== null, 'validate_url: skema ftp: -> ditolak');
check(validate_url('https://example.com') === null, 'validate_url: https://example.com -> valid');
check(validate_url('http://example.com/path?q=1&x=2') === null, 'validate_url: URL dengan query -> valid');
check(validate_url('  https://example.com  ') === null, 'validate_url: spasi tepi diabaikan (trim)');

// ---------------------------------------------------------------
// 3. sanitize_alias() & validate_alias()
// ---------------------------------------------------------------
check(sanitize_alias('  my-alias_2! ') === 'myalias2', 'sanitize_alias: simbol/spasi dibuang');
check(sanitize_alias('@@@') === '', 'sanitize_alias: hanya simbol -> string kosong');
check(sanitize_alias('Promo2026') === 'Promo2026', 'sanitize_alias: huruf+angka dibiarkan');

check(validate_alias('') === null, 'validate_alias: kosong -> sah (opsional)');
check(validate_alias('   ') === null, 'validate_alias: spasi saja -> sah (dianggap kosong)');
check(validate_alias('promo2026') === null, 'validate_alias: "promo2026" -> sah');
check(validate_alias(str_repeat('a', 30)) === null, 'validate_alias: 30 karakter -> sah');
check(validate_alias('my-alias') !== null, 'validate_alias: tanda hubung -> error');
check(validate_alias('my alias') !== null, 'validate_alias: spasi di tengah -> error');
check(validate_alias('alias!') !== null, 'validate_alias: simbol -> error');
check(validate_alias(str_repeat('a', 31)) !== null, 'validate_alias: 31 karakter -> error');

// ---------------------------------------------------------------
// 4. alias_exists() & save_link()
// ---------------------------------------------------------------
check(alias_exists($pdo, 'kode-yang-pasti-belum-ada-999') === false, 'alias_exists: kode belum dipakai -> false');

$id = save_link($pdo, 'https://example.com/uji-fase-2');
check($id > 0, 'save_link: tanpa alias -> id > 0');

$row = $pdo->query('SELECT * FROM links WHERE id = ' . $id)->fetch();
check($row !== false, 'save_link: baris tersimpan');
check(str_starts_with((string) $row['short_code'], 'pending_'), 'save_link: short_code masih placeholder (belum digenerate)');
check((int) $row['clicks'] === 0, 'save_link: clicks default 0');
check((string) $row['original_url'] === 'https://example.com/uji-fase-2', 'save_link: original_url tersimpan utuh');
check(alias_exists($pdo, (string) $row['short_code']) === true, 'alias_exists: kode terpakai -> true');

$idAlias = save_link($pdo, 'https://example.com/uji-alias', 'aliasuji123');
$rowAlias = $pdo->query('SELECT * FROM links WHERE id = ' . $idAlias)->fetch();
check((string) ($rowAlias['short_code'] ?? '') === 'aliasuji123', 'save_link: alias user dipakai sebagai short_code');

// ---------------------------------------------------------------
// 5. Simulasi request end-to-end ke index.php
// ---------------------------------------------------------------
$jumlahAwal = row_count($pdo);

// 5a. GET: form tampil, tidak ada pesan, tidak ada baris baru.
$html = simulate([], 'GET');
check(str_contains($html, '<form method="post"'), 'GET: form tampil');
check(!str_contains($html, 'Form belum bisa disimpan'), 'GET: tidak ada kotak error');
check(!str_contains($html, 'URL berhasil disimpan'), 'GET: tidak ada kotak sukses');
check(row_count($pdo) === $jumlahAwal, 'GET: tidak ada baris baru di database');

// 5b. URL tidak valid -> error dan TIDAK tersimpan.
$html = simulate(['url' => 'bukan-url', 'alias' => '']);
check(str_contains($html, 'URL tidak valid'), 'POST URL invalid: pesan error tampil');
check(row_count($pdo) === $jumlahAwal, 'POST URL invalid: tidak ada baris baru di database');

// 5c. URL valid + alias valid -> tersimpan dengan short_code = alias.
$html = simulate(['url' => 'https://example.com/fase2-e2e', 'alias' => 'promouji']);
check(str_contains($html, 'URL berhasil disimpan'), 'POST valid + alias: pesan sukses tampil');
check(row_count($pdo) === $jumlahAwal + 1, 'POST valid + alias: tepat 1 baris baru');

$stmt = $pdo->prepare('SELECT * FROM links WHERE short_code = :code');
$stmt->execute([':code' => 'promouji']);
$baris = $stmt->fetch();
$stmt->closeCursor(); // bebaskan lock baca SQLite sebelum request lain menulis
check((string) ($baris['original_url'] ?? '') === 'https://example.com/fase2-e2e', 'POST valid + alias: short_code = alias user');

// 5d. Alias yang sama dikirim ulang -> ditolak, tidak ada baris baru.
$html = simulate(['url' => 'https://example.com/fase2-lain', 'alias' => 'promouji']);
check(str_contains($html, 'sudah dipakai'), 'POST alias duplikat: pesan error tampil');
check(row_count($pdo) === $jumlahAwal + 1, 'POST alias duplikat: tidak ada baris baru di database');

// 5e. URL valid tanpa alias -> tersimpan dengan placeholder pending_.
$html = simulate(['url' => 'https://example.com/fase2-tanpa-alias', 'alias' => '']);
check(str_contains($html, 'URL berhasil disimpan'), 'POST valid tanpa alias: pesan sukses tampil');
$stmt = $pdo->prepare('SELECT * FROM links WHERE original_url = :url');
$stmt->execute([':url' => 'https://example.com/fase2-tanpa-alias']);
$baris = $stmt->fetch();
$stmt->closeCursor(); // bebaskan lock baca SQLite sebelum request lain menulis
check(str_starts_with((string) ($baris['short_code'] ?? ''), 'pending_'), 'POST valid tanpa alias: short_code placeholder pending_');

// 5f. Alias tidak sah (mengandung spasi/simbol) -> error, tidak tersimpan.
$html = simulate(['url' => 'https://example.com/fase2-alias-jelek', 'alias' => 'my alias!']);
check(str_contains($html, 'huruf dan angka'), 'POST alias tidak sah: pesan error tampil');
check(row_count($pdo) === $jumlahAwal + 2, 'POST alias tidak sah: tidak ada baris baru di database');

// 5g. Skema selain http/https -> ditolak.
$html = simulate(['url' => 'phar://demo', 'alias' => '']);
check(str_contains($html, 'http:// atau https://'), 'POST skema phar:: pesan error skema tampil');
check(row_count($pdo) === $jumlahAwal + 2, 'POST skema phar:: tidak ada baris baru di database');

// 5h. Input array (?url[]=...) -> tidak crash, diperlakukan sebagai kosong.
$html = simulate(['url' => ['https://example.com'], 'alias' => '']);
check(str_contains($html, 'URL wajib diisi'), 'POST url berupa array: diperlakukan kosong + error jelas');
check(row_count($pdo) === $jumlahAwal + 2, 'POST url berupa array: tidak ada baris baru di database');

// 5i. Nilai input di-escape (anti-XSS) saat ditampilkan ulang.
$html = simulate(['url' => '"><script>alert(1)</script>', 'alias' => '']);
check(!str_contains($html, '<script>alert(1)'), 'POST input berbahaya: tidak ada script mentah di HTML');
check(str_contains($html, '&lt;script&gt;'), 'POST input berbahaya: output ter-escape dengan htmlspecialchars');

// ---------------------------------------------------------------
// 6. Bersihkan SEMUA data uji
// ---------------------------------------------------------------
$pdo->prepare('DELETE FROM links WHERE id > :base')->execute([':base' => $baseline]);
check(row_count($pdo) === (int) $pdo->query('SELECT COUNT(*) FROM links WHERE id <= ' . $baseline)->fetchColumn(), 'data uji berhasil dibersihkan');

// ---------------------------------------------------------------
// Ringkasan
// ---------------------------------------------------------------
echo PHP_EOL;
if ($fails === 0) {
    echo 'Semua pemeriksaan Fase 2 LULUS.' . PHP_EOL;
    exit(0);
}
echo "ADA {$fails} PEMERIKSAAN YANG GAGAL." . PHP_EOL;
exit(1);
