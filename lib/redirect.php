<?php

declare(strict_types=1);

function redirect_to(string $url): never
{
    header('Location: ' . $url, true, 302);
    exit;
}

function show_not_found(): never
{
    http_response_code(404);
?>
    <!DOCTYPE html>
    <html lang="id">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Link tidak ditemukan</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>

    <body class="bg-slate-100 min-h-screen">
        <main class="max-w-3xl mx-auto px-4 py-10">
            <div class="rounded-lg border border-red-300 bg-red-50 p-6">
                <h1 class="text-2xl font-bold text-red-800">Link tidak ditemukan</h1>
                <p class="mt-2 text-red-700">
                    Kode pendek yang Anda buka tidak tersedia di database.
                </p>
                <a href="index.php" class="mt-6 inline-block rounded-md bg-sky-600 px-4 py-2 font-semibold text-white hover:bg-sky-700">
                    Kembali ke halaman utama
                </a>
            </div>
        </main>

    </body>

    </html>
<?php
    exit;
}
