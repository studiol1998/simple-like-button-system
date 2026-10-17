<?php
declare(strict_types=1);

if (!defined('POLLYANNA_AUTHENTICATED_WIDGET_TEST') || POLLYANNA_AUTHENTICATED_WIDGET_TEST !== true) {
    http_response_code(404);
    exit;
}

// Edit only this definition to change the target shared by both buttons and the example.
$testIdentifier = 'Pollyanna-test_01（ポリアンナテスト_01）';
$testEscape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '/admin/index.php';
$basePath = rtrim(str_replace('\\', '/', dirname(dirname($scriptPath))), '/.');
$example = '<link rel="stylesheet" href="' . $basePath . '/assets/like.css">' . "\n"
    . '<div data-pollyanna-like data-identifier="' . $testEscape($testIdentifier) . '"></div>' . "\n"
    . '<script defer src="' . $basePath . '/assets/like.js"></script>';
?>
<!doctype html>
<html lang="<?= $testEscape($language) ?>" data-theme="<?= $testEscape($theme) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Pollyanna — <?= $testEscape($ui['widget_test_title']) ?></title>
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="../assets/like.css">
</head>
<body>
<header><div class="brand-line"><span class="brand">Pollyanna</span></div><p><a href="./"><?= $testEscape($ui['widget_test_back']) ?></a></p></header>
<main>
    <h1><?= $testEscape($ui['widget_test_title']) ?></h1>
    <section class="panel settings-card">
        <p><?= $testEscape($ui['widget_test_intro']) ?></p>
        <div class="notice" role="note"><ul>
        <?php foreach ($ui['widget_test_warnings'] as $warning): ?><li><?= $testEscape($warning) ?></li><?php endforeach ?>
        </ul></div>
        <p><?= $testEscape($ui['widget_test_identifier']) ?>: <bdi dir="auto" style="overflow-wrap:anywhere"><?= $testEscape($testIdentifier) ?></bdi></p>
        <?php for ($button = 1; $button <= 2; $button++): ?>
        <div class="field"><h2><?= $testEscape(sprintf($ui['widget_test_button'], $button)) ?></h2><div data-pollyanna-like data-identifier="<?= $testEscape($testIdentifier) ?>"></div></div>
        <?php endfor ?>
        <p><?= $testEscape($ui['widget_test_unicode']) ?></p>
    </section>
    <section class="panel settings-card" style="margin-top:20px">
        <h2><?= $testEscape($ui['widget_test_example']) ?></h2>
        <p><?= $testEscape($ui['widget_test_paths']) ?></p>
        <pre style="white-space:pre-wrap;overflow-wrap:anywhere"><code><?= $testEscape($example) ?></code></pre>
    </section>
</main>
<script defer src="../assets/like.js"></script>
</body>
</html>
