<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$configPath = $root . '/config/config.php';
$credentialsPath = $root . '/config/admin_credentials.json';
$completionPath = $root . '/config/install_complete.json';
require_once $root . '/src/OriginPolicy.php';
require_once $root . '/src/InstallerState.php';
require_once $root . '/src/AdminPasswordPolicy.php';
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
        'view_widget_example' => 'ログイン後に動作テストを開く（install削除後）',
        'admin_link' => '管理画面へ',
        'welcome_title' => 'Pollyannaへようこそ！',
        'welcome_intro' => 'インストールを始める前に、データベース（データを保存しておく入れ物）の準備をします。',
        'welcome_flow' => 'この後の画面では「環境確認 → データベース接続 → 基本設定」の順に進みます。',
        'preparation_title' => 'インストール前の事前準備',
        'preparation_location' => 'お使いのレンタルサーバーやホスティングサービスの管理画面（「MySQL」「データベース」などのメニュー）で、次の作業を行ってください。',
        'preparation_1_title' => 'データベースを1つ作る',
        'preparation_1_body' => 'Pollyanna専用の、中身が空のデータベースを作成します。他のサイトと共用せず、専用にするのが安全です。',
        'preparation_2_title' => 'DBユーザーを用意する',
        'preparation_2_body' => 'データベースを操作するための「ログイン用アカウント」です。新しく作るか、すでにあるものを使います。',
        'preparation_3_title' => 'ユーザーに権限を付ける',
        'preparation_3_assignment' => '手順1で作ったデータベースに、手順2のユーザーを割り当ててください。',
        'preparation_3_permissions' => '「テーブルの作成・読み取り・追加・更新・削除」ができる権限を付けます。',
        'preparation_3_tip' => '迷ったら、Pollyanna専用データベースに対する「すべての権限」を選べばOKです。',
        'preparation_4_title' => '次の5つの情報をメモする',
        'preparation_4_body' => 'インストール中に入力するので、管理画面の表示をそのままメモしてください。',
        'database_memo_items' => ['DBホスト（例：localhost）', 'ポート番号（例：3306）', 'DB名', 'DBユーザー名', 'DBパスワード'],
        'preparation_note' => 'データベースは空のままで大丈夫です。テーブルの作成、初期データの登録、設定ファイルの作成は、インストーラーが自動で行います。',
        'preparation_caution_title' => '名前は管理画面の表示をそのままコピーしてください。',
        'preparation_caution' => 'サーバーによっては、入力した名前の前に自動で文字が付きます（例：pollyanna と入力しても、実際は abc123_pollyanna）。前に付いた文字も含めて、そのままコピーしてください。',
        'start_environment' => '環境確認を始める',
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
        'log_writable' => 'logディレクトリへ書き込み可能',
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
        'log_directory_writable' => 'ログと集計アーカイブを保存できます。',
        'log_directory_not_writable' => 'logディレクトリを配置し、Webサーバーから書き込める権限を設定してください。',
        'ready' => '必要な環境を確認できました。次はデータベース接続を設定します。',
        'not_ready' => '要確認の項目を修正してから、ページを再読み込みしてください。',
        'continue' => 'データベース設定へ',
        'reload' => '再確認',
        'csrf_error' => '確認トークンが一致しません。ページを再読み込みしてください。',
        'database_title' => 'データベース設定',
        'database_intro' => 'Pollyannaが使用するMySQLまたはMariaDBの接続情報を入力してください。',
        'database_guide_title' => '入力前の準備',
        'database_guide_1' => 'レンタルサーバーやホスティングサービスの管理画面を開き、Pollyanna専用の空のデータベースを1つ作成します。',
        'database_guide_2' => 'そのデータベースを操作するユーザーを作成または選択し、対象DBのテーブル作成・読み書き・更新・削除を許可します。',
        'database_guide_3' => '管理画面に表示されたDBホスト、DB名、ユーザー名、パスワードを、この画面へ入力します。',
        'database_guide_note' => '空のデータベースだけ用意してください。テーブル作成、SQLの貼り付け、秘密鍵や設定ファイルの作成はインストーラーが自動で行います。',
        'database_provider_note' => 'サービスによってはDB名やユーザー名の先頭へ契約アカウント名が自動で付きます。表示された完全な名前を入力してください。DBホストがlocalhost以外に指定されている場合も、サービス側の表示を優先してください。',
        'db_host' => 'DBホスト',
        'db_host_help' => 'サーバー管理画面に記載されたホスト名です。例：localhost、mysql.example.jp',
        'db_port' => 'ポート',
        'db_port_help' => '指定がなければ通常は3306です。サーバー会社から別の番号を案内されている場合だけ変更してください。',
        'db_name' => 'DB名',
        'db_name_help' => '事前に作成した空DBの完全な名前です。既存サイトが使用中のDBは指定しないでください。',
        'db_user' => 'ユーザー名',
        'db_user_help' => '上記DBへの操作権限を付けたDBユーザー名です。WebサイトやFTPのログイン名とは異なる場合があります。',
        'db_password' => 'パスワード',
        'db_password_help' => 'DBユーザーに設定したパスワードです。接続確認後は画面に再表示せず、確認画面にも表示しません。',
        'db_connect' => '接続を確認して次へ',
        'db_invalid' => 'DB接続情報の入力形式を確認してください。',
        'db_failed' => 'データベースへ接続できませんでした。ホスト、DB名、ユーザー名、パスワードを確認してください。',
        'basic_title' => '基本設定',
        'basic_intro' => '集計に使うタイムゾーンと、管理画面のパスワードを設定します。',
        'site_origin' => 'Pollyanna本体Origin',
        'site_origin_help' => 'Pollyannaを設置するWebサイトの scheme・host・port です。表示候補を確認し、必要なら修正してください。パスは含めません。',
        'site_base_domain' => '本体基準ドメイン',
        'site_base_domain_help' => '本体サイト群として扱うhostだけを入力します。自動候補は必ず管理者が確認してください。',
        'site_subdomains' => 'このドメインのサブドメインからの利用も許可する',
        'origin_invalid' => '本体Originと本体基準ドメインの形式、および両者の対応を確認してください。基準ドメインは本体Originのhost自身か、その親ドメインにしてください。',
        'timezone' => '集計タイムゾーン',
        'admin_password' => '管理パスワード',
        'admin_password_confirmation' => '管理パスワード（確認）',
        'password_requirement' => '12 Unicode文字以上、UTF-8で72バイト以内。空白はそのまま扱い、パスワードのハッシュだけを保存します。',
        'show_password' => 'パスワードを表示',
        'basic_continue' => '実行確認へ',
        'password_invalid' => '管理パスワードは12 Unicode文字以上、UTF-8で72バイト以内の有効な文字列を入力してください。NUL文字は使用できません。',
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
        'install_failed' => 'インストールを完了できませんでした。入力内容とサーバー設定を確認して、画面を再読み込みし、途中状態の案内を確認してください。進行マーカーは時間経過だけで削除しないでください。DBパスワードや管理パスワードはログへ出力していません。',
        'partial_title' => 'インストールが途中まで完了しています',
        'partial_text' => '設定とデータを保護するため、新規インストールは開始できません。保存済みの成果物を確認し、最後の完了処理だけを再試行できます。',
        'recovery_stopped' => '以前のインストール実行が停止していることを確認しました。',
        'partial_recover' => '完了処理を再試行',
        'partial_failed' => '自動復旧できませんでした。完了マーカーは作成しておらず、既存のconfig.php、管理認証情報、DBも変更していません。config.php、admin_credentials.json、DB接続、および必要なPollyannaテーブルを確認してください。',
        'steps' => ['ようこそ', '環境確認', 'DB設定', '基本設定', '実行確認', 'インストール', '完了'],
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
        'view_widget_example' => 'Open authenticated widget test (after deleting install/)',
        'admin_link' => 'Go to Admin',
        'welcome_title' => 'Welcome to Pollyanna!',
        'welcome_intro' => 'Before starting the installation, prepare a database—the container where Pollyanna stores its data.',
        'welcome_flow' => 'The following screens proceed through Environment check → Database connection → Basic settings.',
        'preparation_title' => 'Before installation',
        'preparation_location' => 'Open the “MySQL,” “Database,” or similarly named section of your hosting control panel, then complete these steps.',
        'preparation_1_title' => 'Create one database',
        'preparation_1_body' => 'Create an empty database dedicated to Pollyanna. Keeping it separate from other websites is safer.',
        'preparation_2_title' => 'Prepare a database user',
        'preparation_2_body' => 'This is the login account used to operate the database. Create a new user or use an existing one.',
        'preparation_3_title' => 'Grant the user permissions',
        'preparation_3_assignment' => 'Assign the user from step 2 to the database from step 1.',
        'preparation_3_permissions' => 'Grant permission to create tables and read, insert, update, and delete data.',
        'preparation_3_tip' => 'If you are unsure, select “all privileges” for the dedicated Pollyanna database.',
        'preparation_4_title' => 'Write down these five values',
        'preparation_4_body' => 'You will enter them during installation, so copy them exactly as shown in the control panel.',
        'database_memo_items' => ['Database host (example: localhost)', 'Port number (example: 3306)', 'Database name', 'Database username', 'Database password'],
        'preparation_note' => 'The database may remain empty. The installer creates the tables, inserts the initial data, and writes the configuration files automatically.',
        'preparation_caution_title' => 'Copy names in their complete form',
        'preparation_caution' => 'Some providers automatically add characters before the name you enter. For example, pollyanna may become abc123_pollyanna. Copy the entire displayed name, including the added characters.',
        'start_environment' => 'Start environment check',
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
        'log_writable' => 'Writable log directory',
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
        'log_directory_writable' => 'Logs and aggregation archives can be saved.',
        'log_directory_not_writable' => 'Upload the log directory and grant the web server write access to it.',
        'ready' => 'The required environment is ready. Database configuration comes next.',
        'not_ready' => 'Fix the items marked for review, then reload this page.',
        'continue' => 'Database settings',
        'reload' => 'Check again',
        'csrf_error' => 'The verification token did not match. Reload the page.',
        'database_title' => 'Database settings',
        'database_intro' => 'Enter the MySQL or MariaDB connection used by Pollyanna.',
        'database_guide_title' => 'Before entering the connection',
        'database_guide_1' => 'Open your hosting control panel and create one empty database dedicated to Pollyanna.',
        'database_guide_2' => 'Create or select a database user and grant it permission to create, read, update, and delete tables in that database.',
        'database_guide_3' => 'Copy the database host, database name, username, and password shown by your hosting provider into this screen.',
        'database_guide_note' => 'You only need to prepare an empty database. The installer creates the tables, runs the schema, generates the secret, and writes the configuration files.',
        'database_provider_note' => 'Some providers automatically prefix database names and usernames with your account name. Enter the complete displayed value. If your provider specifies a host other than localhost, use that host.',
        'db_host' => 'Database host',
        'db_host_help' => 'The hostname shown in your hosting control panel, such as localhost or mysql.example.com.',
        'db_port' => 'Port',
        'db_port_help' => 'Use 3306 unless your hosting provider gives you a different port.',
        'db_name' => 'Database name',
        'db_name_help' => 'The complete name of the empty database you created. Do not use a database already used by another site.',
        'db_user' => 'Username',
        'db_user_help' => 'The database user granted access to the database above. This may differ from your website or FTP login.',
        'db_password' => 'Password',
        'db_password_help' => 'The password assigned to the database user. It is not redisplayed after verification or shown on the review screen.',
        'db_connect' => 'Verify connection and continue',
        'db_invalid' => 'Check the database connection value formats.',
        'db_failed' => 'Could not connect to the database. Check the host, database name, username, and password.',
        'basic_title' => 'Basic settings',
        'basic_intro' => 'Set the reporting timezone and admin password.',
        'site_origin' => 'Pollyanna origin',
        'site_origin_help' => 'The scheme, host, and port of the website hosting Pollyanna. Confirm the candidate and edit it if needed. Do not include a path.',
        'site_base_domain' => 'Base domain',
        'site_base_domain_help' => 'Enter only the host to treat as the primary site group. An administrator must confirm the suggested value.',
        'site_subdomains' => 'Also allow this domain and its subdomains',
        'origin_invalid' => 'Check the Pollyanna origin, base domain, and their relationship. The base domain must be the origin host itself or one of its parent domains.',
        'timezone' => 'Aggregation timezone',
        'admin_password' => 'Admin password',
        'admin_password_confirmation' => 'Confirm admin password',
        'password_requirement' => 'Use at least 12 Unicode code points and at most 72 UTF-8 bytes. Spaces are preserved. Only the password hash is stored.',
        'show_password' => 'Show passwords',
        'basic_continue' => 'Review installation',
        'password_invalid' => 'Use valid UTF-8 with at least 12 Unicode code points, at most 72 bytes, and no NUL characters.',
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
        'install_failed' => 'Installation could not be completed. Check the submitted values and server configuration, then reload to review the partial state. Never remove a progress marker merely because time has passed. Database and admin passwords were not written to the log.',
        'partial_title' => 'Installation is partially complete',
        'partial_text' => 'A new installation cannot start because the saved configuration and data must be protected. You can verify the existing artifacts and retry only the final completion step.',
        'recovery_stopped' => 'I have confirmed that the previous installer stopped.',
        'partial_recover' => 'Retry completion',
        'partial_failed' => 'Automatic recovery failed. No completion marker was created, and the existing config.php, administrator credentials, and database were not changed. Check config.php, admin_credentials.json, the database connection, and the required Pollyanna tables.',
        'steps' => ['Welcome', 'Environment', 'Database', 'Basic settings', 'Confirmation', 'Install', 'Complete'],
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

