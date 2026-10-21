<?php
// Tests never inherit the application's production DATABASE_URL.
if(PHP_SAPI==='cli'){
    $testDatabase=(string)getenv('CSP_TEST_DATABASE_URL');
    if($testDatabase!==''&&!in_array(parse_url($testDatabase,PHP_URL_HOST),['localhost','127.0.0.1'],true))throw new RuntimeException('I test PostgreSQL richiedono un database locale isolato.');
    putenv('DATABASE_URL='.$testDatabase);
}
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('CSP_TEST_SOURCE',__DIR__.'/fixture.php');
$dir=sys_get_temp_dir().'/csp-accounts-'.bin2hex(random_bytes(8));putenv('CSP_DATA_DIR='.$dir);
mkdir($dir,0700);session_save_path($dir);
set_error_handler(function($severity,$message){throw new RuntimeException($message);});
putenv('CSP_ADMIN_USER=owner');putenv('CSP_ADMIN_PASSWORD=Bootstrap!Secret123');putenv('CSP_ADMIN_PASSWORD_HASH');
require __DIR__.'/../csp_core.php';
$checks=[];
function ck($ok,$message):void{if(!$ok)throw new RuntimeException('FAIL: '.$message);$GLOBALS['checks'][]=$message;}
function denied(callable $f):bool{try{$f();return false;}catch(InvalidArgumentException|RuntimeException $e){return true;}}
try{
    ck(csp_login('owner','Bootstrap!Secret123'),'credenziali esistenti creano il primo amministratore');$owner=$_SESSION;
    ck((int)csp_sql('SELECT COUNT(*) FROM users')->fetchColumn()===1,'bootstrap unico');
    csp_create_user(['username'=>'mario','password'=>'Temporary!Secret123','role'=>'operator'],'owner');
    $id=(int)csp_sql('SELECT id FROM users WHERE username=?',['mario'])->fetchColumn();
    ck(denied(fn()=>csp_create_user(['username'=>'MARIO','password'=>'Temporary!Secret123','role'=>'operator'],'owner')),'duplicati senza distinzione maiuscole rifiutati');
    ck(denied(fn()=>csp_create_user(['username'=>'test','password'=>'short','role'=>'operator'],'owner')),'password brevi rifiutate');
    ck(csp_login('MARIO','Temporary!Secret123') && $_SESSION['admin']==='mario','login personale con identità canonica');
    ck(denied(fn()=>csp_authorize_action('board')),'password temporanea blocca API operative');
    ck(denied(fn()=>csp_users()) && denied(fn()=>csp_create_user(['username'=>'evil','password'=>'Temporary!Secret123','role'=>'admin'],'mario')),'operatore non gestisce utenti neanche dal server');
    ck(denied(fn()=>csp_change_password('wrong','Personal!Secret456')),'cambio password richiede password attuale');
    $before=$_SESSION;csp_change_password('Temporary!Secret123','Personal!Secret456');$mario=$_SESSION;
    ck(!$_SESSION['must_change'] && csp_credentials_valid('mario','Personal!Secret456') && !csp_credentials_valid('mario','Temporary!Secret123'),'cambio obbligatorio e hash persistente');
    ck(!denied(fn()=>csp_authorize_action('suppress_scope')) && !denied(fn()=>csp_authorize_action('message')),'operatore abilitato a corse e avvisi');
    ck(denied(fn()=>csp_authorize_action('queue_retry')) && denied(fn()=>csp_authorize_action('telegram_chats')),'strumenti Telegram riservati agli amministratori');
    $_SESSION=$before;csp_refresh_identity();ck(empty($_SESSION['admin']),'cambio password revoca le altre sessioni');
    $_SESSION=$owner;csp_update_user(['id'=>$id,'role'=>'operator','active'=>'0'],'owner');
    ck(!csp_credentials_valid('mario','Personal!Secret456'),'account disabilitato non accede');
    $_SESSION=$mario;csp_refresh_identity();ck(empty($_SESSION['admin']),'disabilitazione revoca sessione aperta');
    $_SESSION=$owner;csp_update_user(['id'=>$id,'role'=>'operator','active'=>'1'],'owner');
    ck(csp_login('mario','Personal!Secret456'),'riattivazione conserva credenziali');$mario=$_SESSION;
    $_SESSION=$owner;csp_reset_password($id,'Reset!Secret7890','owner');
    $_SESSION=$mario;csp_refresh_identity();ck(empty($_SESSION['admin']),'reset amministratore revoca sessioni');
    ck(csp_login('mario','Reset!Secret7890') && $_SESSION['must_change'],'reset richiede nuova password personale');
    $_SESSION=$owner;
    ck(denied(fn()=>csp_update_user(['id'=>$owner['user_id'],'role'=>'operator','active'=>'0'],'owner')),'amministratore non disabilita o declassa se stesso');
    // Simulate a stale manager session to verify the last-admin invariant inside the transaction.
    $_SESSION['user_id']=99999;
    ck(denied(fn()=>csp_update_user(['id'=>$owner['user_id'],'role'=>'operator','active'=>'0'],'owner')),'ultimo amministratore protetto anche nel database');
    $_SESSION=$owner;
    putenv('CSP_ADMIN_USER=other');putenv('CSP_ADMIN_PASSWORD=Another!Secret123');
    ck(!csp_credentials_valid('other','Another!Secret123') && !csp_authenticate('other','Another!Secret123'),'variabili Render non aprono accessi dopo il bootstrap');
    $audit=csp_json(csp_sql('SELECT * FROM audit')->fetchAll(PDO::FETCH_ASSOC));
    ck(!str_contains($audit,'Secret') && str_contains($audit,'user_password_reset') && str_contains($audit,'mario'),'registro attribuito senza password');
    $hash=csp_sql('SELECT password_hash FROM users WHERE id=?',[$id])->fetchColumn();ck(password_get_info($hash)['algoName']!=='unknown','password conservate soltanto come hash');
    session_write_close();foreach($checks as $s)echo 'PASS: '.$s.PHP_EOL;
}finally{foreach(glob($dir.'/*')?:[] as $f)unlink($f);if(is_dir($dir))rmdir($dir);}
