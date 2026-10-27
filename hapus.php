<?php

declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/lib/link.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'Metode tidak diizinkan. Gunakan form hapus dari dashboard';
    exit;
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    echo 'ID link tidak valid';
    exit;
}

delete_link(db(), $id);

header('Location: index.php', true, 303);
exit;
