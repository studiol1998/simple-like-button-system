<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/regression-prelude.php';
require_once dirname(__DIR__) . '/src/RequestSecurity.php';
require_once dirname(__DIR__) . '/src/AdminSession.php';

$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void {
    $checks++;
    if (!$ok) throw new RuntimeException($message);
};
$config = ['site_origin' => 'https://example.test', 'app_secret' => str_repeat('a', 64)];
$server = ['SCRIPT_NAME' => '/pollyanna/admin/index.php', 'REMOTE_ADDR' => '192.0.2.1',
    'HTTP_HOST' => 'example.test', 'HTTPS' => 'on', 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/pollyanna/admin/'];
$settings = RequestSecurity::settings($config, $server);
$assert($settings['environment'] === 'production' && $settings['admin_path'] === '/pollyanna/admin/', 'Safe beta defaults');
$assert(RequestSecurity::evaluate($settings, $server)['allowed'], 'Native HTTPS allowed');
$assert(RequestSecurity::evaluate($settings, array_replace($server, ['REQUEST_URI' => '/pollyanna/admin/index.php?tab=dashboard']))['allowed'], 'Explicit index and query use the same cookie path');
foreach (['/admin/', '/wrong/admin/', '/pollyanna/%61dmin/', '/pollyanna/admin/extra', '//evil.test/admin/'] as $uri) {
    $assert(RequestSecurity::evaluate($settings, array_replace($server, ['REQUEST_URI' => $uri]))['status'] === 400, 'Cookie path mismatch denied before session start');
}
$assert(RequestSecurity::evaluate($settings, array_replace($server, ['HTTPS' => 'off', 'REQUEST_METHOD' => 'POST']))['status'] === 403, 'HTTP POST rejected');
$redirect = RequestSecurity::evaluate($settings, array_replace($server, ['HTTPS' => 'off', 'REQUEST_URI' => '//evil.test/']));
$assert($redirect['location'] === 'https://example.test/pollyanna/admin/', 'HTTP GET has a fixed safe destination');
foreach (['evil.test', 'example.test.evil.test', 'example.test:444', 'example.test/path', "example.test\r\nX-Test: x"] as $host) {
    $assert(!RequestSecurity::evaluate($settings, array_replace($server, ['HTTP_HOST' => $host]))['allowed'], 'Bad Host denied');
}
$spoofed = array_replace($server, ['HTTPS' => 'off', 'REQUEST_METHOD' => 'POST', 'HTTP_X_FORWARDED_PROTO' => 'https']);
$assert(!RequestSecurity::evaluate($settings, $spoofed)['allowed'], 'Untrusted forwarding ignored');
$proxySettings = $settings;
$proxySettings['trusted_proxies'] = ['192.0.2.1'];
$assert(RequestSecurity::evaluate($proxySettings, $spoofed)['secure'], 'Explicit trusted proxy HTTPS');
foreach (['https,http', 'https, http', 'HTTPS', ' https', '', 'ftp'] as $proto) {
    $assert(!RequestSecurity::evaluate($proxySettings, array_replace($spoofed, ['HTTP_X_FORWARDED_PROTO' => $proto]))['allowed'], 'Ambiguous forwarding denied');
}
$assert(!RequestSecurity::evaluate($proxySettings, $server)['allowed'], 'Trusted proxy must supply designated header');
$assert(!RequestSecurity::evaluate($settings, array_replace($server, ['HTTPS' => 'garbage']))['allowed'], 'Unknown native HTTPS state denied');
$devConfig = array_replace($config, ['site_origin' => 'http://localhost:8080', 'admin_security' => ['environment' => 'development']]);
$devServer = array_replace($server, ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost:8080', 'HTTPS' => 'off', 'REQUEST_METHOD' => 'POST']);
$dev = RequestSecurity::settings($devConfig, $devServer);
$assert(RequestSecurity::evaluate($dev, $devServer)['allowed'] && !RequestSecurity::evaluate($dev, $devServer)['secure'], 'Explicit direct loopback HTTP development');
$assert(!RequestSecurity::evaluate($dev, array_replace($devServer, ['REMOTE_ADDR' => '192.0.2.1']))['allowed'], 'Development never allows LAN clients');
$assert(!RequestSecurity::evaluate($dev, $devServer + ['HTTP_X_FORWARDED_PROTO' => 'http'])['allowed'], 'Development proxy exception denied');
foreach ([['site_origin' => 'http://example.test'], ['admin_security' => ['trusted_proxies' => ['0.0.0.0/0']]],
    ['admin_security' => ['environment' => 'guess']], ['admin_security' => ['instance_id' => '../bad']],
    ['admin_security' => ['admin_path' => '/x/%2e%2e/admin/']], ['admin_security' => ['admin_path' => '/x/%0d/admin/']]] as $bad) {
    $rejected = false;
    try { RequestSecurity::settings(array_replace($config, $bad), $server); } catch (Throwable) { $rejected = true; }
    $assert($rejected, 'Uncertain configuration fails closed');
}
foreach (['https://example.test/path', 'https://user:pass@example.test', '//evil.test', "https://example.test\r\nLocation: https://evil.test", 'https://'] as $origin) {
    $rejected = false;
    try { RequestSecurity::settings(array_replace($config, ['admin_security' => ['origin' => $origin]]), $server); }
    catch (Throwable) { $rejected = true; }
    $assert($rejected, 'Invalid redirect origin cannot become a Location header');
}
$unicode = RequestSecurity::settings($config, array_replace($server, ['SCRIPT_NAME' => '/作品.v1/admin/index.php']));
$assert($unicode['admin_path'] === '/%E4%BD%9C%E5%93%81.v1/admin/', 'Existing Unicode/dotted install path is encoded safely');

$hash = password_hash('regression-only-password', PASSWORD_BCRYPT);
$data = ['pollyanna_admin_authenticated' => true, 'pollyanna_admin_credential_stamp' => hash('sha256', $hash),
    'pollyanna_authenticated_at' => 1000, 'pollyanna_last_activity_at' => 1000];
$assert(AdminSession::valid($data, $hash, 2799), 'Idle just before boundary');
$assert(!AdminSession::valid($data, $hash, 2800), 'Idle expires at 30 minutes');
$data['pollyanna_last_activity_at'] = 29000;
$assert(AdminSession::valid($data, $hash, 29799), 'Absolute just before boundary');
$assert(!AdminSession::valid($data, $hash, 29800), 'Absolute expires at eight hours');
$assert(!AdminSession::valid($data, $hash, 999), 'Clock rollback fails closed');
$assert(!AdminSession::valid($data, 'changed-hash', 29001), 'Password change invalidates other sessions');
unset($data['pollyanna_authenticated_at']);
$assert(!AdminSession::valid($data, $hash, 29001), 'Old or incomplete authenticated state rejected');

final class AdminSecurityMemorySession implements AdminSessionStore
{
    public array $data = [];
    public bool $failWrite = false;
    public bool $failRead = false;
    public bool $failClose = false;
    public bool $failure = false;
    public int $writes = 0;
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { if ($this->failClose) { $this->failure = true; return false; } return true; }
    public function failed(): bool { return $this->failure; }
    public function read(string $id): string|false { if ($this->failRead) { $this->failure = true; return false; } return $this->data[$id] ?? ''; }
    public function write(string $id, string $data): bool { $this->writes++; if ($this->failWrite) { $this->failure = true; return false; } $this->data[$id] = $data; return true; }
    public function destroy(string $id): bool { unset($this->data[$id]); return true; }
    public function gc(int $max_lifetime): int|false { return 0; }
    public function validateId(string $id): bool { return isset($this->data[$id]); }
    public function updateTimestamp(string $id, string $data): bool { return $this->write($id, $data); }
}
$handler = new AdminSecurityMemorySession();
session_set_save_handler($handler, true);
$_GET = $_POST = [];
session_id('untrusted-fixed-id');
$session = new AdminSession($handler);
$session->start($settings, true);
$assert($handler->writes === 0, 'Starting a session does not force a write or readback');
$assert(session_id() !== 'untrusted-fixed-id', 'Session fixation rejected');
$assert(session_name() === 'pollyanna_admin_' . $settings['instance_id'] && session_name() !== 'pollyanna_installer', 'Per-installation admin name');
$cookie = session_get_cookie_params();
$assert($cookie['secure'] && $cookie['httponly'] && $cookie['samesite'] === 'Strict' && $cookie['path'] === '/pollyanna/admin/' && $cookie['domain'] === '', 'Cookie attributes');
$before = session_id();
$session->authenticate($hash, 1000);
$assert(session_id() !== $before && !isset($handler->data[$before]), 'Login rotates and destroys old session');
$csrf = $_SESSION['pollyanna_csrf_token'];
$assert(is_string($csrf) && strlen($csrf) === 64, 'CSRF generated at authentication');
$writesBeforeValidation = $handler->writes;
$assert($session->validate($hash, 1100) && $_SESSION['pollyanna_last_activity_at'] === 1100, 'Activity updated for final persistence');
$assert($handler->writes === $writesBeforeValidation && session_status() === PHP_SESSION_ACTIVE, 'Authorization keeps the session lock without another write/read cycle');
$assert(!$session->validate($hash, 2900) && $_SESSION['pollyanna_csrf_token'] !== $csrf, 'Expired auth clears state and rotates CSRF');
$session->authenticate($hash, 3000);
$id = session_id();
$session->logout();
$session->finish();
$assert(!isset($handler->data[$id]) && session_status() === PHP_SESSION_NONE, 'Logout destroys server state');
session_id($id);
$fresh = new AdminSession($handler);
$fresh->start($settings, true);
$assert(session_id() !== $id && !$fresh->validate($hash, 3001), 'Logged-out ID cannot restore authentication');
$fresh->authenticate($hash, 3001);
$fresh->finish();
$foreignId = session_id();
$foreignState = $handler->data[$foreignId];
$otherSettings = array_replace($settings, ['instance_id' => str_repeat('b', 32)]);
$other = new AdminSession($handler);
$other->start($otherSettings, true);
$assert(session_id() !== $foreignId && !$other->validate($hash, 3002), 'Other installation session cannot grant authentication');
$assert($handler->data[$foreignId] === $foreignState, 'Foreign session is preserved without rewriting it');
$other->finish();
$handler->failWrite = true;
$failed = false;
try { $badWrite = new AdminSession($handler); $badWrite->start($settings, true); $badWrite->finish(); } catch (RuntimeException) { $failed = true; }
$assert($failed && session_status() !== PHP_SESSION_ACTIVE, 'Write failure is not a successful session');
$handler->failWrite = false;
$handler->failure = false;
$finalSave = new AdminSession($handler);
$finalSave->start($settings, true);
$finalSave->authenticate($hash, 3100);
$handler->failWrite = true;
$failed = false;
try { $finalSave->finish(); } catch (RuntimeException) { $failed = true; }
$assert($failed && session_status() !== PHP_SESSION_ACTIVE, 'Final save failure cannot be reported as success');
$handler->failWrite = false;
$handler->failure = false;
$handler->failRead = true;
$failed = false;
try { (new AdminSession($handler))->start($settings, true); } catch (RuntimeException) { $failed = true; }
$assert($failed, 'Read failure is refused before authorization');
$handler->failRead = false;
$handler->failure = false;
$closeFailure = new AdminSession($handler);
$closeFailure->start($settings, true);
$handler->failClose = true;
$failed = false;
try { $closeFailure->finish(); } catch (RuntimeException) { $failed = true; }
$assert($failed, 'Close callback failure is not reported as successful persistence');
$handler->failClose = false;
$handler->failure = false;
$corruptId = 'corrupt-regression-session';
$handler->data[$corruptId] = '!invalid-session-encoding';
session_id($corruptId);
$failed = false;
try { (new AdminSession($handler))->start($settings, true); } catch (RuntimeException) { $failed = true; }
$assert($failed, 'Corrupt encoded session cannot authenticate');
$handler->failure = false;
$_GET['pollyanna_admin_' . $settings['instance_id']] = 'injected-id';
$failed = false;
try { (new AdminSession($handler))->start($settings, true); } catch (RuntimeException) { $failed = true; }
$assert($failed && session_status() === PHP_SESSION_NONE, 'URL session ID is refused before session start');
$_GET = [];
$failed = false;
try { (new AdminSession())->start($settings, true); } catch (RuntimeException) { $failed = true; }
$assert($failed && session_status() === PHP_SESSION_NONE, 'An unverified user backend is refused by default');
echo "Admin security tests passed ($checks assertions; pure transport and in-memory sessions).\n";
