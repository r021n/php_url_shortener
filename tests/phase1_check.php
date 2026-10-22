<?php

declare(strict_types=1);

/**
 * Verifikasi Fase 1 — jalankan dari terminal root proyek:
 *
 *   php tests/phase1_check.php
 *
 * Exit code 0 = semua lulus, 1 = ada yang gagal.
 * File ini aman dijalankan berulang kali (data uji dibersihkan di akhir).
 */

$root = dirname(__DIR__);
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

// ---------------------------------------------------------------
// 1. File & koneksi
// ---------------------------------------------------------------
check(is_file($root . '/config/database.php'), 'config/database.php ada');
check(is_file($root . '/database.sqlite'), 'database.sqlite ada (jalankan: php setup.php)');

require $root . '/config/database.php';

check(function_exists('db'), 'fungsi db() terdefinisi di config/database.php');

$pdo = db();
check($pdo instanceof PDO, 'db() mengembalikan instance PDO');
check($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite', 'driver PDO adalah sqlite');
check($pdo->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION, 'ERRMODE_EXCEPTION aktif');

// Panggil lagi — harus instance yang sama (koneksi tidak dibuat ulang).
check($pdo === db(), 'panggilan db() berikutnya memakai koneksi yang sama (static)');

// ---------------------------------------------------------------
// 2. Tabel links bisa di-query
// ---------------------------------------------------------------
$rows = $pdo->query('SELECT * FROM links')->fetchAll();
check(is_array($rows), 'SELECT * FROM links berhasil dieksekusi');

// ---------------------------------------------------------------
// 3. Struktur kolom sesuai brief
// ---------------------------------------------------------------
$expected = [
    // name => [type, notnull, default, pk]
    // Catatan: SQLite melaporkan notnull=0 untuk INTEGER PRIMARY KEY
    // karena kolom itu alias rowid (otomatis terisi, tidak bisa NULL).
    'id'           => ['INTEGER', 0, null, 1],
    'original_url' => ['TEXT',    1, null, 0],
    'short_code'   => ['TEXT',    1, null, 0],
    'clicks'       => ['INTEGER', 0, '0',  0],
    'created_at'   => ['DATETIME', 0, 'CURRENT_TIMESTAMP', 0],
];

$actual = [];
foreach ($pdo->query('PRAGMA table_info(links)') as $col) {
    $actual[$col['name']] = $col;
}

check(array_keys($actual) === array_keys($expected), 'urutan kolom tabel links sesuai brief');

foreach ($expected as $name => [$type, $notnull, $default, $pk]) {
    $col = $actual[$name] ?? null;
    if ($col === null) {
        check(false, "kolom {$name} ada");
        continue;
    }
    check(strtoupper((string) $col['type']) === $type, "kolom {$name} bertipe {$type}");
    check((int) $col['notnull'] === $notnull, "kolom {$name} NOT NULL = {$notnull}");
    check((int) $col['pk'] === $pk, "kolom {$name} PRIMARY KEY = {$pk}");
    check((string) $col['dflt_value'] === (string) $default, "kolom {$name} default = " . var_export($default, true));
}

// UNIQUE pada short_code
$indexes = $pdo->query('PRAGMA index_list(links)')->fetchAll();
$hasUnique = false;
foreach ($indexes as $idx) {
    if ((int) $idx['unique'] === 1) {
        foreach ($pdo->query('PRAGMA index_info(' . $idx['name'] . ')') as $idxCol) {
            if ($idxCol['name'] === 'short_code') {
                $hasUnique = true;
            }
        }
    }
}
check($hasUnique, 'short_code memiliki UNIQUE constraint');

// ---------------------------------------------------------------
// 4. Uji tulis-baca + nilai default (dibersihkan kembali)
// ---------------------------------------------------------------
$code = 't' . substr(bin2hex(random_bytes(4)), 0, 7);
$stmt = $pdo->prepare('INSERT INTO links (original_url, short_code) VALUES (:url, :code)');
$stmt->execute([':url' => 'https://example.com/phase1-test', ':code' => $code]);
$id = (int) $pdo->lastInsertId();
check($id > 0, 'INSERT berhasil dan lastInsertId() mengembalikan id');

$row = $pdo->query('SELECT * FROM links WHERE id = ' . $id)->fetch();
check($row !== false, 'baris yang di-insert bisa dibaca kembali');
check((int) $row['clicks'] === 0, 'nilai default clicks = 0');
check(!empty($row['created_at']), 'created_at terisi otomatis (CURRENT_TIMESTAMP)');

// UNIQUE benar-benar menolak short_code duplikat
$dupOk = false;
try {
    $stmt->execute([':url' => 'https://example.com/duplikat', ':code' => $code]);
} catch (PDOException $e) {
    $dupOk = true;
}
check($dupOk, 'INSERT short_code duplikat ditolak (UNIQUE bekerja)');

// Bersihkan data uji
$pdo->prepare('DELETE FROM links WHERE id = :id')->execute([':id' => $id]);
check(!$pdo->query('SELECT 1 FROM links WHERE id = ' . $id)->fetch(), 'data uji berhasil dibersihkan');

// ---------------------------------------------------------------
// Ringkasan
// ---------------------------------------------------------------
echo PHP_EOL;
if ($fails === 0) {
    echo 'Semua pemeriksaan Fase 1 LULUS.' . PHP_EOL;
    exit(0);
}
echo "ADA {$fails} PEMERIKSAAN YANG GAGAL." . PHP_EOL;
exit(1);
