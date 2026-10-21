<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
putenv('DATABASE_URL=');define('CSP_TEST_SOURCE',__DIR__.'/fixture.php');$dir=sys_get_temp_dir().'/csp-effective-'.bin2hex(random_bytes(6));putenv('CSP_DATA_DIR='.$dir);putenv('EAV_TELEGRAM_BOT_TOKEN');
require __DIR__.'/../csp_core.php';
function effective_check($value,$label){if(!$value)throw new RuntimeException($label);echo 'PASS: '.$label.PHP_EOL;}
try{
 foreach(['A','P'] as $type)$GLOBALS['fixture']['41|'.$type]=[['number'=>'11949','destination'=>'Torre Annunziata','time'=>$type==='A'?'23:55':'23:57','platform'=>'3','delay'=>$type==='A'?10:0,'info'=>'']];
 $board=csp_operations_board('41','Torre Annunziata');$row=$board['rows'][0];
 effective_check($row['actualA']==='00:05'&&$row['actualP']==='23:57'&&$row['actualSourceA']==='calculated','ritardo e zero ritardo calcolati con cambio giornata');
 csp_sql('DELETE FROM cache');$GLOBALS['fixture']['41|A'][0]['delay']=15;$row=csp_operations_board('41','Torre Annunziata')['rows'][0];effective_check($row['actualA']==='00:10','effettivo aggiornato quando varia il ritardo');
 csp_save_actual(['id'=>$row['id'],'actualA'=>'00:12'],'operatore');$row=csp_operations_board('41','Torre Annunziata')['rows'];$history=csp_operations_history('41',date('Y-m-d'));$id=$history['rows'][0]['id'];
 effective_check($history['rows'][0]['actualA']==='00:12'&&$history['rows'][0]['actualSourceA']==='manual','manuale prioritario e conservato nel resoconto');
 csp_save_history_edit(['id'=>$id,'station'=>'41','day'=>date('Y-m-d'),'note'=>'Nota corretta','reportInfo'=>'Binario comunicato corretto','binP'=>'5','actualP'=>'00:02','destination'=>'Torre Annunziata / Oplonti'],'anna');
 csp_operations_board('41','Torre Annunziata');$details=csp_train_detail($id,'41',date('Y-m-d'));
 effective_check($details['row']['note']==='Nota corretta'&&$details['row']['binP']==='5'&&$details['row']['actualP']==='00:02'&&$details['row']['actualSourceP']==='report','correzioni archivio persistono dopo nuovo aggiornamento');
 effective_check($details['editable']&&$details['row']['historyEdit']['actor']==='anna'&&count(array_filter($details['events'],fn($e)=>$e['action']==='history_edit'))===1,'modifica attribuita e registrata con valori precedenti');
 $pdfData=csp_daily_report('41',date('Y-m-d'));effective_check($pdfData['rows'][0]['reportInfo']==='Binario comunicato corretto','integrazioni comprese nel report completo');
 $past=date('Y-m-d',strtotime('-1 day'));$pastRow=$details['row'];$pastRow['id']=hash('sha256','past-edit');csp_sql('INSERT INTO operations_history VALUES (?,?,?,?,?,?)',[$pastRow['id'],$past,'41',csp_json($pastRow),'PASSAGGIO AUTOMATICO',time()-86400]);
 csp_save_history_edit(['id'=>$pastRow['id'],'station'=>'41','day'=>$past,'note'=>'Correzione giorno precedente'],'anna');effective_check(csp_operations_history('41',$past)['rows'][0]['note']==='Correzione giorno precedente'&&count(csp_train_detail($pastRow['id'],'41',$past)['events'])>=1,'note modificabili anche sulle giornate precedenti');
 $unknown=csp_complete_effective(['theoryA'=>'10:00','delayA'=>0,'delayKnownA'=>false]);effective_check($unknown['actualA']==='10:00'&&$unknown['actualSourceA']==='calculated'&&!$unknown['delayKnownA'],'ritardo non segnalato: effettivo uguale al teorico senza alterare il dato ritardo');
 foreach([null,''] as $delay){$zero=csp_complete_effective(['theoryA'=>'05:41:00','delayA'=>$delay,'theoryP'=>'05:42:00','delayP'=>$delay]);effective_check($zero['actualA']==='05:41:00'&&$zero['actualP']==='05:42:00','ritardo assente: effettivi uguali ai teorici con secondi');}
 $absent=csp_complete_effective(['theoryA'=>'10:00','theoryP'=>'','delayA'=>0,'delayP'=>null]);effective_check(empty($absent['actualP']),'partenza non prevista resta senza effettivo');
 $savedZero=$pastRow;$savedZero['id']=hash('sha256','saved-empty-effective');$savedZero['actualA']='';$savedZero['actualSourceA']='';$savedZero['delayA']=0;$savedZero['delayKnownA']=false;csp_sql('INSERT INTO operations_history VALUES (?,?,?,?,?,?)',[$savedZero['id'],$past,'41',csp_json($savedZero),'PASSAGGIO AUTOMATICO',time()-86400]);$loaded=csp_train_detail($savedZero['id'],'41',$past)['row'];effective_check($loaded['actualA']===$loaded['theoryA'],'vecchio resoconto senza effettivo corretto automaticamente alla lettura');
 $suppressed=csp_complete_effective(['theoryA'=>'10:00','delayA'=>10,'suppressedA'=>true]);effective_check(empty($suppressed['actualA']),'nessun effettivo automatico per treno soppresso');
 $rejected=false;try{csp_save_history_edit(['id'=>$id,'station'=>'41','day'=>date('Y-m-d'),'actualA'=>'99:99'],'anna');}catch(InvalidArgumentException $e){$rejected=true;}effective_check($rejected,'orari errati rifiutati senza sovrascrivere archivio');
 if(getenv('CSP_EFFECTIVE_PDF')){require __DIR__.'/../report_pdf.php';file_put_contents(getenv('CSP_EFFECTIVE_PDF'),csp_report_pdf($pdfData));}
}finally{foreach(glob($dir.'/*')?:[] as $f)@unlink($f);@rmdir($dir);}
