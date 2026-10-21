<?php
// Tests never inherit the application's production DATABASE_URL.
if(PHP_SAPI==='cli'){
    $testDatabase=(string)getenv('CSP_TEST_DATABASE_URL');
    if($testDatabase!==''&&!in_array(parse_url($testDatabase,PHP_URL_HOST),['localhost','127.0.0.1'],true))throw new RuntimeException('I test PostgreSQL richiedono un database locale isolato.');
    putenv('DATABASE_URL='.$testDatabase);
}
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('CSP_TEST_SOURCE',__DIR__.'/fixture.php');$dir=sys_get_temp_dir().'/csp-telegram-'.bin2hex(random_bytes(8));putenv('CSP_DATA_DIR='.$dir);putenv('EAV_TELEGRAM_BOT_TOKEN=  OFFLINE-TOKEN  ');putenv('EAV_TELEGRAM_CHANNEL_ID=@offline');putenv('EAV_TELEGRAM_GROUP_ID');
require __DIR__.'/../csp_core.php';
function verify($ok,$label){if(!$ok)throw new RuntimeException('FAIL: '.$label);echo 'PASS: '.$label.PHP_EOL;}
try{
 $GLOBALS['telegramMock']=['status'=>401,'body'=>'{"ok":false,"error_code":401,"description":"Unauthorized"}'];
 try{csp_telegram_rpc('getMe');throw new LogicException('Errore mancante');}catch(RuntimeException $e){verify(str_contains($e->getMessage(),'token non valido'),'401 con istruzioni token');}
 verify(!str_contains($GLOBALS['mockUrl'],' '),'spazi esterni del token rimossi');
 try{csp_queue('group','prova','group');throw new LogicException('Errore mancante');}catch(RuntimeException $e){verify(str_contains($e->getMessage(),'Canale associato'),'gruppo assente suggerisce il canale');}
 csp_queue('channel','Messaggio offline.','channel');csp_flush();$pending=csp_sql('SELECT * FROM outbox')->fetch(PDO::FETCH_ASSOC);verify($pending['status']==='pending'&&str_contains($pending['last_error'],'token non valido')&&!str_contains($pending['last_error'],'OFFLINE-TOKEN'),'invio 401 resta in coda con errore senza segreti');
 $GLOBALS['telegramMock']=['status'=>200,'body'=>'{"ok":true,"result":{"username":"offline_bot","first_name":"Offline"}}'];$count=csp_sql('SELECT COUNT(*) FROM outbox')->fetchColumn();csp_telegram_rpc('getMe');verify(csp_sql('SELECT COUNT(*) FROM outbox')->fetchColumn()===$count,'verifica bot senza nuovo invio');
 csp_sql('UPDATE outbox SET next_try=0');csp_flush();verify(csp_sql('SELECT status FROM outbox')->fetchColumn()==='sent','consegna solo dopo risposta positiva');
 $input=['structured'=>'1','kind'=>'delay','number'=>'1081','destination'=>'Sorrento','time'=>'05:41','delay'=>'10'];$text=csp_manual_message($input);verify(str_contains($text,'<code>1081</code>')&&str_contains($text,'<b>+10 minuti</b>')&&str_contains($text,'05:41')&&str_contains($text,'LINEE VESUVIANE'),'modello richiesto per messaggi manuali');
 $escaped=csp_manual_message(array_merge($input,['destination'=>'<b>Test & prova</b>']));verify(str_contains($escaped,'&lt;b&gt;Test &amp; prova&lt;/b&gt;'),'escape dei campi Telegram');
 csp_queue('html',$text,'channel','HTML');verify(csp_sql('SELECT parse_mode FROM outbox WHERE event_key=?',['html'])->fetchColumn()==='HTML','formattazione memorizzata in coda senza troncare tag');
 echo 'Telegram verificato con risposte simulate, senza rete.'.PHP_EOL;
}finally{foreach(glob($dir.'/*')?:[] as $f)unlink($f);@rmdir($dir);}
