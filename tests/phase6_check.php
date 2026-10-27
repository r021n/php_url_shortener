<?php

declare(strict_types=1);

/**
 * Verifikasi Fase 6 (alat bantu pengembang — TIDAK dibahas di panduan).
 *
 * Jalankan dari root proyek SETELAH Fase 6 diimplementasikan:
 *
 *   php tests/phase6_check.php
 *
 * Exit code 0 = semua lulus, 1 = ada yang gagal.
 *
 * Skrip ini memeriksa fungsi delete_link(), keamanan method hapus.php,
 * validasi ID, penghapusan sungguhan lewat HTTP POST, redirect kembali ke
 * dashboard, serta keberadaan empty state. Semua data uji dibersihkan di akhir.
 */

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

// ------------------------------------------------------------------
// 0. Database & baseline pembersihan
// ------------------------------------------------------------------
require_once $root . '/config/database.php';

$pdo = db();

try {
    $pdo->query('SELECT COUNT(*) FROM links');
} catch (PDOException $e) {
    echo '[FAIL] tabel links belum ada. Jalankan dulu: php setup.php' . PHP_EOL;
    exit(1);
}

$baseline = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM links')->fetchColumn();

function row_by_code(PDO $pdo, string $code): array|false
{
    $stmt = $pdo->prepare('SELECT * FROM links WHERE short_code = :code LIMIT 1');
    $stmt->execute([':code' => $code]);
    $row = $stmt->fetch();
    $stmt->closeCursor();

    return $row;
}

function row_count(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM links')->fetchColumn();
}

function insert_row(PDO $pdo, string $code, string $url): int
{
    $stmt = $pdo->prepare('INSERT INTO links (original_url, short_code) VALUES (:url, :code)');
    $stmt->execute([':url' => $url, ':code' => $code]);

    return (int) $pdo->lastInsertId();
}

// ------------------------------------------------------------------
// 1. File & fungsi
// ------------------------------------------------------------------
check(is_file($root . '/hapus.php'), 'hapus.php ada');
check(is_file($root . '/lib/link.php'), 'lib/link.php ada');

require_once $root . '/lib/link.php';

check(function_exists('delete_link'), 'fungsi delete_link() terdefinisi');

// ------------------------------------------------------------------
// 2. delete_link(): perilaku di level database
// ------------------------------------------------------------------
$idHapus = insert_row($pdo, 'fase6hapus', 'https://example.com/fase6-hapus');
check(row_by_code($pdo, 'fase6hapus') !== false, 'delete_link: baris uji tersimpan');

check(delete_link($pdo, $idHapus) === true, 'delete_link: menghapus baris valid -> true');
check(row_by_code($pdo, 'fase6hapus') === false, 'delete_link: baris benar-benar hilang');
check(delete_link($pdo, 999999999) === false, 'delete_link: ID tidak ada -> false');

// ------------------------------------------------------------------
// 3. Server uji + request HTTP sungguhan
// ------------------------------------------------------------------
function find_free_port(): int
{
    for ($port = 8183; $port < 8203; $port++) {
        $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if (!is_resource($sock)) {
            return $port;
        }
        fclose($sock);
    }

    throw new RuntimeException('Tidak ada port bebas untuk server uji.');
}

function start_server(int $port, string $root)
{
    $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phase6_server.log';
    $descriptors = [
        0 => ['file', 'NUL', 'r'],
        1 => ['file', $log, 'a'],
        2 => ['file', $log, 'a'],
    ];

    $proc = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root],
        $descriptors,
        $pipes,
        $root
    );

    if (!is_resource($proc)) {
        throw new RuntimeException('Gagal menyalakan PHP Built-in Web Server.');
    }

    for ($i = 0; $i < 100; $i++) {
        $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if (is_resource($sock)) {
            fclose($sock);
            return $proc;
        }
        usleep(100_000);
    }

    throw new RuntimeException('Server uji tidak siap dalam 10 detik.');
}

function stop_server($proc): void
{
    $status = proc_get_status($proc);
    if ($status['running']) {
        if (PHP_OS_FAMILY === 'Windows') {
            exec('taskkill /F /T /PID ' . (int) $status['pid'] . ' 2>NUL');
        }
        proc_terminate($proc);
    }
    proc_close($proc);
}

