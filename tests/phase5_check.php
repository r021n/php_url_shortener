<?php

declare(strict_types=1);

/**
 * Verifikasi Fase 5 (alat bantu pengembang — TIDAK dibahas di panduan).
 *
 * Jalankan dari root proyek SETELAH Fase 5 diimplementasikan:
 *
 *   php tests/phase5_check.php
 *
 * Exit code 0 = semua lulus, 1 = ada yang gagal.
 *
 * Skrip ini menyalakan PHP Built-in Web Server pada port uji, lalu memeriksa
 * dashboard lewat request HTTP sungguhan: urutan data, kelengkapan kolom,
 * escaping XSS, dan short link yang bisa diklik. Semua data uji dibersihkan
 * di akhir sehingga aman dijalankan berulang kali.
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

function row_count(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM links')->fetchColumn();
}

function insert_row(PDO $pdo, string $code, string $url, ?string $createdAt = null): int
{
    if ($createdAt === null) {
        $stmt = $pdo->prepare('INSERT INTO links (original_url, short_code) VALUES (:url, :code)');
        $stmt->execute([':url' => $url, ':code' => $code]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO links (original_url, short_code, created_at) VALUES (:url, :code, :created)'
        );
        $stmt->execute([':url' => $url, ':code' => $code, ':created' => $createdAt]);
    }

    return (int) $pdo->lastInsertId();
}

/** Mengambil potongan HTML satu baris <tr> yang memuat needle. */
function row_html(string $html, string $needle): string
{
    $pos = strpos($html, $needle);
    if ($pos === false) {
        return '';
    }

    $start = strrpos(substr($html, 0, $pos), '<tr');
    $end   = strpos($html, '</tr>', $pos);

    if ($start === false || $end === false) {
        return '';
    }

    return substr($html, $start, $end - $start + 5);
}

/** Mengambil teks <td> terakhir pada potongan baris. */
function last_td_text(string $rowHtml): string
{
    if (preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $rowHtml, $matches) === false) {
        return '';
    }

    $cells = $matches[1];
    if ($cells === []) {
        return '';
    }

    return trim(strip_tags((string) end($cells)));
}

// ------------------------------------------------------------------
// 1. File & fungsi
// ------------------------------------------------------------------
check(is_file($root . '/index.php'), 'index.php ada');
check(is_file($root . '/lib/link.php'), 'lib/link.php ada');

require_once $root . '/lib/validation.php';
require_once $root . '/lib/link.php';
require_once $root . '/lib/redirect.php';

check(function_exists('get_all_links'), 'fungsi get_all_links() terdefinisi');

// ------------------------------------------------------------------
// 2. get_all_links(): isi, jumlah, dan urutan
// ------------------------------------------------------------------
$kodeLama = 'fase5lama';
$kodeBaru = 'fase5baru';
insert_row($pdo, $kodeLama, 'https://example.com/fase5-lama', '2020-01-01 00:00:00');
insert_row($pdo, $kodeBaru, 'https://example.com/fase5-baru', '2020-01-02 00:00:00');

$semua = get_all_links($pdo);
check(is_array($semua), 'get_all_links: mengembalikan array');
check(count($semua) === row_count($pdo), 'get_all_links: jumlah baris sama dengan COUNT(*)');

$kode = array_column($semua, 'short_code');
$posLama = array_search($kodeLama, $kode, true);
$posBaru = array_search($kodeBaru, $kode, true);
check($posLama !== false && $posBaru !== false, 'get_all_links: baris uji ditemukan');
check(
    $posLama !== false && $posBaru !== false && $posBaru < $posLama,
    'get_all_links: created_at DESC (link terbaru di atas)'
);