function installer_config_contents(array $database, array $basic, string $secret): string
{
    $config = [
        'database' => [
            'dsn' => $database['dsn'],
            'username' => $database['user'],
            'password' => $database['password'],
        ],
        'app_secret' => $secret,
        'site_origin' => $basic['site_origin'],
        'site_base_domain' => $basic['site_base_domain'],
        'site_base_domain_include_subdomains' => $basic['site_base_domain_include_subdomains'],
        'system_state' => 'enabled',
        'access_protection' => ['enabled' => true, 'auto_block' => true,
            'state' => ['window' => 60, 'threshold' => 2400],
            'like' => ['window' => 60, 'threshold' => 240]],
        'admin_language' => 'ja',
        'admin_theme' => 'green',
        'widget_language' => 'ja',
        'click_success_message' => 'like02',
        'show_like_count' => false,
        'like_count_color' => '#767676',
        'button_symbol_preset' => 'heart',
        'button_unliked_color' => '#666666',
        'button_liked_color' => '#e53955',
        'count_refresh_interval' => 5,
        'likes_per_24_hours' => 3,
        'aggregation_start_threshold' => 1000,
        'aggregation_batch_size' => 500,
        'timezone' => $basic['timezone'],
    ];
    return "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
}

$installationState = PollyannaInstallerState::detect($configPath, $credentialsPath, $completionPath);
$installed = $installationState === PollyannaInstallerState::COMPLETE;
$partialInstallation = $installationState === PollyannaInstallerState::PARTIAL;
$canInstall = $installationState === PollyannaInstallerState::UNINSTALLED;
$webInstallationComplete = $installed;
$sessionProbe = bin2hex(random_bytes(8));
$_SESSION['installer_probe'] = $sessionProbe;
$checks = [
    ['label' => $ui['php_version'], 'ok' => version_compare(PHP_VERSION, '8.1.0', '>='), 'detail' => sprintf($ui['php_detail'], PHP_VERSION)],
    ['label' => $ui['pdo_mysql'], 'ok' => extension_loaded('pdo_mysql'), 'detail' => extension_loaded('pdo_mysql') ? $ui['enabled'] : $ui['missing_extension']],
    ['label' => $ui['json'], 'ok' => extension_loaded('json'), 'detail' => extension_loaded('json') ? $ui['enabled'] : $ui['missing_extension']],
    ['label' => $ui['session'], 'ok' => ($_SESSION['installer_probe'] ?? null) === $sessionProbe, 'detail' => $ui['session_detail']],
    ['label' => $ui['config_writable'], 'ok' => is_dir($root . '/config') && is_writable($root . '/config'), 'detail' => is_writable($root . '/config') ? $ui['directory_writable'] : $ui['directory_not_writable']],
    ['label' => $ui['log_writable'], 'ok' => PollyannaInstallerState::isWritableDirectory($root . '/log'), 'detail' => PollyannaInstallerState::isWritableDirectory($root . '/log') ? $ui['log_directory_writable'] : $ui['log_directory_not_writable']],
    ['label' => $ui['install_sql'], 'ok' => is_file(__DIR__ . '/install.sql'), 'detail' => is_file(__DIR__ . '/install.sql') ? $ui['file_found'] : $ui['file_missing']],
    ['label' => $ui['access_protection'], 'ok' => is_file($root . '/config/.htaccess'), 'detail' => is_file($root . '/config/.htaccess') ? $ui['protection_found'] : $ui['protection_missing']],
];
$environmentReady = !in_array(false, array_column($checks, 'ok'), true);
$error = '';
$candidateScheme = $isHttps ? 'https' : 'http';
$candidateHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
try {
    $candidateOriginObject = PollyannaOrigin::parse($candidateScheme . '://' . $candidateHost);
    $candidateOrigin = $candidateOriginObject->serialize();
    $candidateBaseDomain = $candidateOriginObject->host;
    if (str_starts_with($candidateBaseDomain, 'www.') && substr_count($candidateBaseDomain, '.') >= 2) $candidateBaseDomain = substr($candidateBaseDomain, 4);
} catch (Throwable) {
    $candidateOrigin = '';
    $candidateBaseDomain = '';
}
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
    } elseif ($action === 'recover_installation' && $partialInstallation) {
        try {
            PollyannaInstallerState::recover($configPath, $credentialsPath, $completionPath, ($_POST['installer_stopped'] ?? '') === '1');
            header('Location: ?language=' . rawurlencode($language));
            exit;
        } catch (Throwable $recoveryError) {
            error_log('Pollyanna installation recovery failed: ' . get_class($recoveryError));
            $error = $ui['partial_failed'];
        }
    } elseif ($action === 'start_environment' && $canInstall) {
        $_SESSION['installer_step'] = 'environment';
        header('Location: ?step=environment&language=' . rawurlencode($language));
        exit;
    } elseif ($action === 'continue' && $environmentReady && $canInstall) {
        $_SESSION['installer_step'] = 'database';
        header('Location: ?step=database&language=' . rawurlencode($language));
        exit;
    } elseif ($action === 'test_database' && $canInstall) {
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
    } elseif ($action === 'save_basic' && $canInstall && isset($_SESSION['installer_database'])) {
        $_SESSION['installer_step'] = 'basic';
        $timezone = is_string($_POST['timezone'] ?? null) ? $_POST['timezone'] : '';
        $password = is_string($_POST['admin_password'] ?? null) ? $_POST['admin_password'] : '';
        $confirmation = is_string($_POST['admin_password_confirmation'] ?? null) ? $_POST['admin_password_confirmation'] : '';
        $siteOriginInput = is_string($_POST['site_origin'] ?? null) ? trim($_POST['site_origin']) : '';
        $baseDomainInput = is_string($_POST['site_base_domain'] ?? null) ? trim($_POST['site_base_domain']) : '';
        $includeSubdomains = ($_POST['site_base_domain_include_subdomains'] ?? '') === '1';
        try {
            $siteSettings = PollyannaOrigin::normalizeSiteSettings($siteOriginInput, $baseDomainInput, $includeSubdomains);
            $siteOriginObject = $siteSettings['origin'];
            $baseDomain = $siteSettings['base_domain'];
            $includeSubdomains = $siteSettings['include_subdomains'];
        } catch (InvalidArgumentException) {
            $siteOriginObject = null;
            $baseDomain = '';
        }
        if (!$siteOriginObject instanceof PollyannaOrigin || $baseDomain === '') {
            $error = $ui['origin_invalid'];
        } elseif (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $error = $ui['timezone_invalid'];
        } elseif (!AdminPasswordPolicy::isValid($password)) {
            $error = $ui['password_invalid'];
        } elseif (!hash_equals($password, $confirmation)) {
            $error = $ui['password_mismatch'];
        } else {
            $_SESSION['installer_basic'] = ['timezone' => $timezone, 'admin_password' => $password, 'site_origin' => $siteOriginObject->serialize(), 'site_base_domain' => $baseDomain, 'site_base_domain_include_subdomains' => $includeSubdomains];
            $_SESSION['installer_step'] = 'confirmation';
            header('Location: ?step=confirmation&language=' . rawurlencode($language));
            exit;
        }
    } elseif ($action === 'install' && $canInstall && isset($_SESSION['installer_database'], $_SESSION['installer_basic'])) {
        $_SESSION['installer_step'] = 'confirmation';
        $database = $_SESSION['installer_database'];
        $basic = $_SESSION['installer_basic'];
        try {
            PollyannaInstallerState::install($configPath, $credentialsPath, $completionPath, static function () use ($database, $basic, $configPath, $credentialsPath, $completionPath): void {
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
                $adminHash = AdminPasswordPolicy::hash((string) $basic['admin_password']);
                if (!is_string($adminHash)) throw new RuntimeException('Admin password could not be hashed.');
                $secret = bin2hex(random_bytes(32));
                PollyannaInstallerState::createExclusive(
                    $credentialsPath,
                    json_encode(['password_hash' => $adminHash], JSON_THROW_ON_ERROR) . PHP_EOL
                );
                PollyannaInstallerState::createExclusive(
                    $configPath,
                    installer_config_contents($database, $basic, $secret)
                );
                PollyannaInstallerState::writeCompletionMarker($completionPath);
            });
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
$availableSteps = ['welcome'];
if (in_array($_SESSION['installer_step'] ?? '', ['environment', 'database', 'basic', 'confirmation'], true)) $availableSteps[] = 'environment';
if (in_array($_SESSION['installer_step'] ?? '', ['database', 'basic', 'confirmation'], true)) $availableSteps[] = 'database';
if (isset($_SESSION['installer_database'])) $availableSteps[] = 'basic';
if (isset($_SESSION['installer_basic'])) $availableSteps[] = 'confirmation';
$requestedStep = is_string($_GET['step'] ?? null) ? $_GET['step'] : (string) ($_SESSION['installer_step'] ?? 'welcome');
$step = $installed ? 'installed' : ($partialInstallation ? 'partial' : (in_array($requestedStep, $availableSteps, true) ? $requestedStep : 'welcome'));
$stepIndexes = ['welcome' => 0, 'environment' => 1, 'database' => 2, 'basic' => 3, 'confirmation' => 4];
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
    <?php if (!$installed && !$partialInstallation): ?><ol class="steps"><?php foreach ($ui['steps'] as $index => $label): ?><li class="<?= $index === $currentStepIndex ? 'current' : ($index < $currentStepIndex ? 'complete' : '') ?>"><span><?= $index + 1 ?></span><?= installer_h($label) ?></li><?php endforeach ?></ol><?php endif ?>
    <section class="installer-card">
        <?php if ($installed): ?>
            <?php if ($webInstallationComplete): ?>
                <h1><?= installer_h($ui['remove_installer_title']) ?></h1>
                <p><?= installer_h($ui['remove_installer_text']) ?></p>
                <div class="message warning"><strong><?= installer_h($ui['remove_installer_path']) ?></strong></div>
                <p><?= installer_h($ui['admin_locked']) ?></p>
                <div class="completion-links"><a class="primary-link" href="../admin/"><?= installer_h($ui['admin_link']) ?></a><a class="secondary-link" href="readme.php?language=<?= installer_h($language) ?>" target="_blank" rel="noopener noreferrer"><?= installer_h($ui['read_documentation']) ?></a><a class="secondary-link" href="../admin/?view=widget-test" target="_blank" rel="noopener noreferrer"><?= installer_h($ui['view_widget_example']) ?></a></div>
            <?php else: ?>
                <h1><?= installer_h($ui['installed_title']) ?></h1>
                <p><?= installer_h($ui['installed_text']) ?></p>
                <a class="primary-link" href="../admin/"><?= installer_h($ui['admin_link']) ?></a>
            <?php endif ?>
        <?php elseif ($step === 'partial'): ?>
            <h1><?= installer_h($ui['partial_title']) ?></h1>
            <p><?= installer_h($ui['partial_text']) ?></p>
            <?php if ($error !== ''): ?><div class="message error" role="alert"><?= installer_h($error) ?></div><?php endif ?>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= installer_h($csrfToken) ?>"><input type="hidden" name="language" value="<?= installer_h($language) ?>"><input type="hidden" name="action" value="recover_installation"><label class="check-label"><input type="checkbox" name="installer_stopped" value="1" required> <?= installer_h($ui['recovery_stopped']) ?></label><button class="primary" type="submit"><?= installer_h($ui['partial_recover']) ?></button></form>
        <?php elseif ($step === 'welcome'): ?>
            <h1><?= installer_h($ui['welcome_title']) ?></h1>
            <p><?= installer_h($ui['welcome_intro']) ?></p>
            <p><?= installer_h($ui['welcome_flow']) ?></p>
            <section class="setup-guide" aria-labelledby="preparation-title">
                <h2 id="preparation-title"><?= installer_h($ui['preparation_title']) ?></h2>
                <p><?= installer_h($ui['preparation_location']) ?></p>
                <ol class="preparation-steps">
                    <?php for ($preparationIndex = 1; $preparationIndex <= 4; $preparationIndex++): ?>
                        <li><strong><?= installer_h($ui['preparation_' . $preparationIndex . '_title']) ?></strong><?php if ($preparationIndex === 3): ?><p><?= installer_h($ui['preparation_3_assignment']) ?></p><p><?= installer_h($ui['preparation_3_permissions']) ?></p><p class="step-tip"><?= installer_h($ui['preparation_3_tip']) ?></p><?php else: ?><p><?= installer_h($ui['preparation_' . $preparationIndex . '_body']) ?></p><?php endif ?><?php if ($preparationIndex === 4): ?><ul><?php foreach ($ui['database_memo_items'] as $memoItem): ?><li><?= installer_h($memoItem) ?></li><?php endforeach ?></ul><?php endif ?></li>
                    <?php endfor ?>
                </ol>
                <p class="guide-emphasis"><?= installer_h($ui['preparation_note']) ?></p>
                <div class="preparation-caution"><strong><span class="warning-emoji" aria-hidden="true">⚠️</span> <?= installer_h($ui['preparation_caution_title']) ?></strong><p><?= installer_h($ui['preparation_caution']) ?></p></div>
            </section>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= installer_h($csrfToken) ?>"><input type="hidden" name="language" value="<?= installer_h($language) ?>"><input type="hidden" name="action" value="start_environment"><button class="primary" type="submit"><?= installer_h($ui['start_environment']) ?></button></form>
        <?php elseif ($step === 'environment'): ?>
            <h1><?= installer_h($ui['environment_title']) ?></h1>
            <p><?= installer_h($ui['environment_intro']) ?></p>
            <?php if ($error !== ''): ?><div class="message error" role="alert"><?= installer_h($error) ?></div><?php endif ?>
            <div class="check-table" role="table">
                <div class="check-row check-head" role="row"><span role="columnheader"><?= installer_h($ui['requirement']) ?></span><span role="columnheader"><?= installer_h($ui['result']) ?></span><span role="columnheader"><?= installer_h($ui['detail']) ?></span></div>
                <?php foreach ($checks as $check): ?><div class="check-row" role="row"><strong role="cell"><?= installer_h($check['label']) ?></strong><span role="cell" class="status <?= $check['ok'] ? 'ok' : 'failed' ?>"><?= installer_h($check['ok'] ? $ui['ok'] : $ui['failed']) ?></span><span role="cell"><?= installer_h($check['detail']) ?></span></div><?php endforeach ?>
            </div>
            <div class="message <?= $environmentReady ? 'success' : 'warning' ?>"><?= installer_h($environmentReady ? $ui['ready'] : $ui['not_ready']) ?></div>
            <?php if ($environmentReady): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= installer_h($csrfToken) ?>"><input type="hidden" name="language" value="<?= installer_h($language) ?>"><input type="hidden" name="action" value="continue"><div class="form-actions"><a class="secondary-link" href="?step=welcome&amp;language=<?= installer_h($language) ?>"><?= installer_h($ui['back']) ?></a><button class="primary" type="submit"><?= installer_h($ui['continue']) ?></button></div></form><?php else: ?><div class="form-actions"><a class="secondary-link" href="?step=welcome&amp;language=<?= installer_h($language) ?>"><?= installer_h($ui['back']) ?></a><a class="secondary-link" href="?step=environment&amp;language=<?= installer_h($language) ?>"><?= installer_h($ui['reload']) ?></a></div><?php endif ?>
        <?php elseif ($step === 'database'): ?>
            <h1><?= installer_h($ui['database_title']) ?></h1>
            <p><?= installer_h($ui['database_intro']) ?></p>
            <section class="setup-guide" aria-labelledby="database-guide-title"><h2 id="database-guide-title"><?= installer_h($ui['database_guide_title']) ?></h2><ol><li><?= installer_h($ui['database_guide_1']) ?></li><li><?= installer_h($ui['database_guide_2']) ?></li><li><?= installer_h($ui['database_guide_3']) ?></li></ol><p class="guide-emphasis"><?= installer_h($ui['database_guide_note']) ?></p><p><?= installer_h($ui['database_provider_note']) ?></p></section>
            <?php if ($error !== ''): ?><div class="message error" role="alert"><?= installer_h($error) ?></div><?php endif ?>
            <form class="installer-form" method="post"><input type="hidden" name="csrf_token" value="<?= installer_h($csrfToken) ?>"><input type="hidden" name="language" value="<?= installer_h($language) ?>"><input type="hidden" name="action" value="test_database">
                <div class="form-field"><label for="db-host"><?= installer_h($ui['db_host']) ?></label><p class="field-description"><?= installer_h($ui['db_host_help']) ?></p><input id="db-host" name="db_host" type="text" value="<?= installer_h($dbForm['host']) ?>" maxlength="255" required autocomplete="off"></div>
                <div class="form-field"><label for="db-port"><?= installer_h($ui['db_port']) ?></label><p class="field-description"><?= installer_h($ui['db_port_help']) ?></p><input id="db-port" name="db_port" type="number" value="<?= installer_h($dbForm['port']) ?>" min="1" max="65535" required></div>
                <div class="form-field"><label for="db-name"><?= installer_h($ui['db_name']) ?></label><p class="field-description"><?= installer_h($ui['db_name_help']) ?></p><input id="db-name" name="db_name" type="text" value="<?= installer_h($dbForm['name']) ?>" maxlength="64" required autocomplete="off"></div>
                <div class="form-field"><label for="db-user"><?= installer_h($ui['db_user']) ?></label><p class="field-description"><?= installer_h($ui['db_user_help']) ?></p><input id="db-user" name="db_user" type="text" value="<?= installer_h($dbForm['user']) ?>" maxlength="128" required autocomplete="username"></div>
                <div class="form-field"><label for="db-password"><?= installer_h($ui['db_password']) ?></label><p class="field-description"><?= installer_h($ui['db_password_help']) ?></p><input id="db-password" name="db_password" type="password" maxlength="1024" autocomplete="new-password"></div>
                <div class="form-actions"><a class="secondary-link" href="?step=environment&amp;language=<?= installer_h($language) ?>"><?= installer_h($ui['back']) ?></a><button class="primary" type="submit"><?= installer_h($ui['db_connect']) ?></button></div>
            </form>
        <?php elseif ($step === 'basic'): ?>
            <h1><?= installer_h($ui['basic_title']) ?></h1>
            <p><?= installer_h($ui['basic_intro']) ?></p>
            <?php if ($error !== ''): ?><div class="message error" role="alert"><?= installer_h($error) ?></div><?php endif ?>
            <form class="installer-form" method="post" data-password-rule="<?= installer_h($ui['password_requirement']) ?>"><input type="hidden" name="csrf_token" value="<?= installer_h($csrfToken) ?>"><input type="hidden" name="language" value="<?= installer_h($language) ?>"><input type="hidden" name="action" value="save_basic">
                <?php $savedBasic = is_array($_SESSION['installer_basic'] ?? null) ? $_SESSION['installer_basic'] : []; ?>
                <div class="form-field"><label for="site-origin"><?= installer_h($ui['site_origin']) ?></label><p class="field-description"><?= installer_h($ui['site_origin_help']) ?></p><input id="site-origin" name="site_origin" type="url" required value="<?= installer_h((string) ($savedBasic['site_origin'] ?? $candidateOrigin)) ?>" data-origin-host-source></div>
                <div class="form-field"><label for="site-base-domain"><?= installer_h($ui['site_base_domain']) ?></label><p class="field-description"><?= installer_h($ui['site_base_domain_help']) ?></p><input id="site-base-domain" name="site_base_domain" type="text" required value="<?= installer_h((string) ($savedBasic['site_base_domain'] ?? $candidateBaseDomain)) ?>"></div>
                <label class="check-label"><input type="checkbox" name="site_base_domain_include_subdomains" value="1" <?= (($savedBasic['site_base_domain_include_subdomains'] ?? true) === true) ? 'checked' : '' ?> data-subdomain-toggle> <?= installer_h($ui['site_subdomains']) ?></label>
                <label><?= installer_h($ui['timezone']) ?><select name="timezone" required><?php $savedTimezone = is_array($_SESSION['installer_basic'] ?? null) ? (string) ($_SESSION['installer_basic']['timezone'] ?? 'Asia/Tokyo') : 'Asia/Tokyo'; foreach (DateTimeZone::listIdentifiers() as $timezone): ?><option value="<?= installer_h($timezone) ?>" <?= $timezone === $savedTimezone ? 'selected' : '' ?>><?= installer_h($timezone) ?></option><?php endforeach ?></select></label>
                <label><?= installer_h($ui['admin_password']) ?><input name="admin_password" type="password" maxlength="72" data-new-admin-password required autocomplete="new-password" data-installer-password></label>
                <label><?= installer_h($ui['admin_password_confirmation']) ?><input name="admin_password_confirmation" type="password" maxlength="72" data-new-admin-password required autocomplete="new-password" data-installer-password></label>
                <p class="form-help"><?= installer_h($ui['password_requirement']) ?></p><label class="check-label"><input type="checkbox" data-installer-password-toggle> <?= installer_h($ui['show_password']) ?></label>
                <div class="form-actions"><a class="secondary-link" href="?step=database&amp;language=<?= installer_h($language) ?>"><?= installer_h($ui['back']) ?></a><button class="primary" type="submit"><?= installer_h($ui['basic_continue']) ?></button></div>
            </form>
        <?php elseif ($step === 'confirmation'): ?>
            <h1><?= installer_h($ui['confirmation_title']) ?></h1>
            <p><?= installer_h($ui['confirmation_intro']) ?></p>
            <?php if ($error !== ''): ?><div class="message error" role="alert"><?= installer_h($error) ?></div><?php endif ?>
            <?php $database = $_SESSION['installer_database']; $basic = $_SESSION['installer_basic']; ?>
            <dl class="summary-list"><div><dt><?= installer_h($ui['database_server']) ?></dt><dd><?= installer_h((string) $database['host'] . ':' . (string) $database['port']) ?></dd></div><div><dt><?= installer_h($ui['database_name']) ?></dt><dd><?= installer_h((string) $database['name']) ?></dd></div><div><dt><?= installer_h($ui['database_user']) ?></dt><dd><?= installer_h((string) $database['user']) ?></dd></div><div><dt><?= installer_h($ui['site_origin']) ?></dt><dd><?= installer_h((string) $basic['site_origin']) ?></dd></div><div><dt><?= installer_h($ui['site_base_domain']) ?></dt><dd><?= installer_h((string) $basic['site_base_domain']) ?></dd></div><div><dt><?= installer_h($ui['timezone']) ?></dt><dd><?= installer_h((string) $basic['timezone']) ?></dd></div></dl>
            <ul class="install-summary"><li><?= installer_h($ui['create_tables']) ?></li><li><?= installer_h($ui['generate_secret']) ?></li><li><?= installer_h($ui['save_admin_password']) ?></li><li><strong><?= installer_h($ui['lock_admin']) ?></strong></li></ul>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= installer_h($csrfToken) ?>"><input type="hidden" name="language" value="<?= installer_h($language) ?>"><input type="hidden" name="action" value="install"><div class="form-actions"><a class="secondary-link" href="?step=basic&amp;language=<?= installer_h($language) ?>"><?= installer_h($ui['back']) ?></a><button class="primary" type="submit"><?= installer_h($ui['install_button']) ?></button></div></form>
        <?php endif ?>
    </section>
</main>
<script src="../assets/installer.js"></script>
</body>
</html>
