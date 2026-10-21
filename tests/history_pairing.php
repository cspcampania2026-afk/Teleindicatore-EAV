<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
putenv('DATABASE_URL=');define('CSP_TEST_SOURCE',__DIR__.'/fixture.php');
$dir=sys_get_temp_dir().'/csp-pairing-'.bin2hex(random_bytes(6));putenv('CSP_DATA_DIR='.$dir);putenv('EAV_TELEGRAM_BOT_TOKEN');
require __DIR__.'/../csp_core.php';
function paired_check(bool $value,string $label):void{if(!$value)throw new RuntimeException($label);echo 'PASS: '.$label.PHP_EOL;}
function paired_board():array{csp_sql('DELETE FROM cache');return csp_operations_board('41','Torre Annunziata');}
try{
 foreach(['A','P'] as $type)$GLOBALS['fixture']['41|'.$type]=[['number'=>'11949','destination'=>'TORRE A.TA-OPLONTI','time'=>$type==='A'?'20:02':'20:03','platform'=>'3','delay'=>0,'info'=>'']];
 $full=paired_board()['rows'][0];$GLOBALS['fixture']['41|A']=[];$partial=paired_board()['rows'][0];
 paired_check(empty($partial['theoryA'])&&$partial['theoryP']==='20:03','prima scompare il solo arrivo, partenza ancora attiva');
 $GLOBALS['fixture']['41|P']=[];paired_board();$history=csp_operations_history('41',date('Y-m-d'),['page'=>1]);
 paired_check($history['totalAll']===1&&$history['rows'][0]['theoryA']==='20:02'&&$history['rows'][0]['theoryP']==='20:03','scomparsa separata archivia una sola corsa completa');
 csp_sql('INSERT INTO operations_history VALUES (?,?,?,?,?,?)',[$partial['id'],date('Y-m-d'),'41',csp_json($partial),'PASSAGGIO AUTOMATICO · non più nel teleindicatore',time()]);
 paired_check(csp_operations_history('41',date('Y-m-d'),['page'=>1])['totalAll']===1,'doppioni preesistenti compattati prima della paginazione');
 paired_check(count(csp_daily_report('41',date('Y-m-d'))['rows'])===1,'nessun doppione nel PDF');
 $distinct=$partial;$distinct['id']=hash('sha256','other-run');$distinct['refs']=[['id'=>hash('sha256','other-movement'),'tipo'=>'P']];$distinct['historyStatus']='PASSAGGIO AUTOMATICO';
 $other=$full;$other['historyStatus']='PASSAGGIO AUTOMATICO';
 paired_check(count(csp_compact_history([$distinct,$other]))===2,'corse con stesso numero e movimenti diversi conservate');
 csp_recover_operation($full['id'],'test');paired_board();paired_check(count(csp_operations_history('41',date('Y-m-d'))['rows'])===0,'recupero non fa riapparire una copia parziale nel resoconto');
}finally{foreach(glob($dir.'/*')?:[] as $f)@unlink($f);@rmdir($dir);}
