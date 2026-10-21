<?php
// Tests never inherit the application's production DATABASE_URL.
if(PHP_SAPI==='cli'){
    $testDatabase=(string)getenv('CSP_TEST_DATABASE_URL');
    if($testDatabase!==''&&!in_array(parse_url($testDatabase,PHP_URL_HOST),['localhost','127.0.0.1'],true))throw new RuntimeException('I test PostgreSQL richiedono un database locale isolato.');
    putenv('DATABASE_URL='.$testDatabase);
}
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('CSP_TEST_SOURCE',__DIR__.'/fixture.php');
$dir=sys_get_temp_dir().'/csp-monitor-test-'.bin2hex(random_bytes(8));
putenv('CSP_DATA_DIR='.$dir);putenv('EAV_TELEGRAM_BOT_TOKEN');putenv('EAV_TELEGRAM_CHANNEL_ID');putenv('EAV_TELEGRAM_GROUP_ID');putenv('EAV_MONITOR_STATIONS=834,821');putenv('EAV_MONITOR_TYPES=P,A');
$argv=['monitor.php','--once'];
try{
 require __DIR__.'/../monitor.php';
 $health=json_decode(csp_sql('SELECT payload FROM health WHERE id=?',['worker'])->fetchColumn(),true);
 if(($health['state']??'')!=='completed'||($health['stations']??0)!==2)throw new RuntimeException('Monitor 834,821 non completato.');
 foreach(['834|A','834|P','821|A','821|P'] as $id){$status=json_decode(csp_sql('SELECT payload FROM health WHERE id=?',['station:'.$id])->fetchColumn(),true);if(empty($status['ok']))throw new RuntimeException('Lettura fixture non riuscita: '.$id);}
 echo 'PASS: monitor con 834 e 821 completa il ciclo, nessuna chiamata di rete.'.PHP_EOL;
}finally{foreach(glob($dir.'/*')?:[] as $f)@unlink($f);@rmdir($dir);}
