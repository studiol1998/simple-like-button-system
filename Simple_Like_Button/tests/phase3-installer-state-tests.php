<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/src/InstallerState.php';

function phase3_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function phase3_remove_directory(string $path): void
{
    if (!is_dir($path)) return;
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        is_dir($child) ? phase3_remove_directory($child) : unlink($child);
    }
    rmdir($path);
}

$project = dirname(__DIR__);
$temporaryRoot = sys_get_temp_dir() . '/pollyanna-phase3-' . bin2hex(random_bytes(6));
$configDirectory = $temporaryRoot . '/config';
$installDirectory = $temporaryRoot . '/install';
$configPath = $configDirectory . '/config.php';
$credentialsPath = $configDirectory . '/admin_credentials.json';
$completionPath = $configDirectory . '/install_complete.json';
$databaseName = 'pollyanna_phase3_test_' . bin2hex(random_bytes(6));
$host = getenv('POLLYANNA_TEST_DB_HOST') ?: '127.0.0.1';
$port = getenv('POLLYANNA_TEST_DB_PORT') ?: '3306';
$user = getenv('POLLYANNA_TEST_DB_USER') ?: 'root';
$password = getenv('POLLYANNA_TEST_DB_PASSWORD') ?: '';
$server = null;

mkdir($configDirectory, 0750, true);
mkdir($installDirectory, 0750, true);

try {
    phase3_assert(is_dir($project . '/log'), 'The distributed log directory is missing.');
    phase3_assert(is_file($project . '/log/.htaccess'), 'log/.htaccess is missing.');
    phase3_assert(str_contains((string) file_get_contents($project . '/log/.htaccess'), 'Require all denied'), 'log/.htaccess does not deny access.');
    phase3_assert(PollyannaInstallerState::isWritableDirectory($project . '/log'), 'The distributed log directory is not writable.');
    phase3_assert(!PollyannaInstallerState::isWritableDirectory($temporaryRoot . '/missing-log'), 'A missing log directory was accepted.');

    phase3_assert(PollyannaInstallerState::detect($configPath, $credentialsPath, $completionPath) === PollyannaInstallerState::UNINSTALLED, 'Empty artifacts were not classified as uninstalled.');
    phase3_assert(!PollyannaInstallerState::shouldLockAdmin($installDirectory, $configPath, $credentialsPath, $completionPath), 'Uninstalled state locked Admin.');

    $server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $server->exec("CREATE DATABASE `{$databaseName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$databaseName};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $schema = file_get_contents($project . '/install/install.sql');
    foreach (preg_split('/;\s*(?:\r?\n|\z)/', (string) $schema, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
        if (trim($statement) !== '') $pdo->exec($statement);
    }

    $configuration = [
        'database' => [
            'dsn' => "mysql:host={$host};port={$port};dbname={$databaseName};charset=utf8mb4",
            'username' => $user,
            'password' => $password,
        ],
        'app_secret' => bin2hex(random_bytes(32)),
    ];
    file_put_contents($configPath, "<?php\nreturn " . var_export($configuration, true) . ";\n", LOCK_EX);
    file_put_contents($credentialsPath, json_encode(['password_hash' => password_hash('phase3-test-password', PASSWORD_DEFAULT)], JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX);
    $configHash = hash_file('sha256', $configPath);
    $credentialsHash = hash_file('sha256', $credentialsPath);

    phase3_assert(PollyannaInstallerState::detect($configPath, $credentialsPath, $completionPath) === PollyannaInstallerState::PARTIAL, 'Missing marker was not classified as partial.');
    phase3_assert(PollyannaInstallerState::shouldLockAdmin($installDirectory, $configPath, $credentialsPath, $completionPath), 'Partial state did not lock Admin.');
    PollyannaInstallerState::verifyRecoverable($configPath, $credentialsPath);
    PollyannaInstallerState::writeCompletionMarker($completionPath);
    phase3_assert(PollyannaInstallerState::detect($configPath, $credentialsPath, $completionPath) === PollyannaInstallerState::COMPLETE, 'Recovery did not reach complete state.');
    phase3_assert(hash_file('sha256', $configPath) === $configHash, 'Recovery changed config.php.');
    phase3_assert(hash_file('sha256', $credentialsPath) === $credentialsHash, 'Recovery changed administrator credentials.');
    phase3_assert(PollyannaInstallerState::shouldLockAdmin($installDirectory, $configPath, $credentialsPath, $completionPath), 'Complete state with install directory did not lock Admin.');
    phase3_remove_directory($installDirectory);
    phase3_assert(!PollyannaInstallerState::shouldLockAdmin($installDirectory, $configPath, $credentialsPath, $completionPath), 'Admin remained locked after install directory removal.');

    $pdo->exec("INSERT INTO pollyanna_allowed_origins (scheme, host, port, include_subdomains, created_at) VALUES ('https', 'phase3.example', 443, 0, UTC_TIMESTAMP(6))");
    $originId = (int) $pdo->lastInsertId();
    $delete = $pdo->prepare('DELETE FROM pollyanna_allowed_origins WHERE id = :id');
    $delete->execute(['id' => $originId]);
    phase3_assert($delete->rowCount() === 1, 'Existing external origin did not report one deleted row.');
    $delete->execute(['id' => $originId]);
    phase3_assert($delete->rowCount() === 0, 'Missing external origin did not report zero deleted rows.');

    unlink($completionPath);
    $pdo->exec('DROP TABLE pollyanna_allowed_origins');
    $failed = false;
    try {
        PollyannaInstallerState::verifyRecoverable($configPath, $credentialsPath);
    } catch (Throwable) {
        $failed = true;
    }
    phase3_assert($failed, 'Recovery accepted a database with a missing required table.');
    phase3_assert(!is_file($completionPath), 'Failed recovery created a completion marker.');
    phase3_assert(hash_file('sha256', $configPath) === $configHash, 'Failed recovery changed config.php.');
    phase3_assert(hash_file('sha256', $credentialsPath) === $credentialsHash, 'Failed recovery changed administrator credentials.');

    echo "Phase 3 installer state tests passed.\n";
} finally {
    if ($server instanceof PDO) {
        $server->exec("DROP DATABASE IF EXISTS `{$databaseName}`");
    }
    phase3_remove_directory($temporaryRoot);
}
