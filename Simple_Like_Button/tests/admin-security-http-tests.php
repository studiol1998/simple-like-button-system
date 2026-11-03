<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/regression-prelude.php';

// Opt-in local integration only. All writable data lives in this owned temp tree.
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pollyanna-admin-a-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700)) throw new RuntimeException('Cannot allocate isolated HTTP fixture.');
$process = null;
$secondary = null;
$pipes = [];
$checks = 0;
$assert = static function (bool $ok, string $label) use (&$checks): void {
    $checks++;
    if (!$ok) throw new RuntimeException($label);
};
$remove = static function (string $path) use (&$remove, $root): void {
    if ($path !== $root && !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) throw new RuntimeException('Unsafe fixture cleanup.');
    if (is_dir($path) && !is_link($path)) {
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $name) $remove($path . DIRECTORY_SEPARATOR . $name);
        if (!rmdir($path)) throw new RuntimeException('Cannot remove fixture directory.');
    } elseif (!unlink($path)) throw new RuntimeException('Cannot remove fixture file.');
};
$copy = static function (string $source, string $target) use (&$copy): void {
    if (is_link($source)) throw new RuntimeException('Fixture sources must not be links.');
    if (is_dir($source)) {
        mkdir($target, 0700);
        foreach (array_diff(scandir($source) ?: [], ['.', '..']) as $name) $copy($source . '/' . $name, $target . '/' . $name);
    } elseif (!copy($source, $target)) throw new RuntimeException('Cannot copy fixture source.');
};
try {
    foreach (['admin', 'src', 'lang'] as $directory) $copy(dirname(__DIR__) . '/' . $directory, $root . '/' . $directory);
    mkdir($root . '/config', 0700);
    mkdir($root . '/sessions', 0700);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) throw new RuntimeException('Cannot reserve a loopback port.');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $base = 'http://' . $address;
    $password = bin2hex(random_bytes(16));
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $config = require dirname(__DIR__) . '/config/config.example.php';
    // Even unintended dashboard fallthrough can only open an in-memory SQLite DB.
    $config['database'] = ['dsn' => 'sqlite::memory:', 'username' => '', 'password' => ''];
    $config['app_secret'] = bin2hex(random_bytes(32));
    $config['site_origin'] = 'https://' . $address;
    $config['admin_security'] = ['environment' => 'production', 'trusted_proxies' => ['127.0.0.1']];
    $writeConfig = static function () use (&$config, $root): void {
        file_put_contents($root . '/config/config.php', '<?php return ' . var_export($config, true) . ';');
    };
    $writeConfig();
    file_put_contents($root . '/config/admin_credentials.json', json_encode(['password_hash' => $hash], JSON_THROW_ON_ERROR));
    $ini = php_ini_loaded_file();
    $command = $ini === false ? [PHP_BINARY, '-n'] : [PHP_BINARY, '-c', $ini];
    $command = array_merge($command, ['-d', 'session.save_path="' . str_replace('\\', '/', $root) . '/sessions"', '-d', 'session.auto_start=0',
        '-d', 'session.serialize_handler=php', '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'auto_prepend_file=', '-d', 'auto_append_file=',
        '-d', 'opcache.enable_cli=0', '-d', 'opcache.enable=0', '-S', $address, '-t', $root]);
    // Windows anonymous pipes cannot reliably be made nonblocking. Discard server
    // diagnostics at the OS null device; no fixture/production logs contain paths.
    $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $nullDevice, 'w'],
        2 => ['file', $nullDevice, 'w']], $pipes, $root);
    if (!is_resource($process)) throw new RuntimeException('Cannot start isolated HTTP server.');
    fclose($pipes[0]);
    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($probe !== false) { fclose($probe); $ready = true; break; }
        usleep(50000);
    }
    if (!$ready) throw new RuntimeException('Isolated server did not start.');
    $request = static function (string $path, string &$cookie, string $method = 'GET', array $body = [], array $extra = []) use ($base): array {
        $headers = ['Content-Type: application/x-www-form-urlencoded'];
        if ($cookie !== '') $headers[] = 'Cookie: ' . $cookie;
        $headers = array_merge($headers, $extra);
        $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers),
            'content' => $method === 'POST' ? http_build_query($body) : '', 'ignore_errors' => true,
            'follow_location' => 0, 'timeout' => 5]]);
        $stream = fopen($base . $path, 'rb', false, $context);
        if ($stream === false) throw new RuntimeException('Local HTTP request failed.');
        $meta = stream_get_meta_data($stream);
        $html = stream_get_contents($stream);
        fclose($stream);
        $responseHeaders = $meta['wrapper_data'] ?? [];
        $status = 0;
        foreach ($responseHeaders as $header) {
            if (preg_match('~^HTTP/\S+ ([0-9]+)~', $header, $match)) $status = (int) $match[1];
            if (preg_match('/^Set-Cookie: (pollyanna_admin_[a-f0-9]{32}=[^;]*)/i', $header, $match)) $cookie = $match[1];
        }
        preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $match);
        return [$status, $html, $match[1] ?? '', $responseHeaders];
    };
    $tls = ['X-Forwarded-Proto: https']; // Simulated trusted TLS terminator, not a TLS handshake.
    $cookie = '';
    [$status, , , $headers] = $request('/admin/', $cookie, 'GET', [], ['X-Forwarded-Proto: http']);
    $assert($status === 302 && in_array('Location: https://' . $address . '/admin/', $headers, true), 'HTTP GET uses configured HTTPS destination');
    $assert($cookie === '', 'HTTP refusal does not start an admin session');
    [$status] = $request('/admin/', $cookie, 'POST', ['action' => 'admin_login', 'password' => $password], ['X-Forwarded-Proto: http']);
    $assert($status === 403, 'Actual HTTP POST denied before login');
    [$status] = $request('/admin/', $cookie, 'GET', [], ['X-Forwarded-Proto: https,http']);
    $assert($status === 400, 'Ambiguous forwarding denied by actual entry point');
    [$status] = $request('/admin/', $cookie, 'GET', [], array_merge($tls, ['Host: evil.test']));
    $assert($status === 400, 'Bad Host denied by actual entry point');
    [$status, $html, $csrf, $headers] = $request('/admin/', $cookie, 'GET', [], $tls);
    $assert($status === 200 && $csrf !== '' && $cookie !== '', 'Actual login page and CSRF (status ' . $status . ')');
    $allHeaders = implode("\n", $headers);
    $assert(str_contains($allHeaders, 'secure') && str_contains(strtolower($allHeaders), 'httponly')
        && str_contains($allHeaders, 'SameSite=Strict') && str_contains($allHeaders, 'path=/admin/'), 'Actual Set-Cookie attributes');
    $oldCookie = $cookie;
    [$status] = $request('/admin/', $cookie, 'POST', ['action' => 'admin_login', 'password' => $password, 'csrf_token' => 'wrong'], $tls);
    $assert($status === 403, 'Login CSRF remains enforced');
    [$status] = $request('/admin/', $cookie, 'POST', ['action' => 'admin_login', 'password' => $password, 'csrf_token' => $csrf], $tls);
    $assert($status === 302 && $cookie !== $oldCookie, 'Login persists and rotates ID before redirect');
    [$status, $html] = $request('/admin/?view=widget-test', $cookie, 'GET', [], $tls);
    $assert($status === 200 && str_contains($html, 'data-pollyanna-like'), 'Existing authenticated widget-test view');
    $oldSession = $cookie;
    [$status, , $csrf] = $request('/admin/', $cookie, 'GET', [], $tls);
    $assert($status === 200 && $csrf !== '', 'Admin view still renders with isolated DB error');
    [$status] = $request('/admin/', $cookie, 'POST', ['action' => 'save_admin_theme', 'theme' => 'rose', 'csrf_token' => 'wrong'], $tls);
    $assert($status === 403 && !is_file($root . '/config/admin_settings.json'), 'Settings CSRF prevents writes');
    [$status] = $request('/admin/', $cookie, 'POST', ['action' => 'save_admin_theme', 'theme' => 'rose', 'csrf_token' => $csrf], $tls);
    $saved = json_decode((string) file_get_contents($root . '/config/admin_settings.json'), true, 4, JSON_THROW_ON_ERROR);
    $assert($status === 303 && $saved['theme'] === 'rose', 'Existing theme setting saves in fixture only');
    // Two real PHP HTTP servers share the fixture/session directory. Unlike one
    // single-threaded -S server, these can process the same SID concurrently.
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) throw new RuntimeException('Cannot reserve second loopback port.');
    $secondAddress = stream_socket_get_name($socket, false); fclose($socket);
    $secondCommand = $command;
    $secondCommand[array_search('-S', $secondCommand, true) + 1] = $secondAddress;
    $secondPipes = [];
    $secondary = proc_open($secondCommand, [0 => ['pipe', 'r'], 1 => ['file', $nullDevice, 'w'],
        2 => ['file', $nullDevice, 'w']], $secondPipes, $root);
    if (!is_resource($secondary)) throw new RuntimeException('Cannot start second HTTP server.');
    fclose($secondPipes[0]);
    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        $probe = @stream_socket_client('tcp://' . $secondAddress, $errno, $error, 0.1);
        if ($probe !== false) { fclose($probe); $ready = true; break; }
        usleep(50000);
    }
    $assert($ready, 'Second HTTP worker ready');
    $parallel = static function (array $bodies) use ($address, $secondAddress, &$cookie): array {
        $streams = [];
        foreach ([$address, $secondAddress] as $index => $target) {
            $stream = stream_socket_client('tcp://' . $target, $errno, $error, 5);
            if ($stream === false) throw new RuntimeException('Cannot open parallel request.');
            stream_set_timeout($stream, 5);
            $body = $bodies[$index];
            $method = $body === null ? 'GET' : 'POST';
            $encoded = $body === null ? '' : http_build_query($body);
            $path = $body === null ? '/admin/?view=widget-test' : '/admin/';
            fwrite($stream, $method . ' ' . $path . " HTTP/1.1\r\nHost: " . $address
                . "\r\nX-Forwarded-Proto: https\r\nCookie: " . $cookie
                . "\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($encoded)
                . "\r\nConnection: close\r\n\r\n" . $encoded);
            $streams[] = $stream;
        }
        $responses = [];
        foreach ($streams as $stream) {
            try { $responses[] = stream_get_contents($stream); } finally { fclose($stream); }
        }
        return $responses;
    };
    foreach ($parallel([null, null]) as $response) {
        $assert(preg_match('~\AHTTP/1\.[01] 200 ~', $response) === 1 && str_contains($response, 'data-pollyanna-like'), 'Concurrent real admin GET succeeds without 503');
    }
    foreach ($parallel([
        ['action' => 'save_admin_theme', 'theme' => 'green', 'csrf_token' => $csrf],
        ['action' => 'save_admin_language', 'language' => 'en', 'csrf_token' => $csrf],
    ]) as $response) {
        $assert(preg_match('~\AHTTP/1\.[01] 303 ~', $response) === 1, 'Concurrent real setting POST succeeds without 503');
    }
    $saved = json_decode(file_get_contents($root . '/config/admin_settings.json'), true, 4, JSON_THROW_ON_ERROR);
    $assert($saved['theme'] === 'green' && $saved['language'] === 'en', 'Concurrent actual admin settings preserve both values');
    [$status] = $request('/admin/', $cookie, 'POST', ['action' => 'admin_logout', 'csrf_token' => $csrf], $tls);
    $assert($status === 302 && !is_file($root . '/sessions/sess_' . explode('=', $oldSession, 2)[1]), 'Logout destroys actual session file');
    [$status, $html] = $request('/admin/?view=widget-test', $oldSession, 'GET', [], $tls);
    $assert($status === 200 && !str_contains($html, 'data-pollyanna-like'), 'Replayed logout ID cannot access authenticated view');
    $login = static function (string &$cookie) use ($request, $tls, $password, $assert): void {
        $cookie = '';
        [, , $token] = $request('/admin/', $cookie, 'GET', [], $tls);
        [$status] = $request('/admin/', $cookie, 'POST', ['action' => 'admin_login', 'password' => $password, 'csrf_token' => $token], $tls);
        $assert($status === 302, 'Fresh fixture login');
    };
    $expire = static function (string $cookie, int $started, int $last) use ($root): void {
        $id = explode('=', $cookie, 2)[1];
        if (preg_match('/\A[a-zA-Z0-9,-]+\z/D', $id) !== 1) throw new RuntimeException('Invalid fixture session ID.');
        $path = $root . '/sessions/sess_' . $id;
        $contents = file_get_contents($path);
        foreach (['pollyanna_authenticated_at' => $started, 'pollyanna_last_activity_at' => $last] as $key => $value) {
            $contents = preg_replace('/' . $key . '\|i:[0-9]+;/', $key . '|i:' . $value . ';', $contents, 1, $count);
            if ($count !== 1) throw new RuntimeException('Fixture auth timestamp missing.');
        }
        file_put_contents($path, $contents);
    };
    $login($cookie);
    $expire($cookie, time() - 2000, time() - 1801);
    [$status, $html] = $request('/admin/?view=widget-test', $cookie, 'GET', [], $tls);
    $assert($status === 200 && !str_contains($html, 'data-pollyanna-like'), 'Actual idle-expired session loses authorization');
    $login($cookie);
    $expire($cookie, time() - 28801, time());
    [$status, $html] = $request('/admin/?view=widget-test', $cookie, 'GET', [], $tls);
    $assert($status === 200 && !str_contains($html, 'data-pollyanna-like'), 'Actual absolute-expired session loses authorization');
    $login($cookie);
    file_put_contents($root . '/config/admin_credentials.json', json_encode(['password_hash' => password_hash($password, PASSWORD_BCRYPT)], JSON_THROW_ON_ERROR));
    [$status, $html] = $request('/admin/?view=widget-test', $cookie, 'GET', [], $tls);
    $assert($status === 200 && !str_contains($html, 'data-pollyanna-like'), 'Credential change revokes an existing Web session');
    rename($root . '/config/admin_credentials.json', $root . '/config/credentials-held');
    [$status] = $request('/admin/', $cookie, 'GET', [], $tls);
    $assert($status === 403, 'Loopback proxy cannot use local password setup');
    rename($root . '/config/credentials-held', $root . '/config/admin_credentials.json');
    // A real subdirectory mount must use that URL's Cookie Path, not /admin/.
    mkdir($root . '/nested', 0700);
    mkdir($root . '/nested/pollyanna', 0700);
    foreach (['admin', 'src', 'lang', 'config'] as $directory) $copy($root . '/' . $directory, $root . '/nested/pollyanna/' . $directory);
    $nestedConfig = $config;
    $nestedConfig['admin_security']['instance_id'] = bin2hex(random_bytes(16));
    file_put_contents($root . '/nested/pollyanna/config/config.php', '<?php return ' . var_export($nestedConfig, true) . ';');
    $nestedCookie = '';
    [$status, , $token, $headers] = $request('/nested/pollyanna/admin/', $nestedCookie, 'GET', [], $tls);
    $assert($status === 200 && str_contains(implode("\n", $headers), 'path=/nested/pollyanna/admin/'), 'Subdirectory Set-Cookie matches the actual URL');
    [$status] = $request('/nested/pollyanna/admin/', $nestedCookie, 'POST', ['action' => 'admin_login', 'password' => $password, 'csrf_token' => $token], $tls);
    $assert($status === 302, 'Subdirectory login');
    [$status, $html] = $request('/nested/pollyanna/admin/?view=widget-test', $nestedCookie, 'GET', [], $tls);
    $assert($status === 200 && str_contains($html, 'data-pollyanna-like'), 'Subdirectory authenticated view');
    $config['admin_security']['admin_path'] = '/wrong/admin/';
    $writeConfig();
    $cookie = '';
    [$status] = $request('/admin/', $cookie, 'GET', [], $tls);
    $assert($status === 400 && $cookie === '', 'Misconfigured Cookie Path cannot start a session');
    unset($config['admin_security']['admin_path']);
    $config['admin_security']['origin'] = 'https://example.test/path';
    $writeConfig();
    [$status] = $request('/admin/', $cookie, 'GET', [], $tls);
    $assert($status === 503 && $cookie === '', 'Invalid redirect setting fails before session creation');
    unset($config['admin_security']['origin']);
    $writeConfig();
    [$status, $html] = $request('/admin/', $cookie, 'GET', [], $tls);
    $assert($status === 200 && str_contains($html, 'name="password"') && !str_contains($html, 'data-pollyanna-like'), 'Correcting settings restores password login, not authorization bypass');
    // New-instance dev configuration avoids mixing Secure and non-Secure cookies.
    $config['site_origin'] = $base;
    $config['admin_security'] = ['environment' => 'development', 'instance_id' => bin2hex(random_bytes(16))];
    $writeConfig();
    $cookie = '';
    [$status, , , $headers] = $request('/admin/', $cookie);
    $assert($status === 200 && !str_contains(strtolower(implode("\n", $headers)), '; secure'), 'Explicit development HTTP cookie (status ' . $status . ')');
    $config['admin_security']['environment'] = 'production';
    $writeConfig();
    [$status] = $request('/admin/', $cookie);
    $assert($status === 503, 'Unmigrated HTTP origin stops safely');
    $config['site_origin'] = 'https://' . $address;
    $config['admin_security'] = ['environment' => 'production', 'trusted_proxies' => ['127.0.0.1']];
    $writeConfig();
    rename($root . '/sessions', $root . '/sessions-held');
    file_put_contents($root . '/sessions', 'blocked');
    $cookie = '';
    [$status, $html] = $request('/admin/', $cookie, 'GET', [], $tls);
    $assert($status === 503 && !str_contains($html, $root) && !str_contains($html, $password), 'Unavailable session storage returns a non-secret 503');
    echo "Admin HTTP tests passed ($checks assertions; loopback fixture, simulated TLS proxy, no persistent DB).\n";
} finally {
    if (is_resource($secondary)) { proc_terminate($secondary); proc_close($secondary); }
    if (is_resource($process)) {
        proc_terminate($process);
        foreach ([1, 2] as $pipe) if (isset($pipes[$pipe]) && is_resource($pipes[$pipe])) fclose($pipes[$pipe]);
        proc_close($process);
    }
    $remove($root);
}
