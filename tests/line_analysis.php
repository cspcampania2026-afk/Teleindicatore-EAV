<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
putenv('DATABASE_URL=');define('CSP_TEST_SOURCE',__DIR__.'/fixture.php');$dir=sys_get_temp_dir().'/csp-analysis-'.bin2hex(random_bytes(8));putenv('CSP_DATA_DIR='.$dir);putenv('EAV_TELEGRAM_BOT_TOKEN');putenv('EAV_TELEGRAM_CHANNEL_ID');putenv('EAV_TELEGRAM_GROUP_ID');require __DIR__.'/../csp_core.php';
function analysis_check($ok,$label){if(!$ok)throw new RuntimeException('FAIL: '.$label);echo 'PASS: '.$label.PHP_EOL;}
function feed($station,$type,$number,$delay,$time='10:00',$destination='Sorrento'){$GLOBALS['fixture'][$station.'|'.$type][]=compact('number','delay','time','destination')+['platform'=>'1','info'=>'','delayKnown'=>$delay!==null];}
try{
 $day=date('Y-m-d');feed('27','A','1001',4);feed('27','P','1001',5,'10:01');feed('62','A','1001',15,'10:45','Napoli');feed('1','A','4002',9,'10:30','Poggiomarino');feed('27','A','4002',20,'10:00');feed('834','A','50725',null,'11:00','Soccavo');
 foreach(['27','62','1','834'] as $station)csp_operations_board($station,csp_stations()[$station]);
 $network=csp_analysis_samples('27',$day,'network');$by=[];foreach($network['samples'] as $s)$by[$s['number']]=$s;
 analysis_check(count($by)===3,'una corsa per linea/giornata, senza duplicati tra stazioni o A/P');
 analysis_check($by['1001']['delay']===15&&$by['1001']['station']==='62'&&$by['1001']['terminal'],'preferito arrivo al capolinea Sorrento');
 analysis_check($by['4002']['delay']===9&&$by['4002']['station']==='1','Poggiomarino pari verso Napoli, ritardo del capolinea');
 analysis_check($by['50725']['line']==='F7'&&$by['50725']['delay']===null,'L7 identificata e ritardo sconosciuto non trasformato in zero');
 $station=csp_analysis_samples('27',$day,'station');analysis_check(count($station['samples'])===2,'ambito singola stazione senza dati delle altre');
 $terminal=$by['1001'];$saved=csp_sql('SELECT payload FROM operations_rows WHERE id=?',[$terminal['id']])->fetchColumn();csp_sql('INSERT INTO operations_history VALUES (?,?,?,?,?,?)',[$terminal['id'],$day,'62',$saved,'PASSAGGIO AUTOMATICO',time()]);
 csp_save_history_edit(['id'=>$terminal['id'],'station'=>'62','day'=>$day,'delayA'=>'22'],'test');$edited=csp_analysis_samples('27',$day,'network');$match=array_values(array_filter($edited['samples'],fn($s)=>$s['number']==='1001'))[0];analysis_check($match['delay']===22,'analisi include le correzioni dell’archivio');
 $fallback=array_values(array_filter($station['samples'],fn($s)=>$s['number']==='1001'))[0];analysis_check(!$fallback['terminal']&&$fallback['type']==='A','fallback intermedio indicato esplicitamente');
 analysis_check(count(csp_analysis_samples('27',date('Y-m-d',strtotime('-1 day')),'network')['samples'])===0,'giornata vuota senza dati inventati');
 $bad=false;try{csp_analysis_samples('27',$day,'invalid');}catch(InvalidArgumentException $e){$bad=true;}analysis_check($bad,'ambito errato rifiutato');
 echo 'Analisi linee verificata. Nessun accesso esterno o invio Telegram.'.PHP_EOL;
}finally{foreach(glob($dir.'/*')?:[] as $f)@unlink($f);@rmdir($dir);}