// ------------------------------------------------------------------
// 3. Server uji + request HTTP sungguhan
// ------------------------------------------------------------------
function find_free_port(): int
{
    for ($port = 8143; $port < 8163; $port++) {
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
    $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phase5_server.log';
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

// 3a. Dashboard tampil di halaman utama.
$res = http_request($base, 'GET', '/');
check($res['status'] === 200, 'GET /: status 200');
check(str_contains($res['body'], 'URL Shortener'), 'GET /: judul halaman tetap ada');
check(str_contains($res['body'], 'Riwayat Link'), 'GET /: judul dashboard tampil');
check(str_contains($res['body'], 'URL Asli'), 'GET /: kolom URL Asli tampil');
check(str_contains($res['body'], 'Short Link'), 'GET /: kolom Short Link tampil');
check(str_contains($res['body'], 'Waktu Pembuatan'), 'GET /: kolom Waktu Pembuatan tampil');
check(str_contains($res['body'], 'Klik'), 'GET /: kolom Klik tampil');

// 3b. Semua link dari database tampil di tabel, terbaru di atas.
check(str_contains($res['body'], 'https://example.com/fase5-lama'), 'GET /: URL link lama tampil');
check(str_contains($res['body'], 'https://example.com/fase5-baru'), 'GET /: URL link baru tampil');

$posLamaHtml = strpos($res['body'], 'https://example.com/fase5-lama');
$posBaruHtml = strpos($res['body'], 'https://example.com/fase5-baru');
check(
    $posLamaHtml !== false && $posBaruHtml !== false && $posBaruHtml < $posLamaHtml,
    'GET /: urutan dashboard terbaru di atas'
);

// 3c. Short link di dashboard bisa diklik (href benar).
check(
    preg_match('/<a[^>]+href="[^"]*\?c=fase5baru"/', $res['body']) === 1,
    'GET /: short link punya href yang bisa diklik'
);
check(
    str_contains($res['body'], '?c=fase5baru'),
    'GET /: kode short link tampil di dashboard'
);

// 3d. Waktu pembuatan tampil.
check(str_contains($res['body'], '2020-01-02 00:00:00'), 'GET /: waktu pembuatan tampil');

// 3e. XSS: output berbahaya di-escape.
$kodeXss = 'fase5xss';
$urlXss  = 'https://example.com/"><script>alert(1)</script>';
insert_row($pdo, $kodeXss, $urlXss);

$res = http_request($base, 'GET', '/');
check(!str_contains($res['body'], '<script>alert(1)</script>'), 'GET /: tag script mentah TIDAK tampil (XSS dicegah)');
check(str_contains($res['body'], '&lt;script&gt;alert(1)&lt;/script&gt;'), 'GET /: output di-escape htmlspecialchars()');
check(str_contains($res['body'], '&quot;&gt;'), 'GET /: tanda kutip & gt juga di-escape');

// 3f. Short link yang diklik benar-benar redirect dan menambah klik.
$kodeKlik = 'fase5klik';
insert_row($pdo, $kodeKlik, 'https://example.com/fase5-target');

$res = http_request($base, 'GET', '/');
$row = row_html($res['body'], '?c=' . $kodeKlik);
check($row !== '', 'GET /: baris link yang akan diklik ditemukan');
check(last_td_text($row) === '0', 'GET /: klik awal tampil 0');

$res = http_request($base, 'GET', '/?c=' . $kodeKlik);
check($res['status'] === 302, 'GET /?c=kode: status 302');
check(($res['headers']['location'] ?? '') === 'https://example.com/fase5-target', 'GET /?c=kode: redirect ke URL asli');
check(clicks_of($pdo, $kodeKlik) === 1, 'GET /?c=kode: clicks bertambah menjadi 1');

$res = http_request($base, 'GET', '/');
$row = row_html($res['body'], '?c=' . $kodeKlik);
check(last_td_text($row) === '1', 'GET /: klik terbaru tampil 1 setelah redirect');

// 3g. Link baru dari form langsung muncul di dashboard.
$aliasBaru = 'fase5post';
$res = http_request($base, 'POST', '/index.php', [
    'url'   => 'https://example.com/fase5-post',
    'alias' => $aliasBaru,
]);
check($res['status'] === 200, 'POST form: status 200');
check(str_contains($res['body'], 'https://example.com/fase5-post'), 'POST form: URL baru langsung tampil di dashboard');
check(str_contains($res['body'], '?c=' . $aliasBaru), 'POST form: short link baru langsung tampil di dashboard');
check(row_by_code($pdo, $aliasBaru) !== false, 'POST form: baris baru tersimpan di database');

// 3h. Dashboard tidak mengubah data (read-only).
$jumlahSebelum = row_count($pdo);
$klikSebelum   = clicks_of($pdo, $kodeKlik);
http_request($base, 'GET', '/');
check(row_count($pdo) === $jumlahSebelum, 'GET /: dashboard tidak menambah/menghapus baris');
check(clicks_of($pdo, $kodeKlik) === $klikSebelum, 'GET /: dashboard tidak mengubah jumlah klik');

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
    echo 'Semua pemeriksaan Fase 5 LULUS.' . PHP_EOL;
    exit(0);
}
echo "ADA {$fails} PEMERIKSAAN YANG GAGAL." . PHP_EOL;
exit(1);
