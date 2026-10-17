<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (($argv[1] ?? '') === '--http-worker') {
    $input=json_decode((string)stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
    $body=file_get_contents($input['url'],false,stream_context_create(['http'=>['method'=>'POST','header'=>'Content-Type: application/x-www-form-urlencoded'."\r\n".'Cookie: '.$input['cookie'],'content'=>$input['body'],'ignore_errors'=>true,'timeout'=>20]]));
    $status=0; foreach ($http_response_header ?? [] as $header) if (preg_match('/^HTTP\/\S+ (\d+)/',$header,$match)) $status=(int)$match[1];
    echo json_encode(['status'=>$status,'received'=>is_string($body)]); exit;
}
$name='pollyanna_security_http_'.bin2hex(random_bytes(6));
$root=sys_get_temp_dir().'/'.$name; mkdir($root); mkdir($root.'/sessions');
$host=getenv('POLLYANNA_TEST_DB_HOST') ?: '127.0.0.1'; $port=getenv('POLLYANNA_TEST_DB_PORT') ?: '3306';
$user=getenv('POLLYANNA_TEST_DB_USER') ?: 'root'; $dbPassword=getenv('POLLYANNA_TEST_DB_PASSWORD') ?: '';
$server=new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$user,$dbPassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$databases=[$name,$name.'_race',$name.'_other']; foreach($databases as $db) $server->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");
$processes=[]; $checks=0;
$assert=static function(bool $ok,string $label) use (&$checks):void {$checks++; if(!$ok)throw new RuntimeException($label);};
$copy=static function(string $from,string $to) use (&$copy):void {
    if(is_dir($from)){if(!is_dir($to))mkdir($to);foreach(array_diff(scandir($from) ?: [],['.','..'])as $file)$copy($from.'/'.$file,$to.'/'.$file);}else copy($from,$to);
};
$remove=static function(string $path) use (&$remove):void {
    foreach(array_diff(scandir($path) ?: [],['.','..'])as $file){$child=$path.'/'.$file;if(is_dir($child))$remove($child);else{chmod($child,0644);unlink($child);}}rmdir($path);
};
$start=static function(string $dir) use (&$processes):string {
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); if($socket===false)throw new RuntimeException('No test port.');
    $address=stream_socket_get_name($socket,false);fclose($socket);$pipes=[];
    $process=proc_open([PHP_BINARY,'-d','session.save_path='.$dir.'/sessions','-S',$address,'-t',$dir],[0=>['pipe','r'],1=>['file',$dir.'/out.log','a'],2=>['file',$dir.'/error.log','a']],$pipes);
    if(!is_resource($process))throw new RuntimeException('No test server.');fclose($pipes[0]);$processes[]=$process;
    for($i=0;$i<50;$i++){ $ready=@stream_socket_client('tcp://'.$address,$errno,$error,.1);if($ready!==false){fclose($ready);return 'http://'.$address;}usleep(100000); }throw new RuntimeException('Test server did not start.');
};
$request=static function(string $base,string $path,string &$cookie,string $method='GET',array $body=[],bool $follow=true) use (&$request):array {
    $context=stream_context_create(['http'=>['method'=>$method,'header'=>'Content-Type: application/x-www-form-urlencoded'.($cookie!==''?"\r\n".'Cookie: '.$cookie:''),'content'=>$method==='POST'?http_build_query($body):'','ignore_errors'=>true,'follow_location'=>0,'timeout'=>20]]);
    $html=(string)file_get_contents($base.$path,false,$context);$status=0;$headers=$http_response_header ?? [];
    foreach($headers as $header){if(preg_match('/^HTTP\/\S+ (\d+)/',$header,$m))$status=(int)$m[1];if(preg_match('/^Set-Cookie: ((?:PHPSESSID|pollyanna_installer)=[^;]+)/i',$header,$m))$cookie=$m[1];}
    if ($follow && $status >= 300 && $status < 400) {
        foreach ($headers as $header) if (preg_match('/^Location: (.*)$/i', $header, $m)) {
            $location = $m[1];
            if ($location === './') $location = '/admin/';
            elseif (str_starts_with($location, '?')) $location = (str_starts_with($path, '/install/') ? '/install/' : '/admin/') . $location;
            return $request($base, $location, $cookie);
        }
    }
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$html,$m);
    return [$status,$html,$m[1] ?? '',$headers];
};
$prepare=static function(string $base,string $db,string &$cookie,string $password) use ($request,$host,$port,$user,$dbPassword,$assert):array {
    [$status,$html,$token]=$request($base,'/install/',$cookie);$assert($status===200 && $token!=='','Installer welcome and CSRF');
    foreach([['action'=>'start_environment'],['action'=>'continue'],['action'=>'test_database','db_host'=>$host,'db_port'=>$port,'db_name'=>$db,'db_user'=>$user,'db_password'=>$dbPassword]]as $body){
        [$status,$html,$token]=$request($base,'/install/',$cookie,'POST',$body+['csrf_token'=>$token]);$assert($status===200 && $token!=='','Installer wizard advances');
    }
    $assert(substr_count($html,'data-new-admin-password')===2 && str_contains($html,'data-password-rule='),'Installer password fields use localized shared validation');
    [$status,$html,$token]=$request($base,'/install/',$cookie,'POST',['action'=>'save_basic','csrf_token'=>$token,'timezone'=>'Asia/Tokyo','site_origin'=>$base,'site_base_domain'=>'127.0.0.1','admin_password'=>$password,'admin_password_confirmation'=>$password]);
    $assert($status===200 && str_contains($html,'name="action" value="install"'),'Installer accepts password policy');return ['action'=>'install','csrf_token'=>$token];
};
try {
    foreach(['admin','api','assets','src','lang','install','config','log']as $dir)$copy(dirname(__DIR__).'/'.$dir,$root.'/'.$dir);
    $base=$start($root);$installerCookie='';$adminPassword=bin2hex(random_bytes(24));
    $install=$prepare($base,$name,$installerCookie,$adminPassword);
    [$status,$html]=$request($base,'/install/',$installerCookie,'POST',$install);
    $assert($status===200 && is_file($root.'/config/install_complete.json'),'Full new installation completes');
    $cfg=require $root.'/config/config.php';$assert($cfg['access_protection']['enabled'] && $cfg['access_protection']['auto_block'],'New install keeps protection defaults ON');
    $pdo=new PDO($cfg['database']['dsn'],$user,$dbPassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $assert(count($pdo->query('SHOW TABLES')->fetchAll())===10,'New installer creates ten tables');
    $cookie='';[$status]=$request($base,'/admin/',$cookie);$assert($status===503,'Admin locked until installer removal');
    $configHash=hash_file('sha256',$root.'/config/config.php');$authHash=hash_file('sha256',$root.'/config/admin_credentials.json');
    $request($base,'/install/',$installerCookie,'POST',$install);$assert(hash_file('sha256',$root.'/config/config.php')===$configHash && hash_file('sha256',$root.'/config/admin_credentials.json')===$authHash,'Completed installation refuses overwrite');
    $remove($root.'/install');
    file_put_contents($root.'/config/admin_settings.json',json_encode(['language'=>'ja','theme'=>'green']));$settingsHash=hash_file('sha256',$root.'/config/admin_settings.json');
    [$status,$html,$csrf]=$request($base,'/admin/?language=en&theme=blue',$cookie);$assert($status===200 && str_contains($html,'lang="en"') && str_contains($html,'data-theme="blue"'),'Unauthenticated temporary display');
    $assert(hash_file('sha256',$root.'/config/admin_settings.json')===$settingsHash,'Unauthenticated GET cannot save');
    [$status,$html]=$request($base,'/admin/',$cookie,'POST',['action'=>'save_admin_theme','csrf_token'=>$csrf,'theme'=>'pink']);$assert($status===403 && hash_file('sha256',$root.'/config/admin_settings.json')===$settingsHash,'Unauthenticated POST cannot save');
    $request($base,'/admin/',$cookie,'POST',['action'=>'admin_login','csrf_token'=>$csrf,'password'=>bin2hex(random_bytes(20))]);
    [, $html]=$request($base,'/admin/',$cookie);$assert(str_contains($html,'lang="en"') && str_contains($html,'data-theme="blue"'),'Temporary display survives failed login and reload');
    $other='';[, $html]=$request($base,'/admin/',$other);$assert(str_contains($html,'lang="ja"') && str_contains($html,'data-theme="green"'),'Temporary display is session-local');
    [$status,$html,$csrf]=$request($base,'/admin/',$cookie,'POST',['action'=>'admin_login','csrf_token'=>$csrf,'password'=>$adminPassword]);
    $assert($status===200 && str_contains($html,'role="tab"') && str_contains($html,'lang="ja"') && str_contains($html,'data-theme="green"'),'Login clears temporary display');
    $assert(hash_file('sha256',$root.'/config/admin_credentials.json')===$authHash,'Login never rehashes');
    $otherLogin=static function(string &$session,string $password) use($request,$base):string {[,,$token]=$request($base,'/admin/',$session);[,,$token]=$request($base,'/admin/',$session,'POST',['action'=>'admin_login','csrf_token'=>$token,'password'=>$password]);return $token;};
    $otherCsrf=$otherLogin($other,$adminPassword);
    [$status]=$request($base,'/admin/?language=en&theme=pink',$cookie,'POST',['action'=>'save_admin_theme','csrf_token'=>'invalid','theme'=>'pink']);$assert($status===403 && hash_file('sha256',$root.'/config/admin_settings.json')===$settingsHash,'Authenticated invalid CSRF cannot save even with GET values');
    foreach([['save_admin_language','language','en','theme','green'],['save_admin_theme','theme','blue','language','en']]as [$action,$key,$value,$otherKey,$otherValue]){
        [$status]=$request($base,'/admin/',$cookie,'POST',['action'=>$action,'csrf_token'=>$csrf,$key=>$value],false);$assert($status===303,'Preferences use PRG');
        $saved=json_decode((string)file_get_contents($root.'/config/admin_settings.json'),true);$assert($saved[$key]===$value && $saved[$otherKey]===$otherValue,'Preference saves only requested field');
    }
    foreach([['save_admin_language','language','bad'],['save_admin_theme','theme','bad']]as [$action,$key,$value]){ $before=hash_file('sha256',$root.'/config/admin_settings.json');[$status]=$request($base,'/admin/',$cookie,'POST',['action'=>$action,'csrf_token'=>$csrf,$key=>$value]);$assert($status===422 && hash_file('sha256',$root.'/config/admin_settings.json')===$before,'Invalid preference rejected'); }
    $newPassword=bin2hex(random_bytes(24));$change=['action'=>'change_admin_password','csrf_token'=>$csrf,'current_password'=>$adminPassword,'new_password'=>$newPassword,'new_password_confirmation'=>$newPassword];
    [$status]=$request($base,'/admin/',$cookie,'POST',array_replace($change,['csrf_token'=>'invalid']));$assert($status===403 && hash_file('sha256',$root.'/config/admin_credentials.json')===$authHash,'Password change CSRF protects credentials');
    foreach([['current_password'=>'incorrect'],['new_password_confirmation'=>'mismatch'],['new_password'=>str_repeat('a',73)]]as $bad){[$status,$html]=$request($base,'/admin/',$cookie,'POST',array_replace($change,$bad));$assert($status===422 && hash_file('sha256',$root.'/config/admin_credentials.json')===$authHash,'Rejected change preserves credentials');$assert(!str_contains($html,$newPassword) && !str_contains($html,$adminPassword),'Passwords never redisplayed');}
    if(PHP_OS_FAMILY==='Windows'){
        chmod($root.'/config/admin_credentials.json',0444);
        [$status]=$request($base,'/admin/',$cookie,'POST',$change);
        $assert($status===422 && hash_file('sha256',$root.'/config/admin_credentials.json')===$authHash,'Credential replacement failure preserves old hash');
        chmod($root.'/config/admin_credentials.json',0644);
    }
    for ($i=0;$i<6;$i++) $request($base,'/admin/',$cookie,'POST',array_replace($change,['current_password'=>'incorrect']));
    [$status]=$request($base,'/admin/',$cookie,'POST',$change);$assert($status===429,'Password change limited to five attempts per minute');
    $change['csrf_token']=$otherCsrf;$savedSettings=hash_file('sha256',$root.'/config/admin_settings.json');
    [$status]=$request($base,'/admin/',$other,'POST',$change,false);$assert($status===303,'Successful change uses PRG');
    $newHash=json_decode((string)file_get_contents($root.'/config/admin_credentials.json'),true)['password_hash'];
    $assert(password_verify($newPassword,$newHash) && !password_verify($adminPassword,$newHash),'New bcrypt password replaces old');
    [$status,$html,$freshCsrf]=$request($base,'/admin/',$other);$assert(str_contains($html,'name="action" value="admin_login"') && !str_contains($html,'role="tab"'),'Changing session must sign in again');
    [$status]=$request($base,'/admin/',$cookie,'POST',['action'=>'save_admin_theme','csrf_token'=>$csrf,'theme'=>'pink']);$assert($status===403 && hash_file('sha256',$root.'/config/admin_settings.json')===$savedSettings,'Other authenticated session cannot keep operating');
    $request($base,'/admin/',$other,'POST',['action'=>'admin_login','csrf_token'=>$freshCsrf,'password'=>$adminPassword]);
    [, $html,$freshCsrf]=$request($base,'/admin/',$other);$assert(!str_contains($html,'role="tab"'),'Old password cannot log in');
    $otherLogin($other,$newPassword);$assert(hash_file('sha256',$root.'/config/config.php')===$configHash,'Password change preserves app secret and DB settings');
    [$status]=$request($base,'/admin/',$other,'POST',$change);$assert($status===403,'Old CSRF and POST replay cannot change again');
    // Legacy hashes are retained verbatim and accept the original long password.
    $legacyPassword=bin2hex(random_bytes(36)).'legacy-tail';$legacyHash=password_hash($legacyPassword,PASSWORD_BCRYPT);
    file_put_contents($root.'/config/admin_credentials.json',json_encode(['password_hash'=>$legacyHash]));$legacyDigest=hash_file('sha256',$root.'/config/admin_credentials.json');
    $legacyCookie='';$legacyCsrf=$otherLogin($legacyCookie,$legacyPassword);[, $html]=$request($base,'/admin/',$legacyCookie);
    $assert(str_contains($html,'role="tab"') && hash_file('sha256',$root.'/config/admin_credentials.json')===$legacyDigest,'Legacy over-72-byte login succeeds without rehash');
    // Two independent HTTP servers/processes target one copied installation.
    $raceRoot=$root.'/race';mkdir($raceRoot);mkdir($raceRoot.'/sessions');foreach(['admin','api','assets','src','lang','install','config','log']as $dir)$copy(dirname(__DIR__).'/'.$dir,$raceRoot.'/'.$dir);
    file_put_contents($raceRoot.'/install/install.sql',"DO SLEEP(2);\n".(string)file_get_contents(dirname(__DIR__).'/install/install.sql'));
    // In the disposable copy only, pause after the initial state read so both requests can see UNINSTALLED.
    $installerSource=(string)file_get_contents($raceRoot.'/install/index.php');
    $stateLine='$canInstall = $installationState === PollyannaInstallerState::UNINSTALLED;';
    $installerSource=str_replace($stateLine,$stateLine."\n".'if (($_SERVER[\'REQUEST_METHOD\'] ?? \'\') === \'POST\' && ($_POST[\'action\'] ?? \'\') === \'install\') { if (isset($_POST[\'security_test_first\'])) file_put_contents(__DIR__ . \'/.qa-first-ready\', \'ready\'); usleep(400000); }',$installerSource);
    file_put_contents($raceRoot.'/install/index.php',$installerSource);
    $baseA=$start($raceRoot);$baseB=$start($raceRoot);$sessionA='';$sessionB='';$passA=bin2hex(random_bytes(24));$passB=bin2hex(random_bytes(24));
    $bodyA=$prepare($baseA,$databases[1],$sessionA,$passA);$bodyB=$prepare($baseB,$databases[2],$sessionB,$passB);
    $bodyA['security_test_first']='1';
    $pipes=[];$worker=proc_open([PHP_BINARY,__FILE__,'--http-worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',$root.'/worker-error.log','a']],$pipes);
    if(!is_resource($worker))throw new RuntimeException('No concurrent HTTP worker.');
    fwrite($pipes[0],json_encode(['url'=>$baseA.'/install/','cookie'=>$sessionA,'body'=>http_build_query($bodyA)]));fclose($pipes[0]);
    for($i=0;$i<100 && !file_exists($raceRoot.'/install/.qa-first-ready');$i++){clearstatcache();usleep(20000);}
    $assert(file_exists($raceRoot.'/install/.qa-first-ready'),'First process completed the initial state check');
    $request($baseB,'/install/',$sessionB,'POST',$bodyB);
    clearstatcache();
    $assert(file_exists($raceRoot.'/config/.installer-in-progress'),'First independent process holds reservation while stale second request is refused');
    $result=json_decode((string)stream_get_contents($pipes[1]),true);fclose($pipes[1]);$exit=proc_close($worker);
    $assert($exit===0 && ($result['received'] ?? false),'Concurrent HTTP worker completed');
    $assert(is_file($raceRoot.'/config/admin_credentials.json'),'First install publishes credentials');
    $winner=json_decode((string)file_get_contents($raceRoot.'/config/admin_credentials.json'),true)['password_hash'];
    $assert(password_verify($passA,$winner) && !password_verify($passB,$winner),'Second session cannot overwrite first credentials');
    $assert(!file_exists($raceRoot.'/config/.installer-in-progress') && is_file($raceRoot.'/config/.installer.lock'),'Progress cleared and lock retained');
    $otherDb=new PDO("mysql:host={$host};port={$port};dbname={$databases[2]};charset=utf8mb4",$user,$dbPassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $assert($otherDb->query('SHOW TABLES')->fetchAll()===[],'Competing different DB remains untouched');
    $winnerConfig=hash_file('sha256',$raceRoot.'/config/config.php');$winnerAuth=hash_file('sha256',$raceRoot.'/config/admin_credentials.json');
    $request($baseB,'/install/',$sessionB,'POST',$bodyB);$assert(hash_file('sha256',$raceRoot.'/config/config.php')===$winnerConfig && hash_file('sha256',$raceRoot.'/config/admin_credentials.json')===$winnerAuth,'Late replay preserves first secret and credentials');
    foreach([$adminPassword,$newPassword,$legacyPassword,$passA,$passB]as $secret){$assert(!str_contains((string)file_get_contents($root.'/error.log'),$secret) && !str_contains((string)file_get_contents($raceRoot.'/error.log'),$secret),'Passwords absent from server errors');}
    if (in_array('--browser', $argv, true)) {
        $fixture = <<<'HTML'
<!doctype html><meta charset="utf-8"><title>Isolated security UI tests</title>
<iframe id="wide-ja" src="/admin/?tab=admin-operation&amp;language=ja" style="width:1100px;height:900px;border:0"></iframe>
<iframe id="narrow-ja" src="/admin/?tab=admin-operation&amp;language=ja" style="width:320px;height:900px;border:0"></iframe>
<iframe id="wide-en" src="/admin/?tab=admin-operation&amp;language=en" style="width:1100px;height:900px;border:0"></iframe>
<iframe id="narrow-en" src="/admin/?tab=admin-operation&amp;language=en" style="width:320px;height:900px;border:0"></iframe>
<pre id="result">PENDING</pre><script>
window.addEventListener('load', async () => {
 try {
  let checks=0; const assert=(ok,label)=>{checks++;if(!ok)throw Error(label);};
  for(const id of ['wide-ja','narrow-ja','wide-en','narrow-en']){
   const frame=document.getElementById(id);let d=frame.contentDocument,w=frame.contentWindow;
   assert(d.querySelectorAll('[role="tab"]').length===5,id+' five tabs');
   const card=d.querySelector('.password-change-card'),form=card.querySelector('form');
   assert(d.documentElement.scrollWidth<=w.innerWidth,id+' no overflow');
   assert(card.getBoundingClientRect().width===d.querySelector('#panel-admin-operation .settings-card').getBoundingClientRect().width,id+' full-width card');
   assert(form.getBoundingClientRect().width<=480,id+' form <=480');
   assert([...form.querySelectorAll('input:not([type="hidden"])')].every(i=>i.type==='password' && i.value===''),id+' passwords are blank and masked');
   assert(form.querySelectorAll('input[type="hidden"]').length===2,id+' only action and CSRF hidden');
   const field=d.getElementById('new-password');
   field.value='a'.repeat(11);field.dispatchEvent(new w.Event('input',{bubbles:true}));assert(!field.checkValidity(),id+' 11 code points rejected');
   field.value='日'.repeat(24);field.dispatchEvent(new w.Event('input',{bubbles:true}));assert(field.checkValidity(),id+' 72 UTF-8 bytes accepted');
   field.value='日'.repeat(25);field.dispatchEvent(new w.Event('input',{bubbles:true}));assert(!field.checkValidity(),id+' 75 UTF-8 bytes rejected');
   assert(form.querySelector('[data-save-status]').textContent===form.dataset.unsavedText,id+' unsaved notice');
   const original=field.value;d.querySelector('[data-admin-tab="access-protection"]').click();d.querySelector('[data-admin-tab="admin-operation"]').click();
   assert(field.value===original,id+' tab switch retains input');
   const language=d.querySelector('[data-language-select]');assert(language.form.method==='post' && language.form.querySelector('[name="csrf_token"]'),id+' language POST and CSRF');
   const theme=d.querySelector('[data-theme-select]');assert(theme.form.method==='post' && theme.form.querySelector('[name="csrf_token"]'),id+' theme POST and CSRF');
   const navigate=async(change)=>{const loaded=new Promise(resolve=>frame.addEventListener('load',resolve,{once:true}));change();await loaded;d=frame.contentDocument;w=frame.contentWindow;};
   await navigate(()=>{theme.value='rose';theme.dispatchEvent(new w.Event('change',{bubbles:true}));});assert(d.documentElement.dataset.theme==='rose',id+' theme control saves');
   await navigate(()=>{const select=d.querySelector('[data-language-select]');select.value=id.endsWith('ja')?'en':'ja';select.dispatchEvent(new w.Event('change',{bubbles:true}));});
   assert(d.documentElement.lang===(id.endsWith('ja')?'en':'ja'),id+' language control saves');
  }
  document.getElementById('result').textContent='PASS '+checks;
 }catch(e){document.getElementById('result').textContent='FAIL '+e.message;}
});
</script>
HTML;
        $fixture=str_replace('<iframe id="wide-ja"','<script>document.cookie='.json_encode($legacyCookie.'; Path=/').';</script><iframe id="wide-ja"',$fixture);
        file_put_contents($root.'/security-ui.html',$fixture);
        $pipes=[];$browser=proc_open(['C:/Program Files/Google/Chrome/Application/chrome.exe','--headless=new','--no-first-run','--disable-background-networking','--disable-gpu','--user-data-dir='.$root.'/chrome','--virtual-time-budget=15000','--dump-dom',$base.'/security-ui.html'],[0=>['pipe','r'],1=>['file',$root.'/chrome-dom.txt','w'],2=>['file',$root.'/chrome-errors.log','w']],$pipes);
        if(!is_resource($browser))throw new RuntimeException('Browser unavailable.');fclose($pipes[0]);$exit=proc_close($browser);
        $dom=(string)file_get_contents($root.'/chrome-dom.txt');
        $assert($exit===0 && preg_match('/<pre id="result">PASS (\d+)<\/pre>/',$dom,$metrics)===1,'Security browser tests failed: '.(preg_match('/<pre id="result">([^<]*)/',$dom,$failure)?$failure[1]:'no result'));
        echo 'Security Chrome UI tests passed ('.$metrics[1]." checks, Japanese/English at 320px/1100px).\n";
    }
    echo "Security HTTP and concurrent installer tests passed ({$checks} assertions).\n";
} finally {
    foreach($processes as $process){proc_terminate($process);proc_close($process);}
    foreach($databases as $db)$server->exec("DROP DATABASE `{$db}`");
    if(realpath(dirname($root))===realpath(sys_get_temp_dir()) && basename($root)===$name)$remove($root);
}
