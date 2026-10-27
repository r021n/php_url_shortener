<?php

declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/lib/validation.php';
require __DIR__ . '/lib/link.php';
require __DIR__ . '/lib/redirect.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$pdo = db();

$kode = is_string($_GET['c'] ?? null) ? trim($_GET['c']) : '';

if ($kode !== '') {
    $link = find_link_by_code($pdo, $kode);
    $tujuan = $link === null ? '' : (string) $link['original_url'];

    if (validate_url($tujuan) !== null) {
        show_not_found();
    }

    increment_clicks($pdo, $kode);
    redirect_to($tujuan);
}

$errors = [];
$pesanSukses = '';
$shortUrl = '';
$urlInput = '';
$aliasInput = '';
$baseUrl = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost:8000');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $urlInput = is_string($_POST['url'] ?? null) ? trim($_POST['url']) : '';
    $aliasInput = is_string($_POST['alias'] ?? null) ? trim($_POST['alias']) : '';

    $error = validate_url($urlInput);
    if ($error !== null) {
        $errors[] = $error;
    }

    $error = validate_alias($aliasInput);
    if ($error !== null) {
        $errors[] = $error;
    }

    if ($errors === []) {
        $alias = sanitize_alias($aliasInput);

        if ($alias !== '') {
            if (alias_exists($pdo, $alias)) {
                $errors[] = 'Alias "' . $alias . '" sudah dipakai. Silakan pilih alias lain.';
            } else {
                try {
                    save_link($pdo, $urlInput, $alias);
                    $shortCode = $alias;
                } catch (PDOException $e) {
                    if (str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                        $errors[] = 'Alias "' . $alias . '" sudah dipakai. Silakan pilih alias lain';
                    } else {
                        throw $e;
                    }
                }
            }
        } else {
            try {
                $shortCode = generate_short_code($pdo);
                save_link($pdo, $urlInput, $shortCode);
            } catch (PDOException $e) {
                if (str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                    $errors[] = 'Terjadi tabrakan kode. Silakan soba lagi.';
                } else {
                    throw $e;
                }
            }
        }

        if ($errors === [] && isset($shortCode)) {
            $shortUrl = $baseUrl . '/?c=' . $shortCode;
            $pesanSukses = 'URL berhasil disimpan ke database';
            $urlInput = '';
            $aliasInput = '';
        }
    }
}

$links = get_all_links($pdo);
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
            Pendekkan URL panjang, lalu pantau jumlah kliknya.
        </p>

        <?php if ($errors !== []): ?>
            <div class="mt-6 rounded-lg border border-red-300 bg-red-50 p-4 text-red-800">
                <p class="font-semibold">Form belum bisa disimpan:</p>
                <ul class="mt-2 list-disc list-inside space-y-1">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo e($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($pesanSukses !== ''): ?>
            <div class="mt-6 rounded-lg border border-green-300 bg-screen-50 p-4">
                <p class="font-semibold text-green-800"><?php echo e($pesanSukses) ?></p>
                <?php if ($shortUrl !== ''): ?>
                    <p class="mt-2">
                        <a href="<?php echo e($shortUrl); ?>" class="font-mono text-sky-600 underline"><?php echo e($shortUrl); ?></a>
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="post" class="mt-6 rounded-lg bg-white p-6 shadow-md">
            <label for="url" class="block text-sm font-medium text-slate-700">URL panjang</label>
            <input type="text" name="url" id="url" required value="<?php echo e($urlInput); ?>" placeholder="https://example.com/artikel/panjang" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2  text-slate-900 focus:border-sky-500" />

            <label for="alias" class="mt-4 block text-sm font-medium text-slate-700">Custom alias (opsional)</label>
            <input type="text" name="alias" id="alias" value="<?php echo e($aliasInput); ?>" placeholder="contoh: promo2026" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-slate-900 focus:border-sky-500" />
            <p class="mt-1 text-sm text-slate-500">Hanya huruf dan angka, maksimal 30 karakter.</p>

            <button type="submit" class="mt-6 rounded-md bg-sky-600 px-4 py-2 font-semibold text-white hover:bg-sky-700">Simpan URL</button>
        </form>

        <section class="mt-8">
            <h2 class="text-xl font-bold text-slate-800">Riwayat Link</h2>
            <p class="mt-1 text-sm text-slate-500">Semua link yang pernah dibuat, yang terbaru di atas.</p>

            <div class="mt-4 overflow-x-auto rounded-lg bg-white shadow-md">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-4 py-3 font-semibold text-slate-700">URL Asli</th>
                            <th class="px-4 py-3 font-semibold text-slate-700">Short Link</th>
                            <th class="px-4 py-3 font-semibold text-slate-700">Waktu Pembuatan</th>
                            <th class="px-4 py-3 font-semibold text-slate-700">Klik</th>
                            <th class="px-4 py-3 font-semibold text-slate-700">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <?php if ($links === []): ?>
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-slate-500">Belum ada link.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($links as $link): ?>
                            <?php $shortUrl = $baseUrl . '/?c=' . $link['short_code']; ?>
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3 text-slate-700 break-all">
                                    <?php echo e((string) $link['original_url']); ?>
                                </td>
                                <td class="px-4 py-3">
                                    <a href="<?php echo e($shortUrl); ?>" class="font-mono text-sky-600 underline break-all"><?php echo e($shortUrl); ?></a>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-slate-600"><?php echo e((string) $link['created_at']); ?></td>
                                <td class="px-4 py-3"><?php echo (int) $link['clicks']; ?></td>
                                <td class="px-4 py-3 text-right">
                                    <form action="hapus.php" method="post" onsubmit="return confirm('Hapus link ini? Tindakan ini tidak bisa dibatalkan.');">
                                        <input type="hidden" name="id" value="<?php echo (int) $link['id']; ?>">
                                        <button type="submit" class="rounded-md bg-red-600 px-3 py-1 text-xs font-semibold text-white hover:bg-red-700">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>

</html>