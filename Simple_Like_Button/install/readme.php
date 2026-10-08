<?php
declare(strict_types=1);

$language = is_string($_GET['language'] ?? null) ? $_GET['language'] : 'ja';
$readmeFiles = [
    'ja' => dirname(__DIR__) . '/README.md',
    'en' => dirname(__DIR__) . '/README.en.md',
];

if (!array_key_exists($language, $readmeFiles)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    echo 'README not found.';
    exit;
}

$contents = file_get_contents($readmeFiles[$language]);
if ($contents === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    echo 'Could not read README.';
    exit;
}

header('Content-Type: text/plain; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
echo $contents;
