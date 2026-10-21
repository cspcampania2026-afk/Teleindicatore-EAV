<?php
// Tests never inherit the application's production DATABASE_URL.
if(PHP_SAPI==='cli'){
    $testDatabase=(string)getenv('CSP_TEST_DATABASE_URL');
    if($testDatabase!==''&&!in_array(parse_url($testDatabase,PHP_URL_HOST),['localhost','127.0.0.1'],true))throw new RuntimeException('I test PostgreSQL richiedono un database locale isolato.');
    putenv('DATABASE_URL='.$testDatabase);
}
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('CSP_TEST_SOURCE',__DIR__.'/fixture.php');$dir=sys_get_temp_dir().'/csp-delay-'.bin2hex(random_bytes(8));putenv('CSP_DATA_DIR='.$dir);putenv('EAV_TELEGRAM_BOT_TOKEN=OFFLINE');putenv('EAV_TELEGRAM_CHANNEL_ID=@offline');
require __DIR__.'/../csp_core.php';
function check_delay($ok,$label){if(!$ok)throw new RuntimeException('FAIL: '.$label);echo 'PASS: '.$label.PHP_EOL;}
function feed_delay($station,$type,$number,$dest,$delay,$time='23:13'){$GLOBALS['fixture'][$station.'|'.$type]=[['number'=>$number,'destination'=>$dest,'delay'=>$delay,'time'=>$time,'platform'=>'1','info'=>'']];csp_poll($station,$type,csp_stations()[$station]);}
function count_delay(){return (int)csp_sql("SELECT COUNT(*) FROM outbox WHERE event_key LIKE 'terminal-delay:%'")->fetchColumn();}
try{
 check_delay(count(csp_delay_terminals())===12,'i dodici capolinea richiesti');
 feed_delay('1','P','1082','Sorrento',30);check_delay(count_delay()===0,'ritardo alla partenza non inviato');
 feed_delay('41','A','1082','Napoli Porta Nolana',30);check_delay(count_delay()===0,'Torre è intermedia per corsa Sorrento');
 feed_delay('1','A','1082','Sorrento',30);check_delay(count_delay()===0,'origine non scambiata per destinazione di arrivo');
 feed_delay('62','A','1082','Napoli Porta Nolana',8);check_delay(count_delay()===0,'sotto nove minuti nessun avviso');
 feed_delay('62','A','1082','Napoli Porta Nolana',9);check_delay(count_delay()===1,'nove minuti inclusi');
 feed_delay('62','A','1082','Napoli Porta Nolana',11);check_delay(count_delay()===1,'ulteriore aumento senza doppioni');
 $message=csp_sql("SELECT message FROM outbox WHERE event_key LIKE 'terminal-delay:%'")->fetchColumn();check_delay(str_contains($message,'Sorrento')&&!str_contains($message,'Napoli Porta Nolana')&&str_contains($message,'Arrivo programmato')&&str_contains($message,'23:13'),'testo con destinazione e ora di arrivo al capolinea');
 foreach([10,12,14,5,0,10] as $delay)feed_delay('62','A','1082','Napoli Porta Nolana',$delay,'23:14');check_delay(count_delay()===1,'nessun duplicato del ritardo importante per variazioni e orari');
 check_delay((int)csp_sql("SELECT COUNT(*) FROM outbox WHERE event_key LIKE 'terminal-ontime:%'")->fetchColumn()===1,'ritardo rientrato inviato una sola volta dopo ritardo importante');feed_delay('62','A','1082','Napoli Porta Nolana',0);check_delay((int)csp_sql("SELECT COUNT(*) FROM outbox WHERE event_key LIKE 'terminal-ontime:%'")->fetchColumn()===1,'nessun duplicato del rientro');
 csp_sql('DELETE FROM snapshots');feed_delay('62','A','1082','Napoli Porta Nolana',10);check_delay(count_delay()===1,'deduplicazione persistente senza snapshot');
 foreach([15,17,20,12,20] as $delay)feed_delay('62','A','1082','Napoli Porta Nolana',$delay);check_delay(count_delay()===1,'nessun secondo avviso anche se il ritardo aumenta');
 feed_delay('62','A','1082','Napoli Porta Nolana',27);check_delay(count_delay()===1,'ulteriore aumento non ripete il messaggio');$last=csp_sql("SELECT message FROM outbox WHERE event_key LIKE 'terminal-delay:%' ORDER BY id DESC LIMIT 1")->fetchColumn();check_delay(str_contains($last,'+9 minuti'),'conservato il singolo avviso iniziale');
 feed_delay('84','P','80609','Volla',20);feed_delay('70','A','80609','Baiano',12);check_delay(count_delay()===2,'capolinea Volla');
 feed_delay('801','P','5001','Licola',20);feed_delay('821','A','5001','Montesanto',20);check_delay(count_delay()===2,'Soccavo non capolinea per corsa L5');feed_delay('830','A','5001','Montesanto',11);check_delay(count_delay()===3,'capolinea Licola');
 feed_delay('821','P','7001','Monte Sant’Angelo',20);feed_delay('834','A','7001','Soccavo',11);check_delay(count_delay()===4,'capolinea Monte Sant’Angelo L7');
 csp_queue('auto:old','⏱ Ritardo attuale: rientrato');csp_queue('message:manual','Ritardo attuale: prova manuale');csp_cancel_legacy_delays();check_delay(csp_sql('SELECT status FROM outbox WHERE event_key=?',['auto:old'])->fetchColumn()==='cancelled'&&csp_sql('SELECT status FROM outbox WHERE event_key=?',['message:manual'])->fetchColumn()==='pending','vecchi avvisi automatici in coda annullati, manuali mantenuti');
 // One train is visible at many monitored stations; only its actual terminal may notify.
 feed_delay('1','P','10745','TORRE A.TA - OPLONTI',20);
 foreach(['1','22','62','25','84'] as $station)feed_delay($station,'A','10745','Napoli Porta Nolana',25);
 check_delay(count_delay()===4,'nessun avviso da altri capolinea per la corsa Torre');
 feed_delay('41','A','10745','Napoli Porta Nolana',11);feed_delay('41','A','10745','Napoli Porta Nolana',40);
 check_delay(count_delay()===5,'Torre Annunziata: un solo messaggio per la corsa anche con aumento');
 $torre=csp_sql("SELECT message FROM outbox WHERE event_key LIKE 'terminal-delay:%' ORDER BY id DESC LIMIT 1")->fetchColumn();check_delay(str_contains($torre,'Torre Annunziata')&&str_contains($torre,'<b>LINEE VESUVIANE</b>'),'capolinea effettivo e intestazione Vesuviane evidente');
 // A real destination overrides a numbering-family assumption (e.g. an unusual or limited service).
 feed_delay('1','P','12202','Sorrento',20);feed_delay('62','A','12202','Napoli Porta Nolana',12);check_delay(count_delay()===6,'destinazione effettiva prevale sul catalogo della linea');
 $flegree=csp_brief('delay',['number'=>'9001','destination'=>'Torregaveta','delay'=>10]);check_delay(str_contains($flegree,'<b>LINEE FLEGREE</b>'),'intestazione Flegree evidente');
 csp_queue('auto:old-platform','🚉 <b>Binario:</b> 2');csp_queue('platform:implicit','🚉 <b>Binario:</b> 3');csp_cancel_legacy_delays();check_delay(csp_sql('SELECT status FROM outbox WHERE event_key=?',['auto:old-platform'])->fetchColumn()==='cancelled'&&csp_sql('SELECT status FROM outbox WHERE event_key=?',['platform:implicit'])->fetchColumn()==='cancelled','cambi binario automatici preesistenti annullati dalla coda');
 $legacyKey='terminal-delay:'.str_repeat('e',64);csp_queue($legacyKey.':10','Ritardo precedente 10');csp_queue($legacyKey.':15','Ritardo precedente 15');csp_cancel_legacy_delays();check_delay((int)csp_sql("SELECT COUNT(*) FROM outbox WHERE event_key LIKE ? AND status='pending'",[$legacyKey.'%'])->fetchColumn()===1,'una sola notifica pendente conservata dai vecchi incrementi');
 csp_sql("UPDATE outbox SET status='sent' WHERE event_key=?",[$legacyKey.':10']);csp_queue($legacyKey.':20','Ritardo precedente 20');csp_cancel_legacy_delays();check_delay((int)csp_sql("SELECT COUNT(*) FROM outbox WHERE event_key LIKE ? AND status='pending'",[$legacyKey.'%'])->fetchColumn()===0,'duplicati pendenti annullati se il primo ritardo è già stato consegnato');
 $claim=csp_queue('message:claim-test','Prova riserva');check_delay(csp_claim_outbox($claim)&&!csp_claim_outbox($claim),'due processi non possono riservare contemporaneamente lo stesso invio');
 csp_queue('terminal-delay:'.str_repeat('b',64),'⏱ Ritardo attuale: +8 minuti');csp_cancel_legacy_delays();check_delay(csp_sql('SELECT status FROM outbox WHERE event_key=?',['terminal-delay:'.str_repeat('b',64)])->fetchColumn()==='cancelled','vecchi ritardi sotto nove minuti ancora in coda annullati');
 echo 'Ritardi al capolinea verificati offline; nessun invio reale.'.PHP_EOL;
}finally{foreach(glob($dir.'/*')?:[] as $f)@unlink($f);@rmdir($dir);}
