<?php

declare(strict_types=1);

require __DIR__ . '/config/database.php';

db();
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>URL Shortener</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-slate-100 min-h-screen">
    <main class="max-w-3xl mx-auto px-4 py-10">
        <h1 class="text-2xl font-bold text-slate-800">URL Shortener</h1>
        <p class="mt-2 text-slate-600">
            Koneksi database berhasil. Form input &amp; dashboard menyusul pada fase berikutnya.
        </p>
    </main>
</body>

</html>