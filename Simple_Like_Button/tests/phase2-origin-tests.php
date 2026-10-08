<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/OriginPolicy.php';

$count = 0;
$assert = static function (bool $condition, string $message) use (&$count): void {
    $count++;
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
};
$rejects = static function (string $value) use ($assert): void {
    try { PollyannaOrigin::parse($value); $assert(false, 'Rejected origin: ' . $value); }
    catch (InvalidArgumentException) { $assert(true, 'Rejected origin: ' . $value); }
};

$normalized = PollyannaOrigin::parse('HTTPS://Example.COM:443');
$assert($normalized->serialize() === 'https://example.com', 'scheme/host/default port normalization');
$assert(PollyannaOrigin::parse('https://example.com:8443')->serialize() === 'https://example.com:8443', 'non-default port retained');
$assert(PollyannaOrigin::parse('http://localhost:8080')->serialize() === 'http://localhost:8080', 'localhost accepted');
$assert(PollyannaOrigin::parse('http://[::1]:8080')->serialize() === 'http://[::1]:8080', 'IPv6 accepted');
foreach (['null', 'example.com', '//example.com', 'https://example.com/', 'https://example.com/path', 'https://example.com/?a=1', 'https://example.com/#x', 'https://user:pass@example.com', 'file://example.com'] as $invalid) $rejects($invalid);

$policy = new PollyannaOriginPolicy([
    'site_origin' => 'https://www.example.com',
    'site_base_domain' => 'example.com',
    'site_base_domain_include_subdomains' => true,
], [[
    'scheme' => 'https', 'host' => 'external.test', 'port' => 443, 'include_subdomains' => 1,
]]);
foreach (['https://www.example.com', 'https://example.com', 'https://a.b.example.com', 'https://external.test', 'https://sub.external.test'] as $allowed) {
    $assert($policy->allows(PollyannaOrigin::parse($allowed)), 'Allowed: ' . $allowed);
}
foreach (['http://example.com', 'https://fake-example.com', 'https://example.com.evil.test', 'https://external.test:8443'] as $denied) {
    $assert(!$policy->allows(PollyannaOrigin::parse($denied)), 'Denied: ' . $denied);
}
$assert($policy->allowsUrl('https://novel.example.com/work/1'), 'Allowed page URL origin');
$assert(!$policy->allowsUrl('https://evil.test/work/1'), 'Denied page URL origin');

foreach ([
    ['https://eraminan.jp', 'eraminan.jp'],
    ['https://www.eraminan.jp', 'eraminan.jp'],
    ['https://a.b.eraminan.jp', 'eraminan.jp'],
] as [$originValue, $baseDomain]) {
    $settings = PollyannaOrigin::normalizeSiteSettings($originValue, $baseDomain, true);
    $assert($settings['include_subdomains'] === true, 'Compatible site settings: ' . $originValue);
}
foreach ([
    ['https://www.eraminan.jp', 'example.com'],
    ['https://fakeeraminan.jp', 'eraminan.jp'],
    ['https://eraminan.jp.evil.test', 'eraminan.jp'],
] as [$originValue, $baseDomain]) {
    try {
        PollyannaOrigin::normalizeSiteSettings($originValue, $baseDomain, true);
        $assert(false, 'Incompatible site settings rejected: ' . $originValue);
    } catch (InvalidArgumentException) {
        $assert(true, 'Incompatible site settings rejected: ' . $originValue);
    }
}
foreach ([
    ['http://localhost', 'localhost'],
    ['http://127.0.0.1', '127.0.0.1'],
    ['http://[::1]', '::1'],
] as [$originValue, $baseDomain]) {
    $settings = PollyannaOrigin::normalizeSiteSettings($originValue, $baseDomain, true);
    $assert($settings['include_subdomains'] === false, 'Subdomains forced off: ' . $originValue);
    $settings = PollyannaOrigin::normalizeSiteSettings($originValue, $baseDomain, false);
    $assert($settings['origin'] instanceof PollyannaOrigin, 'Local/IP origin works with subdomains off: ' . $originValue);
}

echo "Phase 2 origin tests passed ({$count} assertions).\n";
