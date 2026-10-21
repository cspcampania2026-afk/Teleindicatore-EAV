<?php
// Tests never inherit the application's production DATABASE_URL.
if(PHP_SAPI==='cli'){
    $testDatabase=(string)getenv('CSP_TEST_DATABASE_URL');
    if($testDatabase!==''&&!in_array(parse_url($testDatabase,PHP_URL_HOST),['localhost','127.0.0.1'],true))throw new RuntimeException('I test PostgreSQL richiedono un database locale isolato.');
    putenv('DATABASE_URL='.$testDatabase);
}
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('CSP_TEST_SOURCE',__DIR__.'/fixture.php');
$dir=sys_get_temp_dir().'/csp-history-'.bin2hex(random_bytes(8));putenv('CSP_DATA_DIR='.$dir);putenv('EAV_TELEGRAM_BOT_TOKEN');putenv('EAV_TELEGRAM_CHANNEL_ID');putenv('EAV_TELEGRAM_GROUP_ID');
require __DIR__.'/../csp_core.php';
function verify($ok,$label){if(!$ok)throw new RuntimeException('FAIL: '.$label);echo 'PASS: '.$label.PHP_EOL;}
function live(){csp_sql('DELETE FROM cache');return csp_operations_board('801','Montesanto');}
try{
 foreach(['A','P'] as $t)$GLOBALS['fixture']['801|'.$t]=[['number'=>'9001','destination'=>'Torregaveta','time'=>$t==='A'?'12:00':'12:02','platform'=>'1','delay'=>0,'info'=>''],['number'=>'9002','destination'=>'Torregaveta','time'=>'12:30','platform'=>'2','delay'=>0,'info'=>'']];
 $initial=live();$row=$initial['rows'][0];verify(count($initial['rows'])===2,'corse live iniziali');
 $scope=csp_save_scope(['id'=>$row['id'],'line'=>'F9','scope'=>'station'],'test');verify(count(live()['rows'])===1 && csp_operations_history('801',date('Y-m-d'))['rows'][0]['historyStatus']==='SOPPRESSA','soppressa solo nel resoconto interno');
 csp_restore_scope($scope['ruleId'],'test');verify(count(live()['rows'])===2 && count(csp_operations_history('801',date('Y-m-d'))['rows'])===0,'revoca riporta la corsa live');
 csp_save_actual(['id'=>$row['id'],'actualA'=>'12:05'],'test');verify(count(live()['rows'])===1 && str_contains(csp_operations_history('801',date('Y-m-d'))['rows'][0]['historyStatus'],'manuale'),'orario manuale archivia corsa');
 csp_save_actual(['id'=>$row['id'],'actualA'=>''],'test');verify(count(live()['rows'])===2,'svuotamento effettivo ripristina corsa live');
 csp_mark_passed($row['id'],'test');verify(count(live()['rows'])===1,'passaggio segnato archiviato');
 verify(count(csp_operations_history('27',date('Y-m-d'))['rows'])===0,'resoconto separato per stazione');
 verify(count(csp_operations_history('801',date('Y-m-d',time()-86400))['rows'])===0,'resoconto separato per giornata');
 csp_sql('DELETE FROM operations_events');live();
 foreach(['A','P'] as $t)array_shift($GLOBALS['fixture']['801|'.$t]);
 verify(count(live()['rows'])===1 && str_starts_with(csp_operations_history('801',date('Y-m-d'))['rows'][0]['historyStatus'],'PASSAGGIO AUTOMATICO'),'scomparsa dopo lettura riuscita archiviata senza orario inventato');
 $before=count(csp_operations_history('801',date('Y-m-d'))['rows']);csp_reconcile_operations('801',[],['A'=>'errore','P'=>'errore']);verify(count(csp_operations_history('801',date('Y-m-d'))['rows'])===$before,'errore lettura non archivia corse');
 $row2=live()['rows'][0];$GLOBALS['fixture']['801|A']=[];verify(count(live()['rows'])===1 && count(csp_operations_history('801',date('Y-m-d'))['rows'])===$before,'scomparsa del solo arrivo non archivia partenza ancora live');
 verify(csp_operations_history('801',date('Y-m-d'))['rows'][0]['actualA']==='12:00'&&csp_operations_history('801',date('Y-m-d'))['rows'][0]['actualSourceA']==='calculated','resoconto conserva teorico come effettivo calcolato con ritardo zero');
 csp_recover_operation($row['id'],'test');$restored=live()['rows'];verify(count($restored)===2 && count(array_filter($restored,fn($r)=>!empty($r['recoveredInternal'])))===1,'recupero corsa non più EAV solo nel pannello interno');
 csp_mark_passed($row['id'],'test');verify(count(live()['rows'])===1,'nuovo passaggio dopo recupero archivia nuovamente');
 csp_recover_operation($row['id'],'test');live();$newscope=csp_save_scope(['id'=>$row['id'],'line'=>'F9','scope'=>'station'],'test');verify(count(live()['rows'])===1,'soppressione della corsa recuperata');
 csp_recover_operation($row['id'],'test');verify(count(live()['rows'])===2,'annulla e recupera revoca soppressione manuale');
 $GLOBALS['fixture']['801|P'][0]['info']='SOPPRESSO';$official=live();$officialId=csp_operations_history('801',date('Y-m-d'))['rows'][0]['id'];csp_recover_operation($officialId,'test');live();verify(count(csp_operations_history('801',date('Y-m-d'))['rows'])===1,'recupero non cancella soppressione ufficiale EAV');
 echo 'Resoconto verificato. Nessun invio reale.'.PHP_EOL;
}finally{unset($GLOBALS['fixture']);foreach(glob($dir.'/*')?:[] as $f)unlink($f);@rmdir($dir);}
