<?php

declare(strict_types=1);

function find_link_by_code(PDO $pdo, string $code): ?array
{
    $stmt = $pdo->prepare('SELECT id, original_url, short_code, clicks FROM links WHERE short_code = :code LIMIT 1');
    $stmt->execute([':code' => $code]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function increment_clicks(PDO $pdo, string $code): void
{
    $stmt = $pdo->prepare('UPDATE links SET clicks = clicks + 1 WHERE short_code = :code');
    $stmt->execute([':code' => $code]);
}

function get_all_links(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT id, original_url, short_code, clicks, created_at
        FROM links
        ORDER BY created_at DESC, id DESC
        '
    );

    return $stmt->fetchAll();
}

function alias_exists(PDO $pdo, string $alias): bool
{
    return find_link_by_code($pdo, $alias) !== null;
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

function delete_link(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare('DELETE FROM links WHERE id = :id');
    $stmt->execute([':id' => $id]);

    return $stmt->rowCount() > 0;
}
