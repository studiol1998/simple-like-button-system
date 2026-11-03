<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/src/RuntimePolicy.php';

$checks = 0;
$assert = static function (bool $ok, string $label) use (&$checks): void {
    $checks++;
    if (!$ok) throw new RuntimeException($label);
};
foreach ([
    ['8.3.35', RuntimePolicy::UNSUPPORTED, 'below_upstream_minimum'],
    ['8.3.36', RuntimePolicy::SUPPORTED, 'upstream_minimum_met'],
    ['8.4.26', RuntimePolicy::UNSUPPORTED, 'below_upstream_minimum'],
    ['8.4.27', RuntimePolicy::SUPPORTED, 'upstream_minimum_met'],
    ['8.5.11', RuntimePolicy::UNSUPPORTED, 'below_upstream_minimum'],
    ['8.5.12', RuntimePolicy::SUPPORTED, 'upstream_minimum_met'],
    ['8.3.9', RuntimePolicy::UNSUPPORTED, 'below_upstream_minimum'],
    ['8.3.100', RuntimePolicy::SUPPORTED, 'upstream_minimum_met'],
    ['8.4.100', RuntimePolicy::SUPPORTED, 'upstream_minimum_met'],
    ['8.5.100', RuntimePolicy::SUPPORTED, 'upstream_minimum_met'],
    ['8.1.99', RuntimePolicy::UNSUPPORTED, 'unsupported_branch'],
    ['8.1.0', RuntimePolicy::UNSUPPORTED, 'unsupported_branch'],
    ['8.2.0', RuntimePolicy::UNSUPPORTED, 'unsupported_branch'],
    ['8.2.35', RuntimePolicy::UNSUPPORTED, 'unsupported_branch'],
    ['8.2.99-1ubuntu1', RuntimePolicy::UNSUPPORTED, 'unsupported_branch'],
    ['8.6.0', RuntimePolicy::UNSUPPORTED, 'unsupported_branch'],
    ['9.0.0', RuntimePolicy::UNSUPPORTED, 'unsupported_branch'],
    ['8.3.6-0ubuntu0.24.04.13', RuntimePolicy::BACKPORT_VERIFICATION_REQUIRED, 'vendor_backport_unverified'],
    ['8.4.26-1.el9', RuntimePolicy::BACKPORT_VERIFICATION_REQUIRED, 'vendor_backport_unverified'],
    ['8.5.11-1', RuntimePolicy::BACKPORT_VERIFICATION_REQUIRED, 'vendor_backport_unverified'],
    ['8.5.12-1', RuntimePolicy::SUPPORTED, 'upstream_minimum_met'],
    ['8.3.36-1ubuntu1', RuntimePolicy::SUPPORTED, 'upstream_minimum_met'],
] as [$version, $status, $reason]) {
    $result = RuntimePolicy::evaluate($version, 'cli');
    $assert($result['status'] === $status, 'Version classification: ' . $version);
    $assert($result['reason_code'] === $reason, 'Reason classification: ' . $version);
    $assert($result['meets_upstream_minimum'] === ($status === RuntimePolicy::SUPPORTED), 'No backport bypass: ' . $version);
}
foreach (['', '8.3', '8.3.36.1', '08.3.36', '8.03.36', '8.3.036', '8.3.-1', '8.3.36RC1',
    '8.5.12-dev', '8.5.12beta1', '8.5.12-1RC1', '8.5.12-1-dev', '8.5.12-custom', '8.5.12+unknown', "8.5.12\n", ' 8.5.12',
    "8.5.12\0", 'PHP 8.5.12', str_repeat('9', 200), 'secret=<unsafe>'] as $version) {
    $result = RuntimePolicy::evaluate($version, 'cli');
    $assert($result['status'] === RuntimePolicy::UNSUPPORTED && $result['reason_code'] === 'invalid_version', 'Invalid version fails closed');
    $assert($result['version'] === null && !str_contains($result['reason'], $version === '' ? '<empty>' : $version), 'Invalid input is not echoed');
}
foreach (['cli' => 'cli', 'phpdbg' => 'cli', 'cli-server' => 'web', 'fpm-fcgi' => 'web',
    'apache2handler' => 'web', 'cgi-fcgi' => 'web', 'litespeed' => 'web', 'frankenphp' => 'web', 'unknown' => 'unknown'] as $sapi => $context) {
    $result = RuntimePolicy::evaluate('8.5.12', $sapi);
    $assert($result['execution_context'] === $context, 'SAPI context: ' . $sapi);
    $assert($result['status'] === RuntimePolicy::SUPPORTED, 'SAPI is not proof of a fix');
    $assert(RuntimePolicy::evaluate('8.3.35', $sapi)['status'] === RuntimePolicy::UNSUPPORTED, 'SAPI never bypasses the floor');
}
$assert(RuntimePolicy::current() === RuntimePolicy::evaluate(PHP_VERSION, PHP_SAPI), 'Current process detection');
$assert(RuntimePolicy::evaluate('8.3.35', 'cli')['minimum_fixed_version'] === '8.3.36', 'Branch floor exposed');
echo "Runtime policy tests passed ($checks assertions).\n";