/**
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function http_request(string $baseUrl, string $method, string $path, ?array $post = null): array
{
    $header  = "Accept: text/html\r\n";
    $content = '';

    if ($post !== null) {
        $header  .= "Content-Type: application/x-www-form-urlencoded\r\n";
        $content  = http_build_query($post);
    }

    $ctx = stream_context_create(['http' => [
        'method'          => $method,
        'header'          => $header,
        'content'         => $content,
        'ignore_errors'   => true,
        'follow_location' => 0,
        'timeout'         => 15,
    ]]);

    $body = file_get_contents($baseUrl . $path, false, $ctx);

    $status  = 0;
    $headers = [];
    foreach ($http_response_header ?? [] as $i => $line) {
        if ($i === 0 && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int) $m[1];
            continue;
        }
        $pos = strpos($line, ':');
        if ($pos !== false) {
            $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
        }
    }

    return ['status' => $status, 'headers' => $headers, 'body' => (string) $body];
}

$port = find_free_port();
$proc = start_server($port, $root);
$base = 'http://127.0.0.1:' . $port;

// 3a. Dashboard menampilkan form hapus per baris.
$idForm = insert_row($pdo, 'fase6form', 'https://example.com/fase6-form');
$res = http_request($base, 'GET', '/');
check($res['status'] === 200, 'GET /: status 200');
check(str_contains($res['body'], 'action="hapus.php"'), 'GET /: form action ke hapus.php');
check(str_contains($res['body'], 'name="id"'), 'GET /: ada input hidden id');
check(str_contains($res['body'], 'onsubmit="return confirm('), 'GET /: ada konfirmasi onsubmit');
check(str_contains($res['body'], '>Hapus</button>'), 'GET /: tombol Hapus tampil');
check(str_contains($res['body'], 'value="' . $idForm . '"'), 'GET /: id baris dikirim di form');

// 3b. GET ke hapus.php ditolak.
$res = http_request($base, 'GET', '/hapus.php');
check($res['status'] === 405, 'GET /hapus.php: status 405 (method tidak diizinkan)');
check(str_contains($res['body'], 'Metode tidak diizinkan'), 'GET /hapus.php: pesan metode tidak diizinkan');

// 3c. ID tidak valid ditolak tanpa mengubah data.
$jumlahSebelum = row_count($pdo);
$res = http_request($base, 'POST', '/hapus.php', []);
check($res['status'] === 400, 'POST /hapus.php tanpa id: status 400');

$res = http_request($base, 'POST', '/hapus.php', ['id' => 'abc']);
check($res['status'] === 400, 'POST /hapus.php id bukan angka: status 400');

$res = http_request($base, 'POST', '/hapus.php', ['id' => '0']);
check($res['status'] === 400, 'POST /hapus.php id < 1: status 400');

check(row_count($pdo) === $jumlahSebelum, 'POST /hapus.php id tidak valid: tidak ada baris terhapus');

// 3d. Penghapusan valid: redirect 303 ke index.php, baris hilang.
$idHapusHttp = insert_row($pdo, 'fase6http', 'https://example.com/fase6-http');
$res = http_request($base, 'POST', '/hapus.php', ['id' => $idHapusHttp]);
check($res['status'] === 303, 'POST /hapus.php valid: status 303');
check(($res['headers']['location'] ?? '') === 'index.php', 'POST /hapus.php valid: redirect ke index.php');
check(row_by_code($pdo, 'fase6http') === false, 'POST /hapus.php valid: baris terhapus dari database');

// 3e. Dashboard langsung ter-update setelah hapus.
$res = http_request($base, 'GET', '/');
check(!str_contains($res['body'], 'https://example.com/fase6-http'), 'GET / setelah hapus: link yang dihapus tidak tampil lagi');

// 3f. Empty state ada di template (tanpa menghapus data pengguna).
$template = (string) file_get_contents($root . '/index.php');
check(str_contains($template, 'Belum ada link'), 'index.php: empty state "Belum ada link" ada');
check(str_contains($template, 'colspan="5"'), 'index.php: colspan empty state sudah 5 kolom');

// 3g. Method POST valid tetap tidak menghapus ID yang sudah tidak ada (idempoten aman).
$jumlahSebelum = row_count($pdo);
$res = http_request($base, 'POST', '/hapus.php', ['id' => '999999999']);
check($res['status'] === 303, 'POST /hapus.php id tidak ada: tetap redirect 303');
check(row_count($pdo) === $jumlahSebelum, 'POST /hapus.php id tidak ada: tidak ada baris terhapus');

// ------------------------------------------------------------------
// 4. Bersihkan SEMUA data uji + matikan server
// ------------------------------------------------------------------
stop_server($proc);

$pdo->prepare('DELETE FROM links WHERE id > :base')->execute([':base' => $baseline]);
check(
    row_count($pdo) === (int) $pdo->query('SELECT COUNT(*) FROM links WHERE id <= ' . $baseline)->fetchColumn(),
    'data uji berhasil dibersihkan'
);

// ------------------------------------------------------------------
// Ringkasan
// ------------------------------------------------------------------
echo PHP_EOL;
if ($fails === 0) {
    echo 'Semua pemeriksaan Fase 6 LULUS.' . PHP_EOL;
    exit(0);
}
echo "ADA {$fails} PEMERIKSAAN YANG GAGAL." . PHP_EOL;
exit(1);
