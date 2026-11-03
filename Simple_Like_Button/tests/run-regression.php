<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/src/RuntimePolicy.php';

// Deliberate allowlist: never discover/execute the MySQL/HTTP installer tests here.
$suites = [
    'runtime-policy-tests.php' => [],
    'phase1-php-tests.php' => [],
    'phase2-origin-tests.php' => [],
    'admin-password-policy-tests.php' => [],
    'admin-security-tests.php' => ['session'],
    'runtime-compatibility-tests.php' => ['pdo_sqlite', 'session'],
    'aggregation-review-tests.php' => ['pdo_sqlite'],
];
$runtime = RuntimePolicy::current();
echo 'PHP ' . PHP_VERSION . ' (' . PHP_SAPI . '): ' . $runtime['status'] . "\n";
echo "Classification is informational; these tests do not certify Web PHP or MySQL.\n";
if (!function_exists('proc_open')) {
    fwrite(STDERR, "INCOMPLETE: proc_open is required for isolated test processes.\n");
    exit(2);
}
$passed = $failed = $skipped = 0;
// Keep parent/child base configuration aligned, including a deliberate -n run.
// Per-invocation -d overrides are not inherited; suite isolation settings follow.
$iniPath = php_ini_loaded_file();
$phpCommand = $iniPath === false ? [PHP_BINARY, '-n'] : [PHP_BINARY, '-c', $iniPath];
foreach ($suites as $suite => $extensions) {
    $missing = array_filter($extensions, static fn (string $name): bool => !extension_loaded($name));
    if ($missing !== []) {
        echo 'SKIP ' . $suite . ': missing ' . implode(', ', $missing) . "\n";
        $skipped++;
        continue;
    }
    $pipes = [];
    $process = proc_open(array_merge($phpCommand, [
        '-d', 'error_reporting=-1', '-d', 'display_errors=stderr',
        '-d', 'log_errors=0', '-d', 'session.auto_start=0', '-d', 'auto_append_file=',
        // Relative INI path avoids unquoted non-ASCII/space parsing in PHP -d.
        '-d', 'auto_prepend_file=regression-prelude.php', $suite,
    ]), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, __DIR__);
    if (!is_resource($process)) {
        echo 'FAIL ' . $suite . ": cannot start test process.\n";
        $failed++;
        continue;
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $code = proc_close($process);
    if ($code === 0) {
        // Do not forward child output, even on success: startup diagnostics may
        // contain local paths. Standalone suites still report assertion counts.
        echo 'PASS ' . $suite . "\n";
        $passed++;
    } else {
        // Keep test failures observable without dumping runtime paths/exception data.
        echo 'FAIL ' . $suite . ' (exit ' . $code . "). Run the isolated suite locally for diagnostics.\n";
        $failed++;
    }
}
echo "Suites: $passed passed, $failed failed, $skipped skipped.\n";
exit($failed > 0 ? 1 : ($skipped > 0 ? 2 : 0));
