<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/src/InstallerState.php';
require_once dirname(__DIR__) . '/src/AdminPasswordPolicy.php';
if (($argv[1] ?? '') === '--interrupt-worker') {
    $dir = $argv[2] ?? '';
    if (!is_dir($dir) || basename($dir) !== 'interrupt'
        || preg_match('/\Apollyanna_security_install_[a-f0-9]{12}\z/', basename(dirname($dir))) !== 1
        || realpath(dirname(dirname($dir))) !== realpath(sys_get_temp_dir())) exit(2);
    PollyannaInstallerState::install($dir.'/config.php',$dir.'/auth.json',$dir.'/complete.json',static function () use ($dir): void {
        file_put_contents($dir.'/ready','waiting');
        sleep(30);
    });
    exit;
}
$name = 'pollyanna_security_install_' . bin2hex(random_bytes(6));
$root = sys_get_temp_dir() . '/' . $name;
mkdir($root);
$host = getenv('POLLYANNA_TEST_DB_HOST') ?: '127.0.0.1';
$port = getenv('POLLYANNA_TEST_DB_PORT') ?: '3306';
$user = getenv('POLLYANNA_TEST_DB_USER') ?: 'root';
$password = getenv('POLLYANNA_TEST_DB_PASSWORD') ?: '';
$server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$server->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");
$checks = 0;
$assert = static function (bool $ok, string $label) use (&$checks): void { $checks++; if (!$ok) throw new RuntimeException($label); };
$remove = static function (string $path) use (&$remove): void {
    foreach (array_diff(scandir($path) ?: [], ['.','..']) as $file) { $child=$path.'/'.$file; is_dir($child) ? $remove($child) : unlink($child); }
    rmdir($path);
};
try {
    $cfg = ['database'=>['dsn'=>"mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",'username'=>$user,'password'=>$password], 'app_secret'=>bin2hex(random_bytes(32))];
    $pdo = new PDO($cfg['database']['dsn'], $user, $password, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $schema = preg_split('/;\s*(?:\r?\n|\z)/', (string) file_get_contents(dirname(__DIR__).'/install/install.sql'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $credential = json_encode(['password_hash'=>AdminPasswordPolicy::hash(bin2hex(random_bytes(24)))], JSON_THROW_ON_ERROR);
    foreach (['sql','credentials','config','before_completion','after_completion','normal'] as $phase) {
        $dir=$root.'/'.$phase; mkdir($dir);
        $config=$dir.'/config.php'; $auth=$dir.'/admin_credentials.json'; $complete=$dir.'/install_complete.json';
        try {
            PollyannaInstallerState::install($config,$auth,$complete, static function () use ($phase,$pdo,$schema,$config,$auth,$complete,$cfg,$credential): void {
                foreach ($schema as $index=>$sql) {
                    $pdo->exec($sql);
                    if ($phase==='sql' && $index===0) $pdo->exec('INTENTIONAL INVALID SQL');
                }
                PollyannaInstallerState::createExclusive($auth,$credential);
                if ($phase==='credentials') throw new RuntimeException('Simulated interruption');
                PollyannaInstallerState::createExclusive($config,'<?php return '.var_export($cfg,true).';');
                if (in_array($phase,['config','before_completion'],true)) throw new RuntimeException('Simulated interruption');
                PollyannaInstallerState::writeCompletionMarker($complete);
                if ($phase==='after_completion') throw new RuntimeException('Simulated interruption');
            });
            $assert($phase==='normal','Only normal installation completes');
        } catch (Throwable) { $assert($phase!=='normal','Injected failure retained'); }
        if ($phase==='sql') {
            $assert(count($pdo->query('SHOW TABLES')->fetchAll())===1,'SQL interruption keeps the first created table');
            $pdo->exec("INSERT INTO pollyanna_likes (identifier,client_hash,created_at) VALUES ('preserved',REPEAT('a',64),UTC_TIMESTAMP(6))");
        }
        $assert((int)$pdo->query('SELECT COUNT(*) FROM pollyanna_likes')->fetchColumn()===1,'Partial installation and retries preserve existing fixture data');
        $state=PollyannaInstallerState::detect($config,$auth,$complete);
        $assert($state===($phase==='normal'?PollyannaInstallerState::COMPLETE:PollyannaInstallerState::PARTIAL),'Failure state classified');
        $assert(file_exists($dir.'/.installer-in-progress')===($phase!=='normal'),'Progress retained only for unfinished work');
        $assert(is_file($dir.'/.installer.lock'),'Lock file retained');
        $before=[]; foreach ([$config,$auth,$complete] as $path) if (is_file($path)) $before[$path]=hash_file('sha256',$path);
        $called=false;
        try { PollyannaInstallerState::install($config,$auth,$complete, static function () use (&$called): void {$called=true;}); $assert(false,'Rerun rejected'); }
        catch (RuntimeException) { $assert(!$called,'Rerun does not start writes'); }
        if (in_array($phase,['config','before_completion','after_completion'],true)) {
            try { PollyannaInstallerState::recover($config,$auth,$complete,false); $assert(false,'Stop confirmation required'); }
            catch (RuntimeException) { $assert(file_exists($dir.'/.installer-in-progress'),'Unconfirmed recovery leaves progress present'); }
            PollyannaInstallerState::recover($config,$auth,$complete,true);
            $assert(!file_exists($dir.'/.installer-in-progress'),'Verified recovery removes progress');
            $assert(PollyannaInstallerState::detect($config,$auth,$complete)===PollyannaInstallerState::COMPLETE,'Recovery completes');
        } else {
            try { PollyannaInstallerState::recover($config,$auth,$complete,true); $assert(false,'Unsafe recovery rejected'); }
            catch (RuntimeException) { $assert(true,'Incomplete artifacts or COMPLETE refuse recovery'); }
        }
        foreach ($before as $path=>$hash) $assert(hash_file('sha256',$path)===$hash,'Existing artifact hash preserved');
        if (is_file($auth)) {
            try { PollyannaInstallerState::createExclusive($auth,'replacement'); $assert(false,'Exclusive creation refuses existing file'); }
            catch (RuntimeException) { $assert(hash_file('sha256',$auth)===$before[$auth],'No clobber'); }
        }
    }
    $dir=$root.'/legacy-partial'; mkdir($dir);
    $config=$dir.'/config.php';$auth=$dir.'/admin_credentials.json';$complete=$dir.'/install_complete.json';
    PollyannaInstallerState::createExclusive($config,'<?php return '.var_export($cfg,true).';');
    PollyannaInstallerState::createExclusive($auth,$credential);
    $savedConfig=hash_file('sha256',$config);$savedAuth=hash_file('sha256',$auth);
    try { PollyannaInstallerState::recover($config,$auth,$complete,false);$assert(false,'Legacy recovery also requires stop confirmation'); }
    catch(RuntimeException){$assert(!file_exists($complete),'Unconfirmed legacy recovery writes no completion');}
    PollyannaInstallerState::recover($config,$auth,$complete,true);
    $assert(hash_file('sha256',$config)===$savedConfig && hash_file('sha256',$auth)===$savedAuth,'Legacy recovery preserves credentials and secret');
    file_put_contents($complete,'{"completed":false}');$invalidCompletion=hash_file('sha256',$complete);
    try { PollyannaInstallerState::recover($config,$auth,$complete,true);$assert(false,'Invalid existing completion requires manual review'); }
    catch(RuntimeException){$assert(hash_file('sha256',$complete)===$invalidCompletion,'Invalid existing marker is not overwritten');}
    $dir=$root.'/lock'; mkdir($dir); $lock=fopen($dir.'/.installer.lock','c'); flock($lock,LOCK_EX);
    try { PollyannaInstallerState::install($dir.'/config.php',$dir.'/auth.json',$dir.'/complete.json',static function (): void {throw new LogicException('Must not execute');}); $assert(false,'Busy lock refused'); }
    catch (RuntimeException) { $assert(!file_exists($dir.'/.installer-in-progress'),'Lock conflict writes no progress'); }
    flock($lock,LOCK_UN); fclose($lock);
    unlink($dir.'/.installer.lock'); mkdir($dir.'/.installer.lock');
    try { PollyannaInstallerState::install($dir.'/config.php',$dir.'/auth.json',$dir.'/complete.json',static function (): void {throw new LogicException('Must not execute');}); $assert(false,'Unavailable lock refused'); }
    catch (RuntimeException) { $assert(!file_exists($dir.'/config.php'),'Unavailable lock writes no config'); }
    $dir=$root.'/interrupt'; mkdir($dir); $pipes=[];
    $worker=proc_open([PHP_BINARY,__FILE__,'--interrupt-worker',$dir],[0=>['pipe','r'],1=>['file',$root.'/interrupt-output.log','w'],2=>['file',$root.'/interrupt-error.log','w']],$pipes);
    if(!is_resource($worker))throw new RuntimeException('Cannot start interruption worker.');
    fclose($pipes[0]);
    try {
        for($i=0;$i<100 && !file_exists($dir.'/ready');$i++){clearstatcache();usleep(20000);}
        $assert(file_exists($dir.'/ready'),'Separate process owns installation lock');
    } finally { proc_terminate($worker);proc_close($worker); }
    clearstatcache();
    $assert(file_exists($dir.'/.installer-in-progress'),'Forced termination retains progress');
    $assert(PollyannaInstallerState::detect($dir.'/config.php',$dir.'/auth.json',$dir.'/complete.json')===PollyannaInstallerState::PARTIAL,'Interrupted empty artifacts are PARTIAL');
    $lock=fopen($dir.'/.installer.lock','c');
    $assert(flock($lock,LOCK_EX|LOCK_NB),'Terminated process releases OS lock');
    flock($lock,LOCK_UN);fclose($lock);
    try { PollyannaInstallerState::recover($dir.'/config.php',$dir.'/auth.json',$dir.'/complete.json',true); $assert(false,'Cannot recover missing interrupted artifacts'); }
    catch(RuntimeException){$assert(file_exists($dir.'/.installer-in-progress'),'Unsafe recovery keeps progress and data');}
    echo "Security installer failure tests passed ({$checks} assertions).\n";
} finally {
    $server->exec("DROP DATABASE `{$name}`");
    if (realpath(dirname($root))===realpath(sys_get_temp_dir()) && basename($root)===$name) $remove($root);
}
