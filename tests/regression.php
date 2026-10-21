<?php
// Tests never inherit the application's production DATABASE_URL.
if(PHP_SAPI==='cli'){
    $testDatabase=(string)getenv('CSP_TEST_DATABASE_URL');
    if($testDatabase!==''&&!in_array(parse_url($testDatabase,PHP_URL_HOST),['localhost','127.0.0.1'],true))throw new RuntimeException('I test PostgreSQL richiedono un database locale isolato.');
    putenv('DATABASE_URL='.$testDatabase);
}
if(PHP_SAPI!=='cli') {http_response_code(404);exit;}
// Offline integration test, isolated SQLite database; no credentials or real messages.
define('CSP_TEST_SOURCE',__DIR__.'/fixture.php');
$testDir=sys_get_temp_dir().'/csp-regression-'.bin2hex(random_bytes(8));
putenv('CSP_DATA_DIR='.$testDir);putenv('EAV_TELEGRAM_BOT_TOKEN');putenv('EAV_TELEGRAM_CHANNEL_ID');putenv('EAV_TELEGRAM_GROUP_ID');
require __DIR__.'/../csp_core.php';
function check($condition,$message) {if(!$condition) throw new RuntimeException('FAIL: '.$message);echo 'PASS: '.$message.PHP_EOL;}
function board($s,$type) {return csp_apply(csp_source($s,$type,'Stazione test',true));}
function rejected(callable $f) {try{$f();return false;}catch(InvalidArgumentException $e){return true;}}
try {
    putenv('CSP_ADMIN_USER=test-admin');putenv('CSP_ADMIN_PASSWORD_HASH');putenv('CSP_ADMIN_PASSWORD');
    check(!csp_credentials_valid('test-admin','anything'),'nessuna password predefinita');
    $testPassword='TestOnly!2026#CSP';putenv('CSP_ADMIN_PASSWORD='.$testPassword);
    check(csp_credentials_valid('test-admin',$testPassword),'password dal segreto runtime');
    check(!csp_credentials_valid('wrong-user',$testPassword) && !csp_credentials_valid('test-admin','wrong-password'),'rifiuto utente e password errati');
    putenv('CSP_ADMIN_PASSWORD_HASH='.password_hash('HashOnly!2026',PASSWORD_DEFAULT));
    check(csp_credentials_valid('test-admin','HashOnly!2026') && !csp_credentials_valid('test-admin',$testPassword),'hash configurato ha precedenza sulla password runtime');
    putenv('CSP_ADMIN_PASSWORD_HASH');putenv('CSP_ADMIN_PASSWORD');putenv('CSP_ADMIN_USER');
    foreach(['801','803','808','819'] as $s) foreach(['A','P'] as $type) {
        $GLOBALS['fixture'][$s.'|'.$type]=[
          ['number'=>'9001','destination'=>'Torregaveta','time'=>$type==='A'?'17:30':'17:32','platform'=>'2','delay'=>9,'info'=>'Fermate','category'=>'DD'],
          ['number'=>'9002','destination'=>'Torregaveta','time'=>'18:00','platform'=>'1','delay'=>0,'info'=>'','category'=>'DD']];
        board($s,$type);
    }
    $ops=csp_operations_board('801','Montesanto');$row=$ops['rows'][0];
    check(count($ops['rows'])===2 && $row['theoryA']==='17:30' && $row['theoryP']==='17:32','arrivo e partenza teorici da sorgenti separate');
    check($row['actualA']==='17:39' && $row['actualP']==='17:41' && $row['actualSourceA']==='calculated','effettivi calcolati dal ritardo e distinti dai manuali');
    $result=csp_save_scope(['id'=>$row['id'],'line'=>'F9','scope'=>'range','from'=>'801','to'=>'808'],'test');
    check(!board('801','A')['trains'][0]['suppressed'] && board('801','P')['trains'][0]['suppressed'],'tratta: solo partenza alla stazione iniziale');
    check(board('803','A')['trains'][0]['suppressed'] && board('803','P')['trains'][0]['suppressed'],'tratta: entrambi i movimenti nelle stazioni intermedie');
    check(board('808','A')['trains'][0]['suppressed'] && !board('808','P')['trains'][0]['suppressed'],'tratta: solo arrivo alla stazione finale');
    check(!board('819','A')['trains'][0]['suppressed'] && !board('803','A')['trains'][1]['suppressed'],'stazioni fuori tratta e altre corse non soppresse');
    csp_save_scope(['id'=>$row['id'],'line'=>'F9','scope'=>'range','from'=>'808','to'=>'801'],'test');
    check(board('801','A')['trains'][0]['suppressed'] && !board('801','P')['trains'][0]['suppressed'],'tratta nel verso inverso');
    csp_save_scope(['id'=>$row['id'],'line'=>'F9','scope'=>'line'],'test');
    check(board('819','A')['trains'][0]['suppressed'] && board('819','P')['trains'][0]['suppressed'],'soppressione sull’intero percorso');
    check(rejected(fn()=>csp_save_scope(['id'=>$row['id'],'line'=>'F9','scope'=>'range','from'=>'801','to'=>'834'],'test')),'rifiuto estremi non presenti nel percorso');
    check(rejected(fn()=>csp_save_actual(['id'=>$row['id'],'actualA'=>'30:17'],'test')),'rifiuto orari impossibili');
    csp_save_actual(['id'=>$row['id'],'actualA'=>'17:39:00','actualP'=>'17:42:00','binA'=>'4','binP'=>'5','note'=>'Nota solo interna'],'test');
    csp_operations_board('801','Montesanto');$updated=csp_operations_history('801',date('Y-m-d'))['rows'][0];
    check($updated['actualA']==='17:39:00' && $updated['actualP']==='17:42:00' && $updated['actualSourceA']==='manual','orari effettivi manuali memorizzati e attribuiti');
    check(board('801','A')['trains'][0]['platform']==='4' && board('801','P')['trains'][0]['platform']==='5','binari distinti per arrivo e partenza');
    csp_restore_scope($result['ruleId'],'test');
    check(!board('819','P')['trains'][0]['suppressed'],'ripristino dell’intero percorso');
    $GLOBALS['fixture']['819|P'][0]['info']='SOPPRESSO';
    check(board('819','P')['trains'][0]['suppressed'],'ripristino manuale non cancella la soppressione EAV');
    csp_save_scope(['id'=>$row['id'],'line'=>'F9','scope'=>'line'],'test');
    $p=board('801','P');csp_sql('INSERT INTO snapshots VALUES (?,?,?) ON CONFLICT(id) DO UPDATE SET payload=excluded.payload,day=excluded.day',['801|P',csp_json(array_column($p['trains'],null,'id')),date('Y-m-d')]);
    csp_manual(['station'=>'801','tipo'=>'P','operation'=>'remove','id'=>$p['trains'][0]['id']],'test');
    check(count(board('801','P')['trains'])===1,'rimozione locale ha precedenza sulla regola di soppressione');

    $req=['request_id'=>str_repeat('a',32),'kind'=>'limitazione','message'=>'Corsa 9001 limitata a Bagnoli.','station'=>'801','hours'=>'1'];
    csp_save_notice($req,'test');csp_save_notice($req,'test');
    check(count(csp_public_notices('801'))===1 && count(csp_public_notices('27'))===0,'avviso per stazione e idempotenza pubblicazione');
    csp_save_notice(['request_id'=>str_repeat('b',32),'kind'=>'generale','message'=>'Servizio in aggiornamento.','station'=>'ALL','hours'=>'1'],'test');
    check(count(csp_public_notices('27'))===1 && count(csp_public_notices('801'))===2,'avviso globale visibile su tutte le stazioni');
    csp_revoke_notice(str_repeat('a',32),'test');check(count(csp_public_notices('801'))===1,'ritiro avviso');
    csp_sql('UPDATE notices SET expires=?',[time()-1]);check(count(csp_public_notices('801'))===0,'scadenza automatica avvisi');
    $pending=csp_sql('SELECT COUNT(*) FROM outbox')->fetchColumn();$preview=csp_manual_message(['message'=>'Prova di collegamento.','test'=>'1']);
    check($preview==='[PROVA] Prova di collegamento.' && $pending===csp_sql('SELECT COUNT(*) FROM outbox')->fetchColumn(),'simulazione senza invio o accodamento');
    check(rejected(fn()=>csp_manual_message(['message'=>str_repeat('a',281)])),'limite messaggi brevi');
    check(rejected(fn()=>csp_manual_message(['message'=>'Corsa [numero] soppressa.'])),'rifiuto modello non completato');
    check(mb_strlen(csp_brief('suppress',['number'=>'9001','destination'=>'Torregaveta'],'Montesanto',str_repeat('tratta ',100)))<=1000,'notifiche automatiche strutturate e compatte');
    check(csp_stations()['27']==='San Giorgio a Cremano','nome esatto San Giorgio a Cremano');
    check(csp_stations()['834']==='Monte Sant’Angelo' && isset(csp_stations()['821']),'monitor riconosce Monte Sant’Angelo 834 e Soccavo 821');
    check(csp_context(['station'=>'834'])[0]==='834','stazione 834 valida anche negli endpoint');
    $routeIds=array_unique(array_merge(...array_column(csp_routes(),'ids')));check(!array_diff($routeIds,array_keys(csp_stations())),'tutte le stazioni dei percorsi sono disponibili al monitor');
    $routeKeys=array_keys(csp_routes());check($routeKeys[0]==='L1' && $routeKeys[1]==='TORRE' && str_starts_with(csp_routes()['TORRE']['label'],'L1bis'),'L1bis subito dopo L1');

    foreach(['snapshots','overrides','route_rules','operations_events','outbox'] as $table)csp_db()->exec('DELETE FROM '.$table);
    putenv('EAV_TELEGRAM_BOT_TOKEN=TEST-NOT-A-REAL-TOKEN');putenv('EAV_TELEGRAM_CHANNEL_ID=TEST-CHAT');
    $GLOBALS['fixture']['801|P'][0]['info']='Ferma a: Bagnoli';$GLOBALS['fixture']['801|P'][0]['platform']='2';$GLOBALS['fixture']['801|P'][0]['delay']=0;
    csp_poll('801','P','Montesanto');check((int)csp_sql('SELECT COUNT(*) FROM outbox')->fetchColumn()===0,'primo ciclo senza messaggi sulle nuove corse normali');
    $GLOBALS['fixture']['801|P'][0]['platform']='3';csp_poll('801','P','Montesanto');
    $GLOBALS['fixture']['801|P'][0]['delay']=10;csp_poll('801','P','Montesanto');
    $GLOBALS['fixture']['801|P'][0]['info']='Ferma a: '.str_repeat('Bagnoli ',80);csp_poll('801','P','Montesanto');
    check((int)csp_sql('SELECT COUNT(*) FROM outbox')->fetchColumn()===0,'partenze: nessun cambio binario automatico e ritardi riservati al capolinea di arrivo');
    $GLOBALS['fixture']['801|P'][0]['info']='Corsa limitata a Bagnoli';csp_poll('801','P','Montesanto');
    $GLOBALS['fixture']['801|P'][0]['info']='SOPPRESSO';csp_poll('801','P','Montesanto');
    $GLOBALS['fixture']['801|P'][0]['info']='';csp_poll('801','P','Montesanto');
    $msgs=csp_sql('SELECT message FROM outbox')->fetchAll(PDO::FETCH_COLUMN);check(count($msgs)===3 && max(array_map('mb_strlen',$msgs))<=1000,'messaggi automatici strutturati per limitazione, soppressione e ripristino, senza binari');
    putenv('EAV_TELEGRAM_BOT_TOKEN');putenv('EAV_TELEGRAM_CHANNEL_ID');
    echo "\nTutti i controlli superati. Nessun messaggio Telegram inviato.\n";
} finally {
    foreach(glob($testDir.'/*')?:[] as $file) unlink($file);if(is_dir($testDir)) rmdir($testDir);
}
