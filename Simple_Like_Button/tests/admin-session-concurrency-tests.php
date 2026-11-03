<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/regression-prelude.php';
require_once dirname(__DIR__) . '/src/AdminSession.php';
$settings = ['instance_id' => str_repeat('c', 32), 'admin_path' => '/admin/'];
$cases = ['get', 'settings', 'logout', 'expired', 'csrf-change', 'password-after', 'password-before'];
if (($argv[1] ?? '') === '--worker') {
    [$root, $id, $role, $case] = array_slice($argv, 2) + ['', '', '', ''];
    $temp = realpath(sys_get_temp_dir());
    if (realpath($root) !== $root || is_link($root) || !str_starts_with($root, $temp . DIRECTORY_SEPARATOR)
        || preg_match('/\Apollyanna-session-review-[a-f0-9]{16}\z/D', basename($root)) !== 1
        || preg_match('/\A[A-Za-z0-9,-]{16,128}\z/D', $id) !== 1
        || !in_array($role, ['A', 'B', 'C'], true) || !in_array($case, $cases, true)) throw new RuntimeException('Invalid fixture.');
    require_once $root . '/src/bootstrap.php';
    session_save_path($root . '/sessions'); session_id($id);
    $session = new AdminSession(); $session->start($settings, false);
    $preauthorized = false;
    if ($case === 'password-before' && $role === 'A') {
        $preauthorized = $session->validate(pollyanna_admin_password_hash());
        file_put_contents($root . '/a-ready', 'ready');
        $deadline = microtime(true) + 5;
        while (!is_file($root . '/changed') && microtime(true) < $deadline) usleep(10000);
        if (!is_file($root . '/changed')) throw new RuntimeException('Password barrier timed out.');
    } elseif ($role === 'A' && $case !== 'password-after') {
        file_put_contents($root . '/a-ready', 'ready');
        usleep(250000); // Other workers enter session_start while this lock is held.
    }
    if (!$preauthorized && !$session->validate(pollyanna_admin_password_hash())) {
        $session->finish(); echo 'DENY'; exit;
    }
    $posted = json_decode(file_get_contents($root . '/posted-csrf'), true, 4, JSON_THROW_ON_ERROR)[$id] ?? '';
    if (!is_string($_SESSION['pollyanna_csrf_token'] ?? null) || !hash_equals($_SESSION['pollyanna_csrf_token'], $posted)) {
        $session->finish(); echo 'DENY-CSRF'; exit;
    }
    if (($case === 'password-after' && $role === 'A') || ($case === 'password-before' && $role === 'B')) {
        pollyanna_save_admin_password('fixture-new-password', 'fixture-old-password', pollyanna_admin_password_hash());
        $_SESSION = []; $session->rotate();
        file_put_contents($root . '/changed', 'changed');
        if ($role === 'A') { file_put_contents($root . '/a-ready', 'ready'); usleep(250000); }
        $session->finish(); echo 'CHANGED'; exit;
    }
    if ($case === 'logout' && $role === 'A') { $session->logout(); $session->finish(); echo 'LOGOUT'; exit; }
    if ($case === 'csrf-change' && $role === 'A') $_SESSION['pollyanna_csrf_token'] = bin2hex(random_bytes(32));
    if ($case === 'settings') {
        if ($role === 'A') pollyanna_save_admin_theme('rose');
        else pollyanna_save_admin_language('en');
    }
    $_SESSION['review_' . $role] = true;
    $_SESSION['review_count'] = (int) ($_SESSION['review_count'] ?? 0) + 1;
    $session->finish(); echo 'OK'; exit;
}
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pollyanna-session-review-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700)) throw new RuntimeException('Cannot allocate concurrency fixture.');
$root = realpath($root); $workers = []; $checks = 0;
$assert = static function (bool $ok, string $label) use (&$checks): void {
    $checks++; if (!$ok) throw new RuntimeException($label);
};
$remove = static function (string $path) use (&$remove, $root): void {
    if ($path !== $root && !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) throw new RuntimeException('Unsafe cleanup.');
    if (is_dir($path) && !is_link($path)) {
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $name) $remove($path . DIRECTORY_SEPARATOR . $name);
        rmdir($path);
    } else unlink($path);
};
try {
    foreach (['sessions', 'src', 'config', 'lang'] as $directory) mkdir($root . '/' . $directory, 0700);
    foreach (['bootstrap.php', 'AdminPasswordPolicy.php'] as $name) copy(dirname(__DIR__) . '/src/' . $name, $root . '/src/' . $name);
    foreach (['admin.ja.json', 'admin.en.json'] as $name) copy(dirname(__DIR__) . '/lang/' . $name, $root . '/lang/' . $name);
    file_put_contents($root . '/config/config.php', '<?php return ' . var_export([
        'database' => ['dsn' => 'sqlite::memory:', 'username' => '', 'password' => ''],
        'app_secret' => bin2hex(random_bytes(32)), 'site_origin' => 'https://example.test',
    ], true) . ';');
    $oldHash = password_hash('fixture-old-password', PASSWORD_BCRYPT);
    $ini = php_ini_loaded_file(); $php = $ini === false ? [PHP_BINARY, '-n'] : [PHP_BINARY, '-c', $ini];
    $seed = static function (bool $expired = false) use ($root, $settings, $oldHash): array {
        session_module_name('files'); session_save_path($root . '/sessions'); session_id('');
        $session = new AdminSession(); $session->start($settings, false); $session->authenticate($oldHash);
        $_SESSION['review_count'] = 0;
        if ($expired) {
            $_SESSION['pollyanna_authenticated_at'] = time() - 2000;
            $_SESSION['pollyanna_last_activity_at'] = time() - AdminSession::IDLE_SECONDS;
        }
        $id = session_id(); $csrf = $_SESSION['pollyanna_csrf_token']; $session->finish();
        return [$id, $csrf];
    };
    $spawn = static function (string $id, string $role, string $case) use ($root, $php, &$workers): void {
        $pipes = [];
        $process = proc_open(array_merge($php, ['-d', 'log_errors=0', '-d', 'display_errors=0', '-d', 'session.auto_start=0',
            '-d', 'auto_prepend_file=', '-d', 'auto_append_file=', __FILE__, '--worker', $root, $id, $role, $case]),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start worker.');
        fclose($pipes[0]); $workers[$role] = [$process, $pipes[1]];
    };
    foreach ($cases as $case) {
        foreach (['a-ready', 'changed', 'config/admin_settings.json'] as $name) if (is_file($root . '/' . $name)) unlink($root . '/' . $name);
        file_put_contents($root . '/config/admin_credentials.json', json_encode(['password_hash' => $oldHash], JSON_THROW_ON_ERROR));
        [$id, $csrf] = $seed($case === 'expired'); $otherId = $id; $otherCsrf = $csrf;
        if (str_starts_with($case, 'password-')) [$otherId, $otherCsrf] = $seed();
        file_put_contents($root . '/posted-csrf', json_encode([$id => $csrf, $otherId => $otherCsrf], JSON_THROW_ON_ERROR));
        $spawn($id, 'A', $case);
        $deadline = microtime(true) + 5;
        while (!is_file($root . '/a-ready') && microtime(true) < $deadline) usleep(10000);
        $assert(is_file($root . '/a-ready'), 'First worker acquired its lock');
        $spawn($otherId, 'B', $case);
        if ($case === 'get') $spawn($id, 'C', $case);
        $outcomes = [];
        foreach ($workers as $role => [$process, $pipe]) {
            $outcomes[$role] = stream_get_contents($pipe); fclose($pipe);
            $assert(proc_close($process) === 0, 'Worker must not fail with a storage/503 error');
            unset($workers[$role]);
        }
        $expected = match ($case) {
            'get' => ['A' => 'OK', 'B' => 'OK', 'C' => 'OK'], 'settings' => ['A' => 'OK', 'B' => 'OK'],
            'logout' => ['A' => 'LOGOUT', 'B' => 'DENY'], 'expired' => ['A' => 'DENY', 'B' => 'DENY'],
            'csrf-change' => ['A' => 'OK', 'B' => 'DENY-CSRF'], 'password-after' => ['A' => 'CHANGED', 'B' => 'DENY'],
            'password-before' => ['A' => 'OK', 'B' => 'CHANGED'],
        };
        $assert($outcomes === $expected, 'Only authorized operations may succeed');
        if ($case === 'settings') {
            $saved = json_decode(file_get_contents($root . '/config/admin_settings.json'), true, 8, JSON_THROW_ON_ERROR);
            $assert($saved['theme'] === 'rose' && $saved['language'] === 'en', 'Real setting helpers preserve both writes');
        }
        session_module_name('files'); session_id($id);
        $final = new AdminSession(); $final->start($settings, false);
        $hash = json_decode(file_get_contents($root . '/config/admin_credentials.json'), true, 4, JSON_THROW_ON_ERROR)['password_hash'];
        $assert($final->validate($hash) === in_array($case, ['get', 'settings', 'csrf-change'], true), 'Revoked authentication cannot return');
        if ($case === 'get' || $case === 'settings') $assert($_SESSION['review_count'] === ($case === 'get' ? 3 : 2), 'No session rollback');
        $final->logout(); $final->finish();
    }
    [$id] = $seed();
    file_put_contents($root . '/sessions/sess_' . $id, '!broken-session-data');
    session_module_name('files'); session_id($id); $rejected = false;
    try { (new AdminSession())->start($settings, false); } catch (RuntimeException) { $rejected = true; }
    $assert($rejected, 'Native decoder corruption must not authenticate');
    echo "Session concurrency tests passed ($checks assertions; 7 scenarios, no retry/503 accepted).\n";
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_abort();
    foreach ($workers as [$process, $pipe]) { proc_terminate($process); fclose($pipe); proc_close($process); }
    $remove($root);
}
