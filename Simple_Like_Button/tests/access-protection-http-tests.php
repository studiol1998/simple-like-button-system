<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Dedicated database, copied application and separate PHP session storage only.
$name = 'pollyanna_access_http_' . bin2hex(random_bytes(6));
$host = getenv('POLLYANNA_TEST_DB_HOST') ?: '127.0.0.1';
$port = getenv('POLLYANNA_TEST_DB_PORT') ?: '3306';
$user = getenv('POLLYANNA_TEST_DB_USER') ?: 'root';
$password = getenv('POLLYANNA_TEST_DB_PASSWORD') ?: '';
$server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");
$root = sys_get_temp_dir() . '/' . $name;
mkdir($root); mkdir($root . '/config'); mkdir($root . '/sessions');
$process = null;
$copy = static function (string $from, string $to) use (&$copy): void {
    if (is_dir($from)) {
        if (!is_dir($to)) mkdir($to);
        foreach (array_diff(scandir($from) ?: [], ['.', '..']) as $file) $copy($from . '/' . $file, $to . '/' . $file);
    } else { copy($from, $to); }
};
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path)) {
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $file) $remove($path . '/' . $file);
        rmdir($path);
    } else { unlink($path); }
};
$checks = 0;
$assert = static function (bool $ok, string $label) use (&$checks): void { $checks++; if (!$ok) throw new RuntimeException($label); };
try {
    foreach (['api', 'src', 'admin', 'assets', 'lang', 'log'] as $directory) $copy(dirname(__DIR__) . '/' . $directory, $root . '/' . $directory);
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    foreach (preg_split('/;\s*(?:\r?\n|\z)/', (string) file_get_contents(dirname(__DIR__) . '/install/install.sql'), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $sql) $pdo->exec($sql);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) throw new RuntimeException($error);
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $base = 'http://' . $address;
    $config = ['database' => ['dsn' => "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", 'username' => $user, 'password' => $password],
        'app_secret' => str_repeat('a', 64), 'site_origin' => $base, 'site_base_domain' => '127.0.0.1',
        'site_base_domain_include_subdomains' => false, 'widget_language' => 'en', 'click_success_message' => 'like03'];
    file_put_contents($root . '/config/config.php', '<?php return ' . var_export($config, true) . ';');
    $adminPassword = bin2hex(random_bytes(32));
    file_put_contents($root . '/config/admin_credentials.json', json_encode(['password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT)]));
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $root . '/sessions', '-S', $address, '-t', $root],
        [0 => ['pipe', 'r'], 1 => ['file', $root . '/server-out.log', 'a'], 2 => ['file', $root . '/server-error.log', 'a']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start isolated PHP server');
    fclose($pipes[0]);
    $cookie = '';
    $request = static function (string $path, string $method = 'GET', string $body = '', array $headers = []) use ($base, &$cookie): array {
        if ($cookie !== '') $headers[] = 'Cookie: ' . $cookie;
        $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers),
            'content' => $body, 'ignore_errors' => true, 'timeout' => 10]]);
        $contents = file_get_contents($base . $path, false, $context);
        $responseHeaders = $http_response_header ?? [];
        $status = 0;
        foreach ($responseHeaders as $header) {
            if (preg_match('/^HTTP\/\S+ (\d+)/', $header, $matches)) $status = (int) $matches[1];
            if (preg_match('/^Set-Cookie: (PHPSESSID=[^;]+)/i', $header, $matches)) $cookie = $matches[1];
        }
        return [$status, (string) $contents, implode("\n", $responseHeaders)];
    };
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $ready = @stream_socket_client('tcp://' . $address, $errno, $error, .1);
        if ($ready !== false) { fclose($ready); break; }
        usleep(100000);
    }
    $payload = json_encode(['identifier' => 'test', 'client_token' => str_repeat('c', 64)]);
    $headers = ['Origin: ' . $base, 'Content-Type: application/json'];
    [$status] = $request('/api/state.php', 'POST', $payload, $headers);
    $assert($status === 200, 'Normal state succeeds');
    [$status] = $request('/api/state.php', 'OPTIONS', '', ['Origin: ' . $base, 'Access-Control-Request-Method: POST', 'Access-Control-Request-Headers: Content-Type']);
    $assert($status === 204, 'Normal OPTIONS succeeds');
    $assert((int) $pdo->query('SELECT SUM(request_count) FROM pollyanna_access_buckets')->fetchColumn() === 1, 'Normal OPTIONS not counted');
    [$status] = $request('/api/like.php', 'POST', '{}', $headers);
    $assert($status === 400, 'Invalid input counted and retains error');
    [$status] = $request('/api/like.php', 'POST', $payload, ['Origin: https://not-allowed.example', 'Content-Type: application/json']);
    $assert($status === 403, 'Invalid Origin retains 403');
    $assert((int) $pdo->query("SELECT SUM(request_count) FROM pollyanna_access_buckets WHERE api='like'")->fetchColumn() === 2, 'Invalid Origin and input counted');
    [$status, $html] = $request('/admin/');
    $assert(str_contains($html, 'admin-password') && !str_contains($html, 'access-protection-card'), 'Unauthenticated admin cannot inspect protection');
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $matches); $csrf = $matches[1] ?? '';
    [$status] = $request('/admin/', 'POST', http_build_query(['action' => 'admin_login', 'csrf_token' => $csrf, 'password' => $adminPassword]), ['Content-Type: application/x-www-form-urlencoded']);
    $assert($status === 200, 'Admin login redirects to authenticated page');
    [$status, $html] = $request('/admin/?tab=admin-operation');
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $matches); $csrf = $matches[1] ?? '';
    $assert(substr_count($html, 'role="tab"') === 4 && str_contains($html, 'access-protection-card'), 'Four tabs and protection card');
    $form = ['action' => 'save_access_protection', 'csrf_token' => $csrf, 'access_enabled' => 'on', 'access_auto_block' => 'off',
        'access_state_window' => 60, 'access_state_threshold' => 2, 'access_like_window' => 60, 'access_like_threshold' => 2];
    [$status] = $request('/admin/', 'POST', http_build_query(array_replace($form, ['csrf_token' => 'invalid'])), ['Content-Type: application/x-www-form-urlencoded']);
    $assert($status === 403 && !is_file($root . '/config/access_protection_settings.json'), 'CSRF rejection prevents saving');
    [$status] = $request('/admin/', 'POST', http_build_query($form), ['Content-Type: application/x-www-form-urlencoded']);
    $assert($status === 200 && is_file($root . '/config/access_protection_settings.json'), 'Protection settings saved');
    [$status] = $request('/api/like.php', 'POST', $payload, $headers);
    $assert($status === 201, 'Detection-only threshold does not block');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM pollyanna_access_history')->fetchColumn() > 0, 'HTTP detection recorded');
    $form['access_auto_block'] = 'on';
    $request('/admin/', 'POST', http_build_query($form), ['Content-Type: application/x-www-form-urlencoded']);
    $pdo->exec("INSERT INTO pollyanna_allowed_origins (scheme,host,port,include_subdomains,created_at) VALUES ('https','external.example',443,0,UTC_TIMESTAMP())");
    [$status, $body, $responseHeaders] = $request('/api/like.php', 'POST', $payload, ['Origin: https://external.example', 'Content-Type: application/json', 'X-Forwarded-For: 192.0.2.99']);
    $result = json_decode($body, true);
    $assert($status === 429 && ($result['access_blocked'] ?? false) && !isset($result['rate_limited']), 'Access block distinct from Like quota');
    $assert(str_contains($responseHeaders, 'Access-Control-Allow-Origin: https://external.example') && str_contains($responseHeaders, 'Retry-After: 172800'), '429 retains CORS and Retry-After');
    require_once dirname(__DIR__) . '/src/AccessProtectionService.php';
    $assert($pdo->query('SELECT ip_hash FROM pollyanna_access_blocks')->fetchColumn() === AccessProtectionService::ipHash('127.0.0.1', str_repeat('a', 64)), 'Forwarded header ignored');
    [$status] = $request('/api/state.php', 'POST', $payload, $headers);
    $assert($status === 429, 'Block covers state API');
    [$status] = $request('/api/state.php', 'OPTIONS', '', ['Origin: ' . $base, 'Access-Control-Request-Method: POST', 'Access-Control-Request-Headers: Content-Type']);
    $assert($status === 204, 'Normal OPTIONS stays 204 while blocked');
    [$status] = $request('/api/state.php', 'POST', $payload, ['Origin: null', 'Content-Type: application/json']);
    $assert($status === 403, 'Origin rejection has priority over block');
    [$status, $html] = $request('/admin/?tab=admin-operation');
    $assert($status === 200 && str_contains($html, 'data-access-release-form') && !str_contains($html, AccessProtectionService::ipHash('127.0.0.1', str_repeat('a', 64))), 'Admin accessible; hashes not exposed');
    $id = (int) $pdo->query('SELECT id FROM pollyanna_access_blocks')->fetchColumn();
    $release = ['action' => 'release_access_block', 'csrf_token' => $csrf, 'access_block_id' => $id];
    [$status] = $request('/admin/', 'POST', http_build_query(array_replace($release, ['csrf_token' => 'invalid'])), ['Content-Type: application/x-www-form-urlencoded']);
    $assert($status === 403 && (int) $pdo->query('SELECT COUNT(*) FROM pollyanna_access_blocks')->fetchColumn() === 1, 'Release CSRF protected');
    [$status] = $request('/admin/', 'POST', http_build_query($release), ['Content-Type: application/x-www-form-urlencoded']);
    $assert($status === 200 && (int) $pdo->query('SELECT COUNT(*) FROM pollyanna_access_blocks')->fetchColumn() === 0, 'HTTP manual release');
    [$status] = $request('/api/state.php', 'POST', $payload, $headers);
    $assert($status === 200, 'API usable after release');
    if (($argv[1] ?? '') === '--browser') {
        [$status, $snapshot] = $request('/admin/?tab=admin-operation');
        file_put_contents($root . '/admin/layout-qa.html', $snapshot);
        $fixture = <<<'HTML'
<!doctype html><meta charset="utf-8"><title>Isolated layout QA</title>
<iframe id="wide" src="/admin/layout-qa.html" style="width:1100px;height:900px;border:0"></iframe>
<iframe id="narrow" src="/admin/layout-qa.html" style="width:320px;height:900px;border:0"></iframe>
<pre id="result">PENDING</pre>
<script>
window.addEventListener('load', () => {
 try {
  let checks = 0;
  const assert = (ok, label) => { if (!ok) throw Error(label); checks++; };
  for (const id of ['wide', 'narrow']) {
   const frame = document.getElementById(id), d = frame.contentDocument, w = frame.contentWindow;
   const cards = [...d.querySelectorAll('#panel-admin-operation .settings-card')];
   assert(cards.length === 4, id + ' four operation cards');
   const widths = cards.map(c => c.getBoundingClientRect().width);
   assert(Math.max(...widths) - Math.min(...widths) < 1, id + ' same card widths');
   assert(cards.every((c,i) => !i || c.getBoundingClientRect().top >= cards[i-1].getBoundingClientRect().bottom), id + ' vertical order');
   assert(d.documentElement.scrollWidth <= w.innerWidth, id + ' page has no horizontal overflow');
   assert([...d.querySelectorAll('#panel-admin-operation form')].every(f => f.getBoundingClientRect().width <= 480), id + ' operation forms <=480');
   const value = d.getElementById('access-state-threshold'); value.value = '1234';
   d.querySelector('[data-admin-tab="public-display"]').click();
   assert(w.location.search.includes('tab=public-display'), id + ' URL updated');
   const publicPanel = d.getElementById('panel-public-display');
   assert(!publicPanel.hidden && publicPanel.querySelector('form').getBoundingClientRect().width <= 480, id + ' public form <=480');
   assert(publicPanel.querySelector('.like-preview').getBoundingClientRect().width <= 480, id + ' preview <=480');
   assert(d.documentElement.scrollWidth <= w.innerWidth, id + ' public no overflow');
   const symbol = d.querySelector('[data-button-symbol-preset]'); symbol.value='star'; symbol.dispatchEvent(new w.Event('change', {bubbles:true}));
   const preview = d.querySelector('[data-like-preview-button]'); assert(preview.textContent === '☆', id + ' unliked preview'); preview.click();
   assert(preview.textContent === '★' && preview.getAttribute('aria-pressed') === 'true', id + ' liked preview');
   d.querySelector('[data-preview-background="dark"]').click(); assert(d.querySelector('[data-like-preview-stage]').dataset.background === 'dark', id + ' background');
   const publicTab = d.querySelector('[data-admin-tab="public-display"]');
   publicTab.dispatchEvent(new w.KeyboardEvent('keydown', {key:'ArrowRight',bubbles:true})); assert(!d.getElementById('panel-dashboard').hidden, id + ' arrow wrap');
   d.querySelector('[data-admin-tab="dashboard"]').dispatchEvent(new w.KeyboardEvent('keydown', {key:'End',bubbles:true})); assert(!publicPanel.hidden, id + ' End');
   publicTab.dispatchEvent(new w.KeyboardEvent('keydown', {key:'Home',bubbles:true})); assert(!d.getElementById('panel-dashboard').hidden, id + ' Home');
   d.querySelector('[data-admin-tab="admin-operation"]').click(); assert(value.value === '1234', id + ' unsaved input retained');
  }
  document.getElementById('result').textContent = 'PASS ' + checks;
 } catch (e) { document.getElementById('result').textContent = 'FAIL ' + e.message; }
});
</script>
HTML;
        file_put_contents($root . '/layout-qa.html', $fixture);
        $browser = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
        $browserPipes = [];
        $browserProcess = proc_open([$browser, '--headless=new', '--no-first-run', '--disable-background-networking', '--disable-gpu',
            '--user-data-dir=' . $root . '/browser-profile', '--virtual-time-budget=10000', '--dump-dom', $base . '/layout-qa.html'],
            [0 => ['pipe', 'r'], 1 => ['file', $root . '/browser-dom.txt', 'w'], 2 => ['file', $root . '/browser-error.txt', 'w']], $browserPipes);
        if (!is_resource($browserProcess)) throw new RuntimeException('Browser launch failed');
        fclose($browserPipes[0]);
        $exit = proc_close($browserProcess);
        $dom = (string) file_get_contents($root . '/browser-dom.txt');
        $assert($exit === 0 && preg_match('/<pre id="result">PASS (\d+)<\/pre>/', $dom, $metrics) === 1, 'Browser QA failed: ' . (preg_match('/<pre id="result">([^<]*)/', $dom, $failure) ? $failure[1] : 'no result'));
        echo 'Headless Chrome layout and UI tests passed (' . $metrics[1] . " checks at 1100px and 320px).\n";
        mkdir($root . '/tests');
        foreach (['phase1-browser-tests.html', 'phase3-like-polling-tests.html'] as $test) {
            copy(__DIR__ . '/' . $test, $root . '/tests/' . $test);
            $testPipes = [];
            $testBrowser = proc_open([$browser, '--headless=new', '--no-first-run', '--disable-background-networking', '--disable-gpu',
                '--user-data-dir=' . $root . '/browser-profile-' . $test, '--virtual-time-budget=10000', '--dump-dom', $base . '/tests/' . $test],
                [0 => ['pipe', 'r'], 1 => ['file', $root . '/' . $test . '.dom', 'w'], 2 => ['file', $root . '/' . $test . '.errors', 'w']], $testPipes);
            if (!is_resource($testBrowser)) throw new RuntimeException('Could not run browser regression');
            fclose($testPipes[0]); $testExit = proc_close($testBrowser);
            $testDom = (string) file_get_contents($root . '/' . $test . '.dom');
            $assert($testExit === 0 && str_contains($testDom, 'data-test-status="passed"'), 'Browser regression failed: ' . $test);
            echo $test . " passed in headless Chrome.\n";
        }
    }
    echo "Access protection HTTP tests passed ({$checks} assertions).\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    $server->exec("DROP DATABASE `{$name}`");
    $resolved = realpath($root);
    if ($resolved !== false && basename($resolved) === $name && realpath(dirname($resolved)) === realpath(sys_get_temp_dir())) $remove($root);
}
