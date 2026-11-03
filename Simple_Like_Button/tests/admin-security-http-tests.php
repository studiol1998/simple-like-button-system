<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/regression-prelude.php';
require_once dirname(__DIR__) . '/src/InstallerConfig.php';

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
            if (preg_match('/^Set-Cookie: ((?:pollyanna_admin_[a-f0-9]{32}|pollyanna_installer)=[^;]*)/i', $header, $match)) $cookie = $match[1];
        }
        preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $match);
        return [$status, $html, $match[1] ?? '', $responseHeaders];
    };
    $tls = ['X-Forwarded-Proto: https']; // Simulated trusted TLS terminator, not a TLS handshake.
    mkdir($root . '/install', 0700);
    $rootNoticeCookie = '';
    [$rootNoticeStatus, $rootNoticeHtml] = $request('/admin/?language=en', $rootNoticeCookie);
    $assert($rootNoticeStatus === 503 && str_contains($rootNoticeHtml, 'href="./?language=en">Return to Admin</a>')
        && $rootNoticeCookie === '', 'Document-root installation return link needs no session');
    rmdir($root . '/install');
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
    // New-install integration: exercise the real basic-settings form, shared
    // generator and admin controller. Database provisioning itself is not run.
    $wizardRoot = $root . '/Pollyanna';
    mkdir($wizardRoot, 0700);
    foreach (['admin', 'src', 'lang', 'install'] as $directory) $copy(dirname(__DIR__) . '/' . $directory, $wizardRoot . '/' . $directory);
    foreach (['config', 'log'] as $directory) {
        mkdir($wizardRoot . '/' . $directory, 0700);
        file_put_contents($wizardRoot . '/' . $directory . '/.htaccess', "Require all denied\n");
    }
    $jaCatalog = json_decode(file_get_contents(dirname(__DIR__) . '/lang/admin.ja.json'), true, 64, JSON_THROW_ON_ERROR);
    $enCatalog = json_decode(file_get_contents(dirname(__DIR__) . '/lang/admin.en.json'), true, 64, JSON_THROW_ON_ERROR);
    $wizardCookie = '';
    $localHeaders = ['Host: localhost:' . explode(':', $address)[1]];
    $localOrigin = 'http://localhost:' . explode(':', $address)[1];
    $uninstalledCookie = '';
    [$status, $html] = $request('/Pollyanna/admin/', $uninstalledCookie, 'GET', [], $localHeaders);
    $assert($status === 503 && !str_contains($html, htmlspecialchars($jaCatalog['admin_ui']['installer_removal_required'], ENT_QUOTES, 'UTF-8'))
        && $uninstalledCookie === '', 'Uninstalled state retains the original non-lock condition');
    [$status, , $wizardToken] = $request('/Pollyanna/install/', $wizardCookie, 'GET', [], $localHeaders);
    $assert($status === 200 && $wizardToken !== '' && str_starts_with($wizardCookie, 'pollyanna_installer='), 'Actual installer welcome session');
    $wizardId = explode('=', $wizardCookie, 2)[1];
    if (preg_match('/\A[A-Za-z0-9,-]+\z/D', $wizardId) !== 1) throw new RuntimeException('Invalid isolated wizard SID.');
    $wizardSessionPath = $root . '/sessions/sess_' . $wizardId;
    $wizardDatabase = ['dsn' => 'invalid:fixture-never-connect', 'host' => '127.0.0.1', 'port' => '3306',
        'name' => 'fixture', 'user' => 'fixture', 'password' => 'fixture-only'];
    $seedWizard = static function (?array $basic = null) use ($wizardSessionPath, $wizardToken, $wizardDatabase): void {
        $data = ['installer_csrf' => $wizardToken, 'installer_language' => 'ja', 'installer_database' => $wizardDatabase,
            'installer_step' => $basic === null ? 'basic' : 'confirmation'];
        if ($basic !== null) $data['installer_basic'] = $basic;
        $encoded = '';
        foreach ($data as $key => $value) $encoded .= $key . '|' . serialize($value);
        file_put_contents($wizardSessionPath, $encoded);
    };
    $seedWizard();
    [$status, $html] = $request('/Pollyanna/install/?step=basic', $wizardCookie, 'GET', [], $localHeaders);
    $assert($status === 200 && str_contains($html, 'name="admin_environment"') && str_contains($html, 'value="/Pollyanna/admin/"'), 'Actual wizard environment fields and case-sensitive path');
    $wizardPost = ['action' => 'save_basic', 'csrf_token' => $wizardToken, 'site_origin' => $localOrigin,
        'site_base_domain' => 'localhost', 'timezone' => 'Asia/Tokyo', 'admin_password' => $password,
        'admin_password_confirmation' => $password, 'admin_environment' => 'development',
        'admin_origin' => $localOrigin, 'admin_path' => '/Pollyanna/admin/', 'admin_development_confirmed' => '1'];
    foreach ([['admin_environment' => ''], ['admin_environment' => 'production'], ['admin_development_confirmed' => '0']] as $invalid) {
        [$status, $html] = $request('/Pollyanna/install/', $wizardCookie, 'POST', array_replace($wizardPost, $invalid), $localHeaders);
        $assert($status === 200 && str_contains($html, 'role="alert"') && !is_file($wizardRoot . '/config/config.php'), 'Actual wizard rejects unsafe or unconfirmed selection');
    }
    [$status, $html] = $request('/Pollyanna/install/', $wizardCookie, 'POST', $wizardPost, array_merge($localHeaders, ['X-Forwarded-Proto: https']));
    $assert($status === 200 && str_contains($html, 'role="alert"'), 'Actual wizard rejects proxy-marked development');
    [$status] = $request('/Pollyanna/install/', $wizardCookie, 'POST', array_replace($wizardPost,
        ['admin_environment' => 'production', 'admin_origin' => 'https://localhost:' . explode(':', $address)[1]]), $localHeaders);
    $assert($status === 302, 'Actual wizard accepts explicit HTTPS production selection');
    [$status] = $request('/Pollyanna/install/', $wizardCookie, 'POST', $wizardPost, $localHeaders);
    $assert($status === 302, 'Actual wizard accepts confirmed direct development');
    [$status, $html] = $request('/Pollyanna/install/?step=confirmation', $wizardCookie, 'GET', [], $localHeaders);
    $assert($status === 200 && str_contains($html, '/Pollyanna/admin/') && str_contains($html, htmlspecialchars($localOrigin, ENT_QUOTES, 'UTF-8')), 'Confirmation shows admin origin and path');
    $wizardServer = ['SCRIPT_NAME' => '/Pollyanna/install/index.php', 'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_HOST' => 'localhost:' . explode(':', $address)[1], 'HTTPS' => 'off'];
    $wizardAdmin = InstallerConfig::adminSettings('development', $localOrigin, '/Pollyanna/admin/', true, $wizardServer);
    $wizardBasic = ['timezone' => 'Asia/Tokyo', 'admin_password' => $password, 'site_origin' => $localOrigin,
        'site_base_domain' => 'localhost', 'site_base_domain_include_subdomains' => false,
        'admin_security' => $wizardAdmin, 'admin_development_confirmed' => true];
    $assert(str_contains(file_get_contents($wizardSessionPath), 'installer_basic|' . serialize($wizardBasic)), 'Actual wizard session matches generator inputs');
    $badBasic = $wizardBasic;
    $badBasic['admin_security']['environment'] = 'production'; // HTTP must fail before DB/marker creation.
    $seedWizard($badBasic);
    [$status] = $request('/Pollyanna/install/', $wizardCookie, 'POST', ['action' => 'install', 'csrf_token' => $wizardToken], $localHeaders);
    $assert(!is_file($wizardRoot . '/config/.installer-in-progress') && !is_file($wizardRoot . '/config/config.php'), 'Final validation precedes all install writes');
    $generated = InstallerConfig::contents(['dsn' => 'sqlite::memory:', 'user' => '', 'password' => ''],
        $wizardBasic, bin2hex(random_bytes(32)), $wizardServer);
    file_put_contents($wizardRoot . '/config/config.php', $generated);
    file_put_contents($wizardRoot . '/config/admin_credentials.json', json_encode(['password_hash' => $hash], JSON_THROW_ON_ERROR));
    file_put_contents($wizardRoot . '/config/install_complete.json', '{"completed":true}');
    $built = require $wizardRoot . '/config/config.php';
    $broken = $built;
    $broken['admin_security']['environment'] = 'production'; // Deliberately invalid HTTP production settings.
    file_put_contents($wizardRoot . '/config/config.php', '<?php return ' . var_export($broken, true) . ';');
    $sessionSnapshot = static function () use ($root): array {
        $result = [];
        foreach (glob($root . '/sessions/sess_*') ?: [] as $file) $result[basename($file)] = hash_file('sha256', $file);
        ksort($result); return $result;
    };
    $beforeNotice = $sessionSnapshot();
    $beforeCredentials = hash_file('sha256', $wizardRoot . '/config/admin_credentials.json');
    foreach (['ja' => $jaCatalog, 'en' => $enCatalog] as $code => $catalog) {
        $noticeCookie = '';
        [$status, $html, , $headers] = $request('/Pollyanna/admin/?language=' . $code, $noticeCookie, 'GET', [], $localHeaders);
        $assert($status === 503 && str_contains($html, htmlspecialchars($catalog['admin_ui']['installer_removal_required'], ENT_QUOTES, 'UTF-8'))
            && !str_contains($html, 'transport or session settings'), 'Complete install shows localized removal notice before transport checks');
        $assert(!str_contains(strtolower(implode("\n", $headers)), 'set-cookie:'), 'Notice starts no session');
        $returnLabel = $code === 'ja' ? '管理画面へ戻る' : 'Return to Admin';
        $assert(str_contains($html, 'href="./?language=' . $code . '">' . $returnLabel . '</a>'), 'Localized return link uses current admin directory');
        $returnPaths[$code] = '/Pollyanna/admin/?language=' . $code;
        [$returnStatus, $returnHtml, , $returnHeaders] = $request($returnPaths[$code], $noticeCookie, 'GET', [], $localHeaders);
        $assert($returnStatus === 503 && str_contains($returnHtml, $returnLabel)
            && !str_contains(strtolower(implode("\n", $returnHeaders)), 'set-cookie:'), 'Return link keeps installer lock without starting a session');
    }
    $noticeCookie = '';
    [$status] = $request('/Pollyanna/admin/', $noticeCookie, 'POST', ['action' => 'save_admin_theme', 'theme' => 'rose', 'csrf_token' => $wizardToken], $localHeaders);
    $assert($status === 503 && !is_file($wizardRoot . '/config/admin_settings.json') && $beforeNotice === $sessionSnapshot()
        && $beforeCredentials === hash_file('sha256', $wizardRoot . '/config/admin_credentials.json'), 'Locked POST performs no session/auth/settings writes');
    rename($wizardRoot . '/config/config.php', $wizardRoot . '/config/config-held');
    unlink($wizardRoot . '/config/install_complete.json');
    [$status, $html] = $request('/Pollyanna/admin/?language=en', $noticeCookie, 'GET', [], $localHeaders);
    $assert($status === 503 && str_contains($html, htmlspecialchars($enCatalog['admin_ui']['installer_removal_required'], ENT_QUOTES, 'UTF-8')), 'Partial install without config retains original lock notice');
    unlink($wizardRoot . '/config/config-held');
    file_put_contents($wizardRoot . '/config/config.php', $generated);
    file_put_contents($wizardRoot . '/config/install_complete.json', '{"completed":true}');
    rename($root . '/sessions', $root . '/notice-sessions-held');
    file_put_contents($root . '/sessions', 'not-a-session-directory');
    [$status, $html] = $request('/Pollyanna/admin/', $noticeCookie, 'GET', [], $localHeaders);
    $assert($status === 503 && str_contains($html, htmlspecialchars($jaCatalog['admin_ui']['installer_removal_required'], ENT_QUOTES, 'UTF-8'))
        && !str_contains($html, 'session storage is unavailable'), 'Removal notice does not initialize or finish admin session storage');
    unlink($root . '/sessions');
    rename($root . '/notice-sessions-held', $root . '/sessions');
    rename($wizardRoot . '/install', $root . '/installer-fixture-held'); // Remove from the app's lookup path.
    $generatedCookie = '';
    foreach ($returnPaths as $code => $returnPath) {
        $returnCookie = '';
        [$returnStatus, $returnHtml, $returnToken] = $request($returnPath, $returnCookie, 'GET', [], $localHeaders);
        $assert($returnStatus === 200 && $returnToken !== '' && str_contains($returnHtml, 'name="password"'), 'Same return link reaches login after installer removal');
    }
    [$status, , $generatedToken, $headers] = $request('/Pollyanna/admin/', $generatedCookie, 'GET', [], $localHeaders);
    $assert($status === 200 && $generatedToken !== '' && str_contains(implode("\n", $headers), 'path=/Pollyanna/admin/'), 'Generated development config reaches login with correct Cookie Path');
    [$status] = $request('/Pollyanna/admin/', $generatedCookie, 'POST', ['action' => 'admin_login', 'password' => $password, 'csrf_token' => $generatedToken], $localHeaders);
    $assert($status === 302, 'Generated config authenticates successfully');
    [$status, $html] = $request('/Pollyanna/admin/?view=widget-test', $generatedCookie, 'GET', [], $localHeaders);
    $assert($status === 200 && str_contains($html, 'data-pollyanna-like'), 'Generated config reaches authenticated view');
    $productionBasic = $wizardBasic;
    $productionBasic['admin_security']['environment'] = 'production';
    $productionBasic['admin_security']['origin'] = 'https://localhost:' . explode(':', $address)[1];
    file_put_contents($wizardRoot . '/config/config.php', InstallerConfig::contents(['dsn' => 'sqlite::memory:', 'user' => '', 'password' => ''],
        $productionBasic, bin2hex(random_bytes(32)), $wizardServer));
    $generatedCookie = '';
    [$status, , , $headers] = $request('/Pollyanna/admin/', $generatedCookie, 'GET', [], $localHeaders);
    $assert($status === 302 && in_array('Location: https://localhost:' . explode(':', $address)[1] . '/Pollyanna/admin/', $headers, true)
        && $generatedCookie === '', 'Generated production config redirects HTTP without authentication');
    // Simulate the native TLS server variable only inside this isolated fixture.
    // This tests the generated production config, not a real TLS handshake.
    rename($wizardRoot . '/admin/index.php', $wizardRoot . '/admin/controller-fixture.php');
    file_put_contents($wizardRoot . '/admin/index.php', '<?php declare(strict_types=1); $_SERVER["HTTPS"]="on"; require __DIR__."/controller-fixture.php";');
    [$status, , $generatedToken, $headers] = $request('/Pollyanna/admin/', $generatedCookie, 'GET', [], $localHeaders);
    $assert($status === 200 && $generatedToken !== '' && str_contains(strtolower(implode("\n", $headers)), '; secure'), 'Generated production config accepts simulated native HTTPS with Secure cookie');
    [$status] = $request('/Pollyanna/admin/', $generatedCookie, 'POST', ['action' => 'admin_login', 'password' => $password, 'csrf_token' => $generatedToken], $localHeaders);
    $assert($status === 302, 'Generated production config login on simulated native HTTPS');
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
