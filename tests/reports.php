<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$test=(string)getenv('CSP_TEST_DATABASE_URL');if($test!==''&&!in_array(parse_url($test,PHP_URL_HOST),['localhost','127.0.0.1'],true))throw new RuntimeException('Database test non locale.');putenv('DATABASE_URL='.$test);
define('CSP_TEST_SOURCE',__DIR__.'/fixture.php');$dir=sys_get_temp_dir().'/csp-report-test-'.bin2hex(random_bytes(6));putenv('CSP_DATA_DIR='.$dir);putenv('EAV_TELEGRAM_BOT_TOKEN');putenv('EAV_TELEGRAM_CHANNEL_ID');
require __DIR__.'/../csp_core.php';require __DIR__.'/../report_pdf.php';
function report_check($ok,$label){if(!$ok)throw new RuntimeException('FAIL: '.$label);echo 'PASS: '.$label.PHP_EOL;}
function report_live(){csp_sql('DELETE FROM cache');return csp_operations_board('801','Montesanto');}
try{
    foreach(['A','P']as $tipo){$GLOBALS['fixture']['801|'.$tipo]=[];for($i=0;$i<83;$i++)$GLOBALS['fixture']['801|'.$tipo][]=['number'=>(string)(9001+$i),'destination'=>'Torregaveta','time'=>sprintf('%02d:%02d',5+intdiv($i,60),$i%60),'platform'=>'2','delay'=>0,'info'=>'Ferma a: Corso Vittorio Emanuele, Pozzuoli, Torregaveta.'];}
    $live=report_live();$manual=$live['rows'][0];$suppressed=$live['rows'][1];
    csp_save_actual(['id'=>$manual['id'],'actualA'=>'05:01','note'=>'Nota con accenti: attività, perché, Monte Sant’Angelo.'],'operatore.rossi');
    csp_save_scope(['id'=>$suppressed['id'],'line'=>'F9','scope'=>'range','from'=>'801','to'=>'808'],'admin');report_live();
    $GLOBALS['fixture']['801|A']=[];$GLOBALS['fixture']['801|P']=[];report_live();
    $day=date('Y-m-d');$all=csp_operations_history('801',$day);report_check(count($all['rows'])===83,'tutte le 83 corse conservate, senza limite al resoconto');
    $page=csp_operations_history('801',$day,['page'=>1,'page_size'=>25]);$last=csp_operations_history('801',$day,['page'=>4,'page_size'=>25]);report_check($page['pages']===4&&count($page['rows'])===25&&count($last['rows'])===8,'paginazione completa e pagina finale');
    report_check($page['rows'][0]['number']==='9001'&&$last['rows'][7]['number']==='9083','ordine cronologico dall’inizio della giornata');
    report_check($all['coverage']!==null&&(int)csp_sql('SELECT COUNT(*) FROM history_watch WHERE station=?',['801'])->fetchColumn()===1,'copertura e registrazione stazione per raccolta in background');
    $a=csp_train_detail($manual['id'],'801',$day);$b=csp_train_detail($suppressed['id'],'801',$day);$c=csp_train_detail($all['rows'][2]['id'],'801',$day);
    report_check($a['status']['kind']==='manual'&&$b['status']['kind']==='suppressed'&&$c['status']['kind']==='automatic','stati manuale, soppresso e automatico distinti');
    report_check($a['row']['actualA']==='05:01'&&str_contains($a['row']['note'],'attività')&&count($a['events'])===1,'popup conserva orari, nota e attività attribuita');
    report_check($b['row']['suppressionDetails']['scope']==='range'&&$b['row']['suppressionDetails']['stations'][0]==='801'&&end($b['row']['suppressionDetails']['stations'])==='808','ambito da stazione a stazione conservato anche nell’archivio');
    $r=csp_daily_report('801',$day);$bytes=csp_report_pdf($r);report_check(count($r['rows'])===83&&str_starts_with($bytes,'%PDF-1.4')&&substr_count($bytes,'/Type /Page ')>1,'PDF completo multipagina, indipendente dalla pagina visualizzata');
    if($path=getenv('CSP_REPORT_SAMPLE')){$sample=$r;$sample['rows']=array_slice($r['rows'],0,5);$sample['stationName'].=' - SIMULAZIONE';$sample['rows'][0]['note']=str_repeat('Nota di prova lunga: attività registrata dall’operatore, senza modificare gli orari effettivi. ',65);file_put_contents($path,csp_report_pdf($sample));}
    $yesterday=date('Y-m-d',strtotime('-1 day'));$row=$manual;$row['id']=hash('sha256','previousday');$row['actualA']='';$row['actualSourceA']='';csp_sql('INSERT INTO operations_rows VALUES (?,?,?,?,?,?)',[$row['id'],$yesterday,'801',$row['number'],csp_json($row),time()-86400]);csp_finalize_previous_days();report_check(count(csp_operations_history('801',$yesterday)['rows'])===1,'ultimo dato non concluso conservato al cambio giornata');
    $filtered=csp_operations_history('801',$day,['page'=>1,'query'=>'9083']);report_check($filtered['total']===1&&$filtered['rows'][0]['number']==='9083','ricerca applicata prima della paginazione');
}finally{foreach(glob($dir.'/*')?:[]as $f)@unlink($f);@rmdir($dir);}
