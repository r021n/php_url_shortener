<?php

declare(strict_types=1);

function validate_url(string $url): ?string
{
    $url = trim($url);

    if ($url === '') {
        return 'URL wajib diisi.';
    }

    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return 'URL tidak valid. Contoh yang benar: https://example.com/artikel';
    }

    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if(!in_array($scheme, ['http', 'https'], true)) {
        return 'URL harus diawali dengan http:// atau https://';
    }

    return null;
}

function sanitize_alias(string $alias): string {
    return preg_replace('/[^A-Za-z0-9]/', '', trim($alias)) ?? '';
}

function validate_alias(string $alias): ?string {
    $alias = trim($alias);

    if($alias === '') {
        return null;
    }

    if(sanitize_alias($alias) !== $alias) {
        return 'Alias hanya boleh huruf dan angka (tanpa spasi atau simbol).';
    }

    if(strlen($alias) > 30) {
        return 'Alias maksimal 30 karakter.';
    }

    return null;
}