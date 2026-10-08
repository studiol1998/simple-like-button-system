<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$failures = [];
$assert = static function (bool $condition, string $name) use (&$failures): void {
    if (!$condition) {
        $failures[] = $name;
    }
};

$validIdentifiers = [
    'sakura_01',
    'ＳＡＫＵＲＡ_０１',
    '桜並木_０１',
    '_桜並木_',
    '-桜並木-',
    '【艦これ】桜の季節（改訂版）_001',
    '『作品名』_本編',
    '作品名【全年齢版】',
    '短編集・春_2026',
    str_repeat('桜', 100),
];
foreach ($validIdentifiers as $identifier) {
    $assert(pollyanna_identifier_is_valid($identifier), 'valid identifier: ' . $identifier);
}

$invalidIdentifiers = [
    '',
    str_repeat('桜', 101),
    'sakura.01',
    'sakura/01',
    'sakura,01',
    'sakura#01',
    'sakura@01',
    'sakura=01',
    'sakura(01)',
    'sakura 01',
    'sakura　01',
    "sakura\n01",
    "sakura\x0001",
    "sakura\x0101",
];
foreach ($invalidIdentifiers as $identifier) {
    $assert(!pollyanna_identifier_is_valid($identifier), 'invalid identifier accepted: ' . json_encode($identifier));
}

$urlCases = [
    ['https://example.com/work/index.html?a=1#chapter2', 'https://example.com/work/index.html'],
    ['https://example.com/work/?a=1', 'https://example.com/work/'],
    ['https://example.com/index.html#top', 'https://example.com/index.html'],
    ['https://EXAMPLE.com/Path/Index.HTML', 'https://EXAMPLE.com/Path/Index.HTML'],
    ['https://example.com/作品/桜.html?表示=1#章', 'https://example.com/作品/桜.html'],
];
foreach ($urlCases as [$input, $expected]) {
    $assert(pollyanna_normalize_installation_url($input) === $expected, 'URL normalization: ' . $input);
}

$invalidUrls = ['ftp://example.com/file', 'https://user:pass@example.com/', 'https://example.com:99999/', 'https://example.com/a b', 'not a URL'];
foreach ($invalidUrls as $url) {
    try {
        pollyanna_normalize_installation_url($url);
        $failures[] = 'invalid URL accepted: ' . $url;
    } catch (InvalidArgumentException) {
    }
}

$stateSource = file_get_contents(dirname(__DIR__) . '/api/state.php');
$likeSource = file_get_contents(dirname(__DIR__) . '/api/like.php');
$assert(is_string($stateSource) && !str_contains($stateSource, 'pollyanna_register_identifier_url'), 'state.php registers an installation URL');
$assert(
    is_string($likeSource)
    && strpos($likeSource, 'recordWithinLimit') < strpos($likeSource, 'pollyanna_register_identifier_url'),
    'like.php does not register the URL after a successful Like'
);

if ($failures !== []) {
    fwrite(STDERR, "Phase 1 PHP tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "Phase 1 PHP tests passed.\n");
