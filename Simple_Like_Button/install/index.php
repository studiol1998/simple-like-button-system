<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$configPath = $root . '/config/config.php';
$credentialsPath = $root . '/config/admin_credentials.json';
$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_name('pollyanna_installer');
session_set_cookie_params(['httponly' => true, 'secure' => $isHttps, 'samesite' => 'Strict']);
session_start();

$messages = [
    'ja' => [
        'language_name' => '日本語',
        'title' => 'Pollyanna セットアップ',
        'installed_title' => 'インストール済みです',
        'installed_text' => 'Pollyannaはすでにインストールされています。再インストールや上書きは、この画面から実行できません。',
        'remove_installer_title' => '最後にインストーラーを削除してください',
        'remove_installer_text' => 'インストールは正常に完了しています。安全のため、サーバーからinstallディレクトリを削除するまで管理画面には入れません。',
        'remove_installer_path' => '削除するディレクトリ：install/',
        'admin_locked' => 'installディレクトリを削除した後、管理画面を開いてください。',
        'read_documentation' => 'READMEを確認',
        'view_widget_example' => 'ウィジェット設置例を確認',
        'admin_link' => '管理画面を開く',
        'environment_title' => '環境確認',
        'environment_intro' => 'インストールに必要なサーバー環境と、保存先の状態を確認します。',
        'requirement' => '確認項目',
        'result' => '結果',
        'detail' => '詳細',
        'ok' => 'OK',
        'failed' => '要確認',
        'php_version' => 'PHP 8.1以上',
        'pdo_mysql' => 'PDO MySQL',
        'json' => 'JSON',
        'session' => 'セッション',
        'config_writable' => 'configディレクトリへ書き込み可能',
        'install_sql' => 'テーブル定義ファイル',
        'access_protection' => 'configのApacheアクセス拒否設定',
        'php_detail' => '現在のPHP：%s',
        'enabled' => '利用できます。',
        'missing_extension' => 'PHP拡張を有効にしてください。',
        'session_detail' => 'セッションへ一時データを保存できました。',
        'directory_writable' => '設定ファイルを保存できます。',
        'directory_not_writable' => 'configディレクトリへWebサーバーの書き込み権限を設定してください。',
        'file_found' => 'install/install.sqlを確認しました。',
        'file_missing' => 'install/install.sqlがありません。配布ファイルを再配置してください。',
        'protection_found' => 'config/.htaccessを確認しました。Apache以外では同等のアクセス拒否設定が必要です。',
        'protection_missing' => 'config/.htaccessがありません。設定ファイルへのWebアクセスを拒否してください。',
        'ready' => '必要な環境を確認できました。次はデータベース接続を設定します。',
        'not_ready' => '要確認の項目を修正してから、ページを再読み込みしてください。',
        'continue' => 'データベース設定へ',
        'reload' => '再確認',
        'csrf_error' => '確認トークンが一致しません。ページを再読み込みしてください。',
        'database_title' => 'データベース設定',
        'database_intro' => 'Pollyannaが使用するMySQLまたはMariaDBの接続情報を入力してください。',
        'db_host' => 'DBホスト',
        'db_port' => 'ポート',
        'db_name' => 'DB名',
        'db_user' => 'ユーザー名',
        'db_password' => 'パスワード',
        'db_password_help' => '接続確認後、パスワードは画面に再表示しません。',
        'db_connect' => '接続を確認して次へ',
        'db_invalid' => 'DB接続情報の入力形式を確認してください。',
        'db_failed' => 'データベースへ接続できませんでした。ホスト、DB名、ユーザー名、パスワードを確認してください。',
        'basic_title' => '基本設定',
        'basic_intro' => '集計に使うタイムゾーンと、管理画面のパスワードを設定します。',
        'timezone' => '集計タイムゾーン',
        'admin_password' => '管理パスワード',
        'admin_password_confirmation' => '管理パスワード（確認）',
        'password_requirement' => '12文字以上で入力してください。保存するのはパスワードのハッシュだけです。',
        'show_password' => 'パスワードを表示',
        'basic_continue' => '実行確認へ',
        'password_invalid' => '管理パスワードは12文字以上、1024文字以下で入力してください。',
        'password_mismatch' => '確認用の管理パスワードが一致しません。',
        'timezone_invalid' => 'タイムゾーンを選択してください。',
        'back' => '戻る',
        'confirmation_title' => 'インストール内容の確認',
        'confirmation_intro' => '次の内容でテーブルと設定ファイルを作成します。DBパスワードと管理パスワードは表示しません。',
        'database' => 'データベース',
        'database_server' => 'DBサーバー',
        'database_name' => 'DB名',
        'database_user' => 'DBユーザー',
        'create_tables' => '必要なテーブルを作成',
        'generate_secret' => '64桁のアプリ秘密鍵を生成',
        'save_admin_password' => '管理パスワードをハッシュ化して保存',
        'lock_admin' => '完了後、installディレクトリ削除まで管理画面をロック',
        'install_button' => 'インストールを実行',
        'install_failed' => 'インストールを完了できませんでした。入力内容とサーバー設定を確認して、もう一度実行してください。DBパスワードや管理パスワードはログへ出力していません。',
        'steps' => ['環境確認', 'DB設定', '基本設定', '実行確認', 'インストール', '完了'],
    ],
    'en' => [
        'language_name' => 'English',
        'title' => 'Pollyanna Setup',
        'installed_title' => 'Already installed',
        'installed_text' => 'Pollyanna is already installed. This screen cannot reinstall or overwrite it.',
        'remove_installer_title' => 'Delete the installer to finish',
        'remove_installer_text' => 'Installation completed successfully. For security, the admin interface remains locked until you delete the install directory from the server.',
        'remove_installer_path' => 'Directory to delete: install/',
        'admin_locked' => 'After deleting the install directory, open the admin interface.',
        'read_documentation' => 'Read the README',
        'view_widget_example' => 'View widget example',
        'admin_link' => 'Open admin',
        'environment_title' => 'Environment check',
        'environment_intro' => 'Check the server requirements and configuration destination before installation.',
        'requirement' => 'Requirement',
        'result' => 'Result',
        'detail' => 'Details',
        'ok' => 'OK',
        'failed' => 'Check required',
        'php_version' => 'PHP 8.1 or later',
        'pdo_mysql' => 'PDO MySQL',
        'json' => 'JSON',
        'session' => 'Sessions',
        'config_writable' => 'Writable config directory',
        'install_sql' => 'Table definition file',
        'access_protection' => 'Apache access protection for config',
        'php_detail' => 'Current PHP: %s',
        'enabled' => 'Available.',
        'missing_extension' => 'Enable this PHP extension.',
        'session_detail' => 'Temporary installer data can be stored in the session.',
        'directory_writable' => 'Configuration files can be saved.',
        'directory_not_writable' => 'Grant the web server write access to the config directory.',
        'file_found' => 'install/install.sql was found.',
        'file_missing' => 'install/install.sql is missing. Upload the distribution again.',
        'protection_found' => 'config/.htaccess was found. Non-Apache servers need an equivalent deny rule.',
        'protection_missing' => 'config/.htaccess is missing. Deny web access to configuration files.',
        'ready' => 'The required environment is ready. Database configuration comes next.',
        'not_ready' => 'Fix the items marked for review, then reload this page.',
        'continue' => 'Database settings',
        'reload' => 'Check again',
        'csrf_error' => 'The verification token did not match. Reload the page.',
        'database_title' => 'Database settings',
        'database_intro' => 'Enter the MySQL or MariaDB connection used by Pollyanna.',
        'db_host' => 'Database host',
        'db_port' => 'Port',
        'db_name' => 'Database name',
        'db_user' => 'Username',
        'db_password' => 'Password',
        'db_password_help' => 'The password will not be displayed again after the connection check.',
        'db_connect' => 'Verify connection and continue',
        'db_invalid' => 'Check the database connection value formats.',
        'db_failed' => 'Could not connect to the database. Check the host, database name, username, and password.',
        'basic_title' => 'Basic settings',
        'basic_intro' => 'Set the reporting timezone and admin password.',
        'timezone' => 'Aggregation timezone',
        'admin_password' => 'Admin password',
        'admin_password_confirmation' => 'Confirm admin password',
        'password_requirement' => 'Enter at least 12 characters. Only the password hash will be stored.',
        'show_password' => 'Show passwords',
        'basic_continue' => 'Review installation',
        'password_invalid' => 'Enter an admin password between 12 and 1024 characters.',
        'password_mismatch' => 'The admin password confirmation does not match.',
        'timezone_invalid' => 'Select a timezone.',
        'back' => 'Back',
        'confirmation_title' => 'Review installation',
        'confirmation_intro' => 'The installer will create tables and configuration files with these settings. Database and admin passwords are not displayed.',
        'database' => 'Database',
        'database_server' => 'Database server',
        'database_name' => 'Database name',
        'database_user' => 'Database user',
        'create_tables' => 'Create the required tables',
        'generate_secret' => 'Generate a 64-digit application secret',
        'save_admin_password' => 'Hash and save the admin password',
        'lock_admin' => 'Lock Admin after completion until the install directory is deleted',
        'install_button' => 'Install Pollyanna',
        'install_failed' => 'Installation could not be completed. Check the submitted values and server configuration, then try again. Database and admin passwords were not written to the log.',
        'steps' => ['Environment', 'Database', 'Basic settings', 'Confirmation', 'Install', 'Complete'],
    ],
];

