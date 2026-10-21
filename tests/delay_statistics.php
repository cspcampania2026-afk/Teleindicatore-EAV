<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
putenv('DATABASE_URL=');define('CSP_TEST_SOURCE',__DIR__.'/fixture.php');
$dir=sys_get_temp_dir().'/csp-statistics-'.bin2hex(random_bytes(8));putenv('CSP_DATA_DIR='.$dir);putenv('EAV_TELEGRAM_BOT_TOKEN');putenv('EAV_TELEGRAM_CHANNEL_ID');putenv('EAV_TELEGRAM_GROUP_ID');require __DIR__.'/../csp_core.php';
function stats_check($ok,$label){if(!$ok)throw new RuntimeException('FAIL: '.$label);echo 'PASS: '.$label.PHP_EOL;}
try{
    foreach([['1','1002','Sorrento','Verso Napoli Porta Nolana'],['62','1001','Napoli','Da Napoli verso la linea'],['801','9001','Torregaveta','Verso Napoli Montesanto'],['819','9002','Montesanto','Da Napoli verso la linea'],['821','50734','Monte Sant’Angelo','Monte Sant’Angelo → Soccavo'],['834','50725','Soccavo','Soccavo → Monte Sant’Angelo']] as [$station,$number,$destination,$direction]){
        $row=compact('station','number','destination');stats_check(csp_internal_direction($row)['direction']===$direction,'direzione '.$number.' alla stazione '.$station);stats_check(csp_movement_absence($row,'P')==='Non prevista','sola corsa in arrivo senza partenza '.$number);
    }
    stats_check(csp_known_line(['station'=>'821','number'=>'50734','destination'=>'Soccavo'])==='F7','L7 con prefisso 5 a Soccavo');
    stats_check(csp_known_line(['station'=>'821','number'=>'5074','destination'=>'Licola'])==='F5','Circumflegrea distinta dalla navetta L7');
    $GLOBALS['fixture']['1|A']=[];$GLOBALS['fixture']['1|P']=[];
    foreach([0,8,9,15,30,null] as $i=>$delay)$GLOBALS['fixture']['1|A'][]=['number'=>(string)(1002+$i*2),'destination'=>'Sorrento','time'=>'10:'.sprintf('%02d',$i*5),'platform'=>'1','delay'=>$delay,'delayKnown'=>$delay!==null,'info'=>$i===4?'SOPPRESSO':''];
    $GLOBALS['fixture']['1|P'][]=['number'=>'1101','destination'=>'Sorrento','time'=>'11:00','platform'=>'2','delay'=>10,'info'=>''];
    $GLOBALS['fixture']['1|P'][]=['number'=>'1002','destination'=>'Sorrento','time'=>'10:02','platform'=>'1','delay'=>60,'info'=>''];
    csp_operations_board('1','Napoli Porta Nolana');$stats=csp_delay_statistics('1',date('Y-m-d'));
    stats_check($stats['count']===5&&$stats['mean']===8.4&&$stats['maximum']===15,'media e massimo su corse note, non soppresse');
    stats_check($stats['important']===3&&$stats['importantPercent']===60.0,'indice dalla soglia inclusiva di 9 minuti');
    stats_check($stats['unknown']===1&&$stats['suppressed']===1&&$stats['arrivals']===4&&$stats['departures']===1,'sconosciuti e soppressioni esclusi; sole partenze incluse');
    csp_sql('DELETE FROM cache');csp_operations_board('1','Napoli Porta Nolana');$again=csp_delay_statistics('1',date('Y-m-d'));stats_check($again['count']===$stats['count'],'poll ripetuto non moltiplica i campioni');
    stats_check(csp_delay_statistics('1',date('Y-m-d'),'F7')['count']===0,'filtri linea e assenza campioni senza medie inventate');
    $row=csp_operations_board('1','Napoli Porta Nolana')['rows'][0];$row['historyStatus']='NON CONCLUSA · ultimo dato';stats_check(csp_row_status($row)['label']==='ESITO NON RILEVATO','esito mancante distinto da corsa non effettuata');
    $GLOBALS['fixture']['834|A']=[['number'=>'50725','destination'=>'Soccavo','time'=>'07:30','platform'=>'1','delay'=>0,'info'=>'']];$GLOBALS['fixture']['834|P']=[];$l7=csp_operations_board('834','Monte Sant’Angelo')['rows'][0];stats_check($l7['theoryP']===''&&$l7['actualP']===''&&count($l7['refs'])===1,'L7 arrivo senza generare una partenza');
    if($path=getenv('CSP_STATS_PDF')){require __DIR__.'/../report_pdf.php';$report=csp_daily_report('834',date('Y-m-d'));$report['rows'][0]['historyStatus']='ESITO NON RILEVATO';file_put_contents($path,csp_report_pdf($report));}
    echo 'Statistiche e direzioni verificate. Nessun invio reale.'.PHP_EOL;
}finally{foreach(glob($dir.'/*')?:[] as $f)@unlink($f);@rmdir($dir);}
