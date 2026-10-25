<?php

declare(strict_types=1);

function alias_exists(PDO $pdo, string $alias): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM links WHERE short_code = :code LIMIT 1');
    $stmt->execute([':code' => $alias]);

    return $stmt->fetch() !== false;
}

function generate_short_code(PDO $pdo, int $maxAttempts = 10): string
{
    for ($i = 0; $i < $maxAttempts; $i++) {
        $code = bin2hex(random_bytes(3));
        if (!alias_exists($pdo, $code)) {
            return $code;
        }
    }

    throw new RuntimeException('Gagal menghasilkan kode pendek unik. Coba lagi.');
}

function save_link(PDO $pdo, string $originalUrl, ?string $shortCode = null): int
{
    $shortCode ??= 'pending_' . bin2hex(random_bytes(4));

    $stmt = $pdo->prepare(
        'INSERT INTO links (original_url, short_code) VALUES (:url, :code)'
    );
    $stmt->execute([':url' => $originalUrl, ':code' => $shortCode]);

    return (int) $pdo->lastInsertId();
}