$requestedLanguage = $_GET['language'] ?? $_POST['language'] ?? $_SESSION['installer_language'] ?? 'ja';
$language = is_string($requestedLanguage) && isset($messages[$requestedLanguage]) ? $requestedLanguage : 'ja';
$_SESSION['installer_language'] = $language;
$ui = $messages[$language];

if (!isset($_SESSION['installer_csrf']) || !is_string($_SESSION['installer_csrf'])) {
    $_SESSION['installer_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['installer_csrf'];

function installer_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function installer_has_valid_config(string $path): bool
{
    if (!is_file($path)) return false;
    try {
        $config = require $path;
    } catch (Throwable) {
        return false;
    }
    if (!is_array($config) || !is_array($config['database'] ?? null)) return false;
    $database = $config['database'];
    $secret = $config['app_secret'] ?? null;
    return is_string($database['dsn'] ?? null)
        && is_string($database['username'] ?? null)
        && is_string($database['password'] ?? null)
        && is_string($secret)
        && preg_match('/\A[a-f0-9]{64}\z/i', $secret) === 1;
}

function installer_has_valid_credentials(string $path): bool
{
    if (!is_file($path)) return false;
    $contents = file_get_contents($path);
    if ($contents === false) return false;
    try {
        $credentials = json_decode($contents, true, 4, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return false;
    }
    return is_array($credentials)
        && is_string($credentials['password_hash'] ?? null)
        && $credentials['password_hash'] !== '';
}

function installer_has_completion_marker(string $path): bool
{
    if (!is_file($path)) return false;
    $contents = file_get_contents($path);
    if ($contents === false) return false;
    try {
        $completion = json_decode($contents, true, 4, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return false;
    }
    return is_array($completion) && ($completion['completed'] ?? false) === true;
}

function installer_atomic_write(string $path, string $contents): void
{
    $directory = dirname($path);
    $temporary = tempnam($directory, '.pollyanna-install-');
    if ($temporary === false) throw new RuntimeException('Could not create a temporary configuration file.');
    try {
        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Could not write a temporary configuration file.');
        }
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('Could not replace an incomplete configuration file.');
        }
        if (!rename($temporary, $path)) {
            throw new RuntimeException('Could not publish a configuration file.');
        }
    } finally {
        if (is_file($temporary)) unlink($temporary);
    }
}

function installer_config_contents(array $database, string $timezone, string $secret): string
{
    $config = [
        'database' => [
            'dsn' => $database['dsn'],
            'username' => $database['user'],
            'password' => $database['password'],
        ],
        'app_secret' => $secret,
        'system_state' => 'enabled',
        'admin_language' => 'ja',
        'admin_theme' => 'green',
        'widget_language' => 'ja',
        'click_success_message' => 'like02',
        'show_like_count' => false,
        'like_count_color' => '#767676',
        'count_refresh_interval' => 5,
        'likes_per_24_hours' => 3,
        'aggregation_start_threshold' => 1000,
        'aggregation_batch_size' => 500,
        'timezone' => $timezone,
    ];
    return "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
}

$installed = installer_has_valid_config($configPath) && installer_has_valid_credentials($credentialsPath);
$webInstallationComplete = installer_has_completion_marker($root . '/config/install_complete.json');
$sessionProbe = bin2hex(random_bytes(8));
$_SESSION['installer_probe'] = $sessionProbe;
$checks = [
    ['label' => $ui['php_version'], 'ok' => version_compare(PHP_VERSION, '8.1.0', '>='), 'detail' => sprintf($ui['php_detail'], PHP_VERSION)],
    ['label' => $ui['pdo_mysql'], 'ok' => extension_loaded('pdo_mysql'), 'detail' => extension_loaded('pdo_mysql') ? $ui['enabled'] : $ui['missing_extension']],
    ['label' => $ui['json'], 'ok' => extension_loaded('json'), 'detail' => extension_loaded('json') ? $ui['enabled'] : $ui['missing_extension']],
    ['label' => $ui['session'], 'ok' => ($_SESSION['installer_probe'] ?? null) === $sessionProbe, 'detail' => $ui['session_detail']],
    ['label' => $ui['config_writable'], 'ok' => is_dir($root . '/config') && is_writable($root . '/config'), 'detail' => is_writable($root . '/config') ? $ui['directory_writable'] : $ui['directory_not_writable']],
    ['label' => $ui['install_sql'], 'ok' => is_file(__DIR__ . '/install.sql'), 'detail' => is_file(__DIR__ . '/install.sql') ? $ui['file_found'] : $ui['file_missing']],
    ['label' => $ui['access_protection'], 'ok' => is_file($root . '/config/.htaccess'), 'detail' => is_file($root . '/config/.htaccess') ? $ui['protection_found'] : $ui['protection_missing']],
];
$environmentReady = !in_array(false, array_column($checks, 'ok'), true);
$error = '';
$dbForm = [
    'host' => is_string($_POST['db_host'] ?? null) ? trim($_POST['db_host']) : (string) ($_SESSION['installer_database']['host'] ?? 'localhost'),
    'port' => is_string($_POST['db_port'] ?? null) ? trim($_POST['db_port']) : (string) ($_SESSION['installer_database']['port'] ?? '3306'),
    'name' => is_string($_POST['db_name'] ?? null) ? trim($_POST['db_name']) : (string) ($_SESSION['installer_database']['name'] ?? ''),
    'user' => is_string($_POST['db_user'] ?? null) ? trim($_POST['db_user']) : (string) ($_SESSION['installer_database']['user'] ?? ''),
];
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
if ($requestMethod === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($postedToken) || !hash_equals($csrfToken, $postedToken)) {
        http_response_code(403);
        $error = $ui['csrf_error'];
    } elseif ($action === 'continue' && $environmentReady && !$installed) {
        $_SESSION['installer_step'] = 'database';
        header('Location: ?step=database&language=' . rawurlencode($language));
        exit;
    } elseif ($action === 'test_database' && !$installed) {
        $_SESSION['installer_step'] = 'database';
        $password = is_string($_POST['db_password'] ?? null) ? $_POST['db_password'] : '';
        $port = filter_var($dbForm['port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $valid = preg_match('/\A[a-zA-Z0-9._:\[\]-]+\z/', $dbForm['host']) === 1
            && $port !== false
            && preg_match('/\A[a-zA-Z0-9_$-]+\z/', $dbForm['name']) === 1
            && $dbForm['user'] !== ''
            && strlen($dbForm['user']) <= 128
            && strlen($password) <= 1024;
        if (!$valid) {
            $error = $ui['db_invalid'];
        } else {
            $dsn = 'mysql:host=' . $dbForm['host'] . ';port=' . $port . ';dbname=' . $dbForm['name'] . ';charset=utf8mb4';
            try {
                $pdo = new PDO($dsn, $dbForm['user'], $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                $pdo->query('SELECT 1')->fetchColumn();
                $_SESSION['installer_database'] = $dbForm + ['port' => (string) $port, 'password' => $password, 'dsn' => $dsn];
                $_SESSION['installer_step'] = 'basic';
                header('Location: ?step=basic&language=' . rawurlencode($language));
                exit;
            } catch (Throwable $connectionError) {
                error_log('Pollyanna installer database connection failed: ' . get_class($connectionError));
                $error = $ui['db_failed'];
            }
        }
    } elseif ($action === 'save_basic' && !$installed && isset($_SESSION['installer_database'])) {
        $_SESSION['installer_step'] = 'basic';
        $timezone = is_string($_POST['timezone'] ?? null) ? $_POST['timezone'] : '';
        $password = is_string($_POST['admin_password'] ?? null) ? $_POST['admin_password'] : '';
        $confirmation = is_string($_POST['admin_password_confirmation'] ?? null) ? $_POST['admin_password_confirmation'] : '';
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $error = $ui['timezone_invalid'];
        } elseif (strlen($password) < 12 || strlen($password) > 1024) {
            $error = $ui['password_invalid'];
        } elseif (!hash_equals($password, $confirmation)) {
            $error = $ui['password_mismatch'];
        } else {
            $_SESSION['installer_basic'] = ['timezone' => $timezone, 'admin_password' => $password];
            $_SESSION['installer_step'] = 'confirmation';
            header('Location: ?step=confirmation&language=' . rawurlencode($language));
            exit;
        }
    } elseif ($action === 'install' && !$installed && isset($_SESSION['installer_database'], $_SESSION['installer_basic'])) {
        $_SESSION['installer_step'] = 'confirmation';
        $database = $_SESSION['installer_database'];
        $basic = $_SESSION['installer_basic'];
        try {
            if (!is_array($database) || !is_array($basic)) throw new RuntimeException('Installer state is invalid.');
            $pdo = new PDO((string) $database['dsn'], (string) $database['user'], (string) $database['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $schema = file_get_contents(__DIR__ . '/install.sql');
            if ($schema === false) throw new RuntimeException('Schema file could not be read.');
            $statements = preg_split('/;\s*(?:\r?\n|\z)/', $schema, -1, PREG_SPLIT_NO_EMPTY);
            if (!is_array($statements) || $statements === []) throw new RuntimeException('Schema file is empty.');
            foreach ($statements as $statement) {
                if (trim($statement) !== '') $pdo->exec($statement);
            }
            $adminHash = password_hash((string) $basic['admin_password'], PASSWORD_DEFAULT);
            if (!is_string($adminHash)) throw new RuntimeException('Admin password could not be hashed.');
            $secret = bin2hex(random_bytes(32));
            installer_atomic_write(
                $credentialsPath,
                json_encode(['password_hash' => $adminHash], JSON_THROW_ON_ERROR) . PHP_EOL
            );
            installer_atomic_write(
                $configPath,
                installer_config_contents($database, (string) $basic['timezone'], $secret)
            );
            installer_atomic_write(
                $root . '/config/install_complete.json',
                json_encode(['completed' => true, 'installed_at' => gmdate('c')], JSON_THROW_ON_ERROR) . PHP_EOL
            );
            unset($_SESSION['installer_database'], $_SESSION['installer_basic'], $_SESSION['installer_step']);
            session_regenerate_id(true);
            header('Location: ?language=' . rawurlencode($language));
            exit;
        } catch (Throwable $installError) {
            error_log('Pollyanna installation failed: ' . get_class($installError));
            $error = $ui['install_failed'];
        }
    }
}
$availableSteps = ['environment'];
if (in_array($_SESSION['installer_step'] ?? '', ['database', 'basic', 'confirmation'], true)) $availableSteps[] = 'database';
if (isset($_SESSION['installer_database'])) $availableSteps[] = 'basic';
if (isset($_SESSION['installer_basic'])) $availableSteps[] = 'confirmation';
$requestedStep = is_string($_GET['step'] ?? null) ? $_GET['step'] : (string) ($_SESSION['installer_step'] ?? 'environment');
$step = $installed ? 'installed' : (in_array($requestedStep, $availableSteps, true) ? $requestedStep : 'environment');
$stepIndexes = ['environment' => 0, 'database' => 1, 'basic' => 2, 'confirmation' => 3];
$currentStepIndex = $stepIndexes[$step] ?? 0;
?>
<!doctype html>
<html lang="<?= installer_h($language) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= installer_h($ui['title']) ?></title>
    <link rel="stylesheet" href="../assets/installer.css">
</head>
<body>
<main class="installer-shell">
    <header class="installer-header">
        <div class="brand-line"><span class="heart" aria-hidden="true">♥</span><strong>Pollyanna</strong><span>— An Easy-to-Install Like Button System</span></div>
        <nav class="language-switch" aria-label="Language"><?php foreach ($messages as $code => $locale): ?><a href="?language=<?= installer_h($code) ?>" lang="<?= installer_h($code) ?>" <?= $language === $code ? 'aria-current="page"' : '' ?>><?= installer_h($locale['language_name']) ?></a><?php endforeach ?></nav>
    </header>
    <?php if (!$installed): ?><ol class="steps"><?php foreach ($ui['steps'] as $index => $label): ?><li class="<?= $index === $currentStepIndex ? 'current' : ($index < $currentStepIndex ? 'complete' : '') ?>"><span><?= $index + 1 ?></span><?= installer_h($label) ?></li><?php endforeach ?></ol><?php endif ?>
    <section class="installer-card">
        <?php if ($installed): ?>
            <?php if ($webInstallationComplete): ?>
                <h1><?= installer_h($ui['remove_installer_title']) ?></h1>
                <p><?= installer_h($ui['remove_installer_text']) ?></p>
                <div class="message warning"><strong><?= installer_h($ui['remove_installer_path']) ?></strong></div>
                <p><?= installer_h($ui['admin_locked']) ?></p>
                <div class="completion-links"><a class="secondary-link" href="../README.md" target="_blank" rel="noopener noreferrer"><?= installer_h($ui['read_documentation']) ?></a><a class="secondary-link" href="../tests/like-button-test.html" target="_blank" rel="noopener noreferrer"><?= installer_h($ui['view_widget_example']) ?></a></div>
            <?php else: ?>
                <h1><?= installer_h($ui['installed_title']) ?></h1>
                <p><?= installer_h($ui['installed_text']) ?></p>
                <a class="primary-link" href="../admin/"><?= installer_h($ui['admin_link']) ?></a>
            <?php endif ?>
        <?php elseif ($step === 'environment'): ?>
            <h1><?= installer_h($ui['environment_title']) ?></h1>
            <p><?= installer_h($ui['environment_intro']) ?></p>
            <?php if ($error !== ''): ?><div class="message error" role="alert"><?= installer_h($error) ?></div><?php endif ?>
            <div class="check-table" role="table">
                <div class="check-row check-head" role="row"><span role="columnheader"><?= installer_h($ui['requirement']) ?></span><span role="columnheader"><?= installer_h($ui['result']) ?></span><span role="columnheader"><?= installer_h($ui['detail']) ?></span></div>
                <?php foreach ($checks as $check): ?><div class="check-row" role="row"><strong role="cell"><?= installer_h($check['label']) ?></strong><span role="cell" class="status <?= $check['ok'] ? 'ok' : 'failed' ?>"><?= installer_h($check['ok'] ? $ui['ok'] : $ui['failed']) ?></span><span role="cell"><?= installer_h($check['detail']) ?></span></div><?php endforeach ?>
            </div>
            <div class="message <?= $environmentReady ? 'success' : 'warning' ?>"><?= installer_h($environmentReady ? $ui['ready'] : $ui['not_ready']) ?></div>
            <?php if ($environmentReady): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= installer_h($csrfToken) ?>"><input type="hidden" name="language" value="<?= installer_h($language) ?>"><input type="hidden" name="action" value="continue"><button class="primary" type="submit"><?= installer_h($ui['continue']) ?></button></form><?php else: ?><a class="secondary-link" href="?language=<?= installer_h($language) ?>"><?= installer_h($ui['reload']) ?></a><?php endif ?>
        <?php elseif ($step === 'database'): ?>
            <h1><?= installer_h($ui['database_title']) ?></h1>
            <p><?= installer_h($ui['database_intro']) ?></p>
            <?php if ($error !== ''): ?><div class="message error" role="alert"><?= installer_h($error) ?></div><?php endif ?>
            <form class="installer-form" method="post"><input type="hidden" name="csrf_token" value="<?= installer_h($csrfToken) ?>"><input type="hidden" name="language" value="<?= installer_h($language) ?>"><input type="hidden" name="action" value="test_database">
                <div class="form-grid"><label><?= installer_h($ui['db_host']) ?><input name="db_host" type="text" value="<?= installer_h($dbForm['host']) ?>" maxlength="255" required autocomplete="off"></label><label><?= installer_h($ui['db_port']) ?><input name="db_port" type="number" value="<?= installer_h($dbForm['port']) ?>" min="1" max="65535" required></label></div>
                <label><?= installer_h($ui['db_name']) ?><input name="db_name" type="text" value="<?= installer_h($dbForm['name']) ?>" maxlength="64" required autocomplete="off"></label>
                <label><?= installer_h($ui['db_user']) ?><input name="db_user" type="text" value="<?= installer_h($dbForm['user']) ?>" maxlength="128" required autocomplete="username"></label>
                <label><?= installer_h($ui['db_password']) ?><input name="db_password" type="password" maxlength="1024" autocomplete="new-password"></label><p class="form-help"><?= installer_h($ui['db_password_help']) ?></p>
                <div class="form-actions"><a class="secondary-link" href="?step=environment&amp;language=<?= installer_h($language) ?>"><?= installer_h($ui['back']) ?></a><button class="primary" type="submit"><?= installer_h($ui['db_connect']) ?></button></div>
            </form>
        <?php elseif ($step === 'basic'): ?>
            <h1><?= installer_h($ui['basic_title']) ?></h1>
            <p><?= installer_h($ui['basic_intro']) ?></p>
            <?php if ($error !== ''): ?><div class="message error" role="alert"><?= installer_h($error) ?></div><?php endif ?>
            <form class="installer-form" method="post"><input type="hidden" name="csrf_token" value="<?= installer_h($csrfToken) ?>"><input type="hidden" name="language" value="<?= installer_h($language) ?>"><input type="hidden" name="action" value="save_basic">
                <label><?= installer_h($ui['timezone']) ?><select name="timezone" required><?php $savedTimezone = is_array($_SESSION['installer_basic'] ?? null) ? (string) ($_SESSION['installer_basic']['timezone'] ?? 'Asia/Tokyo') : 'Asia/Tokyo'; foreach (DateTimeZone::listIdentifiers() as $timezone): ?><option value="<?= installer_h($timezone) ?>" <?= $timezone === $savedTimezone ? 'selected' : '' ?>><?= installer_h($timezone) ?></option><?php endforeach ?></select></label>
                <label><?= installer_h($ui['admin_password']) ?><input name="admin_password" type="password" minlength="12" maxlength="1024" required autocomplete="new-password" data-installer-password></label>
                <label><?= installer_h($ui['admin_password_confirmation']) ?><input name="admin_password_confirmation" type="password" minlength="12" maxlength="1024" required autocomplete="new-password" data-installer-password></label>
                <p class="form-help"><?= installer_h($ui['password_requirement']) ?></p><label class="check-label"><input type="checkbox" data-installer-password-toggle> <?= installer_h($ui['show_password']) ?></label>
                <div class="form-actions"><a class="secondary-link" href="?step=database&amp;language=<?= installer_h($language) ?>"><?= installer_h($ui['back']) ?></a><button class="primary" type="submit"><?= installer_h($ui['basic_continue']) ?></button></div>
            </form>
        <?php elseif ($step === 'confirmation'): ?>
            <h1><?= installer_h($ui['confirmation_title']) ?></h1>
            <p><?= installer_h($ui['confirmation_intro']) ?></p>
            <?php if ($error !== ''): ?><div class="message error" role="alert"><?= installer_h($error) ?></div><?php endif ?>
            <?php $database = $_SESSION['installer_database']; $basic = $_SESSION['installer_basic']; ?>
            <dl class="summary-list"><div><dt><?= installer_h($ui['database_server']) ?></dt><dd><?= installer_h((string) $database['host'] . ':' . (string) $database['port']) ?></dd></div><div><dt><?= installer_h($ui['database_name']) ?></dt><dd><?= installer_h((string) $database['name']) ?></dd></div><div><dt><?= installer_h($ui['database_user']) ?></dt><dd><?= installer_h((string) $database['user']) ?></dd></div><div><dt><?= installer_h($ui['timezone']) ?></dt><dd><?= installer_h((string) $basic['timezone']) ?></dd></div></dl>
            <ul class="install-summary"><li><?= installer_h($ui['create_tables']) ?></li><li><?= installer_h($ui['generate_secret']) ?></li><li><?= installer_h($ui['save_admin_password']) ?></li><li><strong><?= installer_h($ui['lock_admin']) ?></strong></li></ul>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= installer_h($csrfToken) ?>"><input type="hidden" name="language" value="<?= installer_h($language) ?>"><input type="hidden" name="action" value="install"><div class="form-actions"><a class="secondary-link" href="?step=basic&amp;language=<?= installer_h($language) ?>"><?= installer_h($ui['back']) ?></a><button class="primary" type="submit"><?= installer_h($ui['install_button']) ?></button></div></form>
        <?php endif ?>
    </section>
</main>
<script src="../assets/installer.js"></script>
</body>
</html>
