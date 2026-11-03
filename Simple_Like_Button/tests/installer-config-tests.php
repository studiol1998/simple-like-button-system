<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/regression-prelude.php';
require_once dirname(__DIR__) . '/src/InstallerConfig.php';
$checks = 0;
$assert = static function (bool $ok, string $label) use (&$checks): void {
    $checks++; if (!$ok) throw new RuntimeException($label);
};
$server = ['SCRIPT_NAME' => '/Pollyanna/install/index.php', 'REMOTE_ADDR' => '127.0.0.1',
    'HTTP_HOST' => 'localhost', 'HTTPS' => 'off', 'REQUEST_METHOD' => 'POST'];
$path = InstallerConfig::adminPath($server);
$assert($path === '/Pollyanna/admin/', 'Case-preserving admin path');
$assert(InstallerConfig::adminPath(array_replace($server, ['SCRIPT_NAME' => '/install/index.php'])) === '/admin/', 'Document-root install');
$dev = InstallerConfig::adminSettings('development', 'http://localhost', $path, true, $server);
$assert($dev === ['environment' => 'development', 'origin' => 'http://localhost', 'admin_path' => '/Pollyanna/admin/', 'trusted_proxies' => []], 'Explicit loopback development output');
$production = InstallerConfig::adminSettings('production', 'https://example.com', $path, false, $server);
$assert($production['environment'] === 'production', 'Explicit HTTPS production origin');
foreach ([
    ['', 'http://localhost', $path, true, $server],
    ['production', 'http://localhost', $path, true, $server],
    ['development', 'http://localhost', $path, false, $server],
    ['development', 'http://localhost', $path, true, array_replace($server, ['REMOTE_ADDR' => '192.0.2.1'])],
    ['development', 'http://localhost', $path, true, $server + ['HTTP_X_FORWARDED_PROTO' => 'https']],
    ['development', 'http://localhost', $path, true, $server + ['HTTP_FORWARDED' => 'proto=http']],
    ['development', 'http://localhost', $path, true, array_replace($server, ['HTTP_HOST' => 'evil.test'])],
    ['development', 'http://example.com', $path, true, $server],
    ['production', 'https://user:pass@example.com', $path, false, $server],
    ['production', 'https://example.com/path', $path, false, $server],
    ['production', 'https://example.com', '/wrong/admin/', false, $server],
] as $arguments) {
    $rejected = false;
    try { InstallerConfig::adminSettings(...$arguments); } catch (Throwable) { $rejected = true; }
    $assert($rejected, 'Invalid or unconfirmed admin environment rejected');
}
$template = require dirname(__DIR__) . '/config/config.example.php';
$database = ['dsn' => $template['database']['dsn'], 'user' => $template['database']['username'], 'password' => $template['database']['password']];
$basic = ['site_origin' => $template['site_origin'], 'site_base_domain' => $template['site_base_domain'],
    'site_base_domain_include_subdomains' => $template['site_base_domain_include_subdomains'],
    'timezone' => $template['timezone'], 'admin_security' => $production, 'admin_development_confirmed' => false];
$generated = InstallerConfig::build($database, $basic, str_repeat('a', 64), $server);
// Concrete deployment values differ from optional template fallbacks; everything
// else must match recursively so future configuration additions cannot drift.
$template['app_secret'] = str_repeat('a', 64);
$template['admin_security']['origin'] = 'https://example.com';
$template['admin_security']['admin_path'] = $path;
$normalize = static function (array $value) use (&$normalize): array {
    foreach ($value as &$item) if (is_array($item)) $item = $normalize($item);
    unset($item); ksort($value); return $value;
};
$assert($normalize($generated) === $normalize($template), 'Recursive template/generated configuration parity');
$basic['admin_security'] = $dev;
$basic['admin_development_confirmed'] = true;
$assert(InstallerConfig::build($database, $basic, str_repeat('b', 64), $server)['admin_security'] === $dev, 'Development config generated');
$denied = false;
try { InstallerConfig::build($database, $basic, str_repeat('b', 64), array_replace($server, ['REMOTE_ADDR' => '192.0.2.1'])); }
catch (Throwable) { $denied = true; }
$assert($denied, 'Final generation rechecks actual connection');
unset($basic['admin_security']);
$denied = false;
try { InstallerConfig::build($database, $basic, str_repeat('b', 64), $server); } catch (Throwable) { $denied = true; }
$assert($denied, 'Old incomplete wizard session requires renewed confirmation');
echo "Installer config tests passed ($checks assertions; no files, sessions or DB writes).\n";
