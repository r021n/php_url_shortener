<?php

declare(strict_types=1);

/**
 * Verifikasi Fase 4 (alat bantu pengembang — TIDAK dibahas di panduan).
 *
 * Jalankan dari root proyek SETELAH Fase 4 diimplementasikan:
 *
 *   php tests/phase4_check.php
 *
 * Exit code 0 = semua lulus, 1 = ada yang gagal.
 *
 * Skrip ini menyalakan PHP Built-in Web Server pada port uji, lalu melakukan
 * request HTTP sungguhan sehingga status code (302/404), header Location,
 * isi body, dan kolom `clicks` diperiksa apa adanya seperti saat dipakai di
 * browser. Semua data uji dibersihkan di akhir sehingga aman dijalankan
 * berulang kali.
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

function clicks_of(PDO $pdo, string $code): ?int
{
    $row = row_by_code($pdo, $code);

    return $row === false ? null : (int) $row['clicks'];
}

function total_clicks(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COALESCE(SUM(clicks), 0) FROM links')->fetchColumn();
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
check(is_file($root . '/index.php'), 'index.php ada');
check(is_file($root . '/lib/link.php'), 'lib/link.php ada');
check(is_file($root . '/lib/redirect.php'), 'lib/redirect.php ada');

require_once $root . '/lib/validation.php';
require_once $root . '/lib/link.php';
require_once $root . '/lib/redirect.php';

foreach (['find_link_by_code', 'increment_clicks', 'redirect_to', 'show_not_found'] as $fn) {
    check(function_exists($fn), "fungsi {$fn}() terdefinisi");
}

// ------------------------------------------------------------------
// 2. find_link_by_code() & increment_clicks()
// ------------------------------------------------------------------
$kodeUji = 'fase4cek1';
insert_row($pdo, $kodeUji, 'https://example.com/target-fase4');

$baris = find_link_by_code($pdo, $kodeUji);
check(is_array($baris), 'find_link_by_code: menemukan kode yang ada');
check(is_array($baris) && (string) $baris['original_url'] === 'https://example.com/target-fase4', 'find_link_by_code: URL asli sesuai');
check(find_link_by_code($pdo, 'kode-ngawur-xyz') === null, 'find_link_by_code: kode tak dikenal -> null');

increment_clicks($pdo, $kodeUji);
check(clicks_of($pdo, $kodeUji) === 1, 'increment_clicks: 0 -> 1');
increment_clicks($pdo, $kodeUji);
check(clicks_of($pdo, $kodeUji) === 2, 'increment_clicks: 1 -> 2 (sekali akses = +1)');

$jumlahSebelum = row_count($pdo);
increment_clicks($pdo, 'kode-ngawur-xyz');
check(row_count($pdo) === $jumlahSebelum, 'increment_clicks: kode tak dikenal tidak membuat baris baru');

// ------------------------------------------------------------------
// 3. Server uji + request HTTP sungguhan
// ------------------------------------------------------------------
function find_free_port(): int
{
    for ($port = 8123; $port < 8143; $port++) {
        $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if (!is_resource($sock)) {
            return $port; // tidak ada yang menerima koneksi = port bebas
        }
        fclose($sock);
    }

    throw new RuntimeException('Tidak ada port bebas untuk server uji.');
}

function start_server(int $port, string $root)
{
    $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phase4_server.log';
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
 * Melakukan satu request HTTP tanpa mengikuti redirect.
 *
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

$port  = find_free_port();
$proc  = start_server($port, $root);
$base  = 'http://127.0.0.1:' . $port;

// 3a. Halaman utama tetap normal (regresi Fase 1-3).
$res = http_request($base, 'GET', '/');
check($res['status'] === 200, 'GET /: status 200');
check(str_contains($res['body'], 'URL Shortener'), 'GET /: halaman form tetap tampil');

// 3b. Kode valid -> redirect 302 ke URL asli, body kosong, clicks + 1.
$res = http_request($base, 'GET', '/?c=' . $kodeUji);
check($res['status'] === 302, 'GET /?c=kode valid: status 302');
check(($res['headers']['location'] ?? '') === 'https://example.com/target-fase4', 'GET /?c=kode valid: header Location = URL asli');
check(trim($res['body']) === '', 'GET /?c=kode valid: tidak ada HTML yang terkirim (exit bekerja)');
check(clicks_of($pdo, $kodeUji) === 3, 'GET /?c=kode valid: clicks 2 -> 3');

// 3c. Setiap akses menambah tepat 1.
http_request($base, 'GET', '/?c=' . $kodeUji);
http_request($base, 'GET', '/?c=' . $kodeUji);
check(clicks_of($pdo, $kodeUji) === 5, 'GET /?c=kode valid: 3 akses -> clicks 3 -> 5');
check(row_by_code($pdo, $kodeUji) !== false, 'GET /?c=kode valid: baris tetap ada (tidak terhapus)');

// 3d. Kode tidak dikenal -> 404 + pesan, tanpa mengubah database.
$totalSebelum = total_clicks($pdo);
$jumlahSebelum = row_count($pdo);
$res = http_request($base, 'GET', '/?c=kode-ngawur-xyz');
check($res['status'] === 404, 'GET /?c=kode tak dikenal: status 404');
check(str_contains($res['body'], 'Link tidak ditemukan'), 'GET /?c=kode tak dikenal: pesan "Link tidak ditemukan" tampil');
check(!isset($res['headers']['location']), 'GET /?c=kode tak dikenal: TIDAK mengirim header Location');
check(total_clicks($pdo) === $totalSebelum, 'GET /?c=kode tak dikenal: tidak ada klik yang tercatat');
check(row_count($pdo) === $jumlahSebelum, 'GET /?c=kode tak dikenal: tidak ada perubahan baris');

// 3e. Parameter kosong -> halaman utama seperti biasa (bukan 404).
$res = http_request($base, 'GET', '/?c=');
check($res['status'] === 200, 'GET /?c= (kosong): status 200');
check(str_contains($res['body'], 'URL Shortener'), 'GET /?c= (kosong): halaman form tampil');

// 3f. Payload SQL tidak membuat crash maupun perubahan data.
$totalSebelum = total_clicks($pdo);
$res = http_request($base, 'GET', '/?c=' . rawurlencode("' OR '1'='1"));
check($res['status'] === 404, 'GET /?c=payload SQL: status 404 (tidak crash)');
check(str_contains($res['body'], 'Link tidak ditemukan'), 'GET /?c=payload SQL: pesan 404 tampil');
check(total_clicks($pdo) === $totalSebelum, 'GET /?c=payload SQL: tidak ada baris yang tersentuh');

// 3g. Target berbahaya di database -> TIDAK di-redirect (aman dari header injection).
$kodeJahat = 'fase4jahat';
insert_row($pdo, $kodeJahat, 'javascript:alert(1)');
$res = http_request($base, 'GET', '/?c=' . $kodeJahat);
check($res['status'] === 404, 'GET /?c=target berbahaya: status 404');
check(!isset($res['headers']['location']), 'GET /?c=target berbahaya: TIDAK ada header Location');
check(clicks_of($pdo, $kodeJahat) === 0, 'GET /?c=target berbahaya: clicks tetap 0');

// 3h. Alur lengkap: POST form -> short link -> redirect -> clicks terhitung.
$res = http_request($base, 'POST', '/index.php', [
    'url'   => 'https://example.com/fase4-e2e',
    'alias' => 'fase4e2e',
]);
check($res['status'] === 200, 'POST form: status 200');
check(str_contains($res['body'], '?c=fase4e2e'), 'POST form: short link tampil');

$res = http_request($base, 'GET', '/?c=fase4e2e');
check($res['status'] === 302, 'POST form -> buka short link: status 302');
check(($res['headers']['location'] ?? '') === 'https://example.com/fase4-e2e', 'POST form -> buka short link: redirect ke URL yang diinput');
check(clicks_of($pdo, 'fase4e2e') === 1, 'POST form -> buka short link: clicks = 1');

// ------------------------------------------------------------------
// 4. Bersihkan SEMUA data uji + matikan server
// ------------------------------------------------------------------
stop_server($proc);

$pdo->prepare('DELETE FROM links WHERE id > :base')->execute([':base' => $baseline]);
check(row_count($pdo) === (int) $pdo->query('SELECT COUNT(*) FROM links WHERE id <= ' . $baseline)->fetchColumn(), 'data uji berhasil dibersihkan');

// ------------------------------------------------------------------
// Ringkasan
// ------------------------------------------------------------------
echo PHP_EOL;
if ($fails === 0) {
    echo 'Semua pemeriksaan Fase 4 LULUS.' . PHP_EOL;
    exit(0);
}
echo "ADA {$fails} PEMERIKSAAN YANG GAGAL." . PHP_EOL;
exit(1);
