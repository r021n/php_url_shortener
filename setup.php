<?php

declare(strict_types=1);

require __DIR__ . '/config/database.php';

$pdo = db();

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS links (
        id              INTEGER     PRIMARY KEY AUTOINCREMENT,
        original_url    TEXT        NOT NULL,
        short_code      TEXT        NOT NULL UNIQUE,
        clicks          INTEGER     DEFAULT 0,
        created_at      DATETIME    DEFAULT CURRENT_TIMESTAMP
    )'
);

echo 'Setup selesai: database.sqlite siap dan tabel links sudah dibuat.' . PHP_EOL;
