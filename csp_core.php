<?php
declare(strict_types=1);
date_default_timezone_set('Europe/Rome');
if(PHP_SAPI==='cli' && defined('CSP_TEST_SOURCE')) require_once CSP_TEST_SOURCE;
else require_once __DIR__.'/eav_source.php';
require_once __DIR__.'/database.php';
function csp_json($value): string {return json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
function csp_stations(): array {
    $f=fopen(__DIR__.'/stazioni.csv','r'); fgetcsv($f); $out=[];
    while(($row=fgetcsv($f))!==false) if(ctype_digit($row[0]??'') && !empty($row[1])) $out[$row[0]]=trim($row[1]);
    fclose($f); $out['27']='San Giorgio a Cremano';
    // New L7 station is absent from some older copies of the master CSV.
    $out['834']='Monte Sant’Angelo';return $out;
}
function csp_context(array $input): array {
    $station=(string)($input['station']??''); $tipo=(string)($input['tipo']??'P'); $names=csp_stations();
    if(!isset($names[$station]) || !in_array($tipo,['P','A'],true)) throw new InvalidArgumentException('Stazione o direzione non valida.');
    return [$station,$tipo,$names[$station]];
}
function csp_train_id(array $t, string $station, string $tipo): string {return hash('sha256',date('Y-m-d').'|'.$station.'|'.$tipo.'|'.($t['number']??'').'|'.($t['time']??'').'|'.($t['destination']??''));}
function csp_source(string $station,string $tipo,string $name,bool $force=false,int $maxAge=5): array {
    $id=$station.'|'.$tipo;
    $cache=csp_sql('SELECT * FROM cache WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
    if(!$force && $cache && $cache['updated']>=time()-$maxAge) return json_decode($cache['payload'],true,512,JSON_THROW_ON_ERROR);
    $p=eav_fetch($station,$tipo,$name); $p['fetchedAt']=date(DATE_ATOM);
    csp_sql('INSERT INTO cache VALUES (?,?,?) ON CONFLICT(id) DO UPDATE SET payload=excluded.payload,updated=excluded.updated',[$id,csp_json($p),time()]);
    return $p;
}
function csp_suppressed(array $t): bool {return !empty($t['suppressed']) || preg_match('/soppresso|soppressa|cancellato|cancellata/iu',(string)($t['info']??''))===1;}
function csp_apply(array $payload): array {
    $station=(string)$payload['station']; $tipo=$payload['tipo'];
    $rows=csp_sql('SELECT * FROM overrides WHERE day=? AND station=? AND tipo=?',[date('Y-m-d'),$station,$tipo])->fetchAll(PDO::FETCH_ASSOC);
    $over=[]; foreach($rows as $r) $over[$r['id']]=$r;
    $trains=[]; $seen=[]; $removed=[];
    foreach($payload['trains'] as $t) {
        $id=csp_train_id($t,$station,$tipo); $t['id']=$id; $seen[$id]=true; $r=$over[$id]??null;
        $t['suppressed']=csp_suppressed($t) || ($r && $r['action']==='suppress');
        if($r && $r['action']==='remove') {$t['suppressed']=csp_suppressed($t);$removed[]=$t;continue;}
        $trains[]=$t;
    }
    // Keep manually suppressed runs visible until their scheduled time + 3 hours,
    // even if the upstream board removes them. Expire every override at midnight.
    foreach($over as $id=>$r) if(!isset($seen[$id])) {
        $t=json_decode($r['train'],true); $t['id']=$id;
        if($r['action']==='remove') {$removed[]=$t;continue;}
        $scheduled=strtotime(date('Y-m-d').' '.($t['time']??''));
        if($r['action']==='suppress' && $scheduled && time() <= $scheduled+10800) {$t['suppressed']=true; $trains[]=$t;}
    }
    $trains=csp_apply_network($trains,$station,$tipo);
    // Preserve EAV's live sequence, which wraps to the next morning after late trains.
    $payload['trains']=$trains; $payload['removed']=$removed; return $payload;
}
function csp_targets(string $destination): array {
    $key=$destination==='group'?'EAV_TELEGRAM_GROUP_ID':'EAV_TELEGRAM_CHANNEL_ID';
    $target=trim((string)getenv($key));
    return $target===''?[]:[$target];
}
function csp_queue(string $key,string $message,string $destination='channel',string $parseMode=''): int {
    if(mb_strlen($message)>4096)throw new InvalidArgumentException('Messaggio Telegram troppo lungo.');if(!in_array($parseMode,['','HTML'],true))throw new InvalidArgumentException('Formato messaggio non valido.');
    $targets=csp_targets($destination); if(!$targets || !getenv('EAV_TELEGRAM_BOT_TOKEN')) throw new RuntimeException($destination==='group'?'Gruppo Telegram non configurato: selezionare Canale associato oppure impostare EAV_TELEGRAM_GROUP_ID su Render.':'Canale Telegram non configurato: impostare EAV_TELEGRAM_CHANNEL_ID e il token su Render.');
    foreach($targets as $target) csp_sql('INSERT INTO outbox(event_key,target,message,created,parse_mode) VALUES (?,?,?,?,?) ON CONFLICT DO NOTHING',[$key,$target,$message,time(),$parseMode]);
    return (int)csp_sql('SELECT id FROM outbox WHERE event_key=? AND target=?',[$key,$targets[0]])->fetchColumn();
}
function csp_label(array $t): string {return 'Corsa '.($t['number']??'').' · '.($t['destination']??'').' · '.($t['time']??'').'\nBinario '.($t['platform']??'—').' · Ritardo '.($t['delay']??0).' min'.(!empty($t['suppressionScope'])?'\n'.$t['suppressionScope']:'');}
function csp_manual(array $input,string $actor): array {
    [$station,$tipo,$name]=csp_context($input); $action=(string)($input['operation']??''); $id=(string)($input['id']??'');
    if(!in_array($action,['remove','suppress','restore'],true) || !preg_match('/^[a-f0-9]{64}$/',$id)) throw new InvalidArgumentException('Operazione non valida.');
    return csp_tx(function() use($station,$tipo,$name,$action,$id,$actor) {
        $r=csp_sql('SELECT * FROM cache WHERE id=?',[$station.'|'.$tipo])->fetch(PDO::FETCH_ASSOC);
        $t=null;
        if($r) foreach(json_decode($r['payload'],true)['trains'] as $candidate) if(csp_train_id($candidate,$station,$tipo)===$id) {$t=$candidate;break;}
        $old=csp_sql('SELECT * FROM overrides WHERE id=? AND day=? AND station=? AND tipo=?',[$id,date('Y-m-d'),$station,$tipo])->fetch(PDO::FETCH_ASSOC);
        if(!$t && $old) $t=json_decode($old['train'],true);
        if(!$t) throw new InvalidArgumentException('Corsa non disponibile: aggiornare il tabellone.');
        if(($action==='restore' && !$old) || ($old && $old['action']===$action)) return ['ok'=>true,'unchanged'=>true];
        if($action==='restore') csp_sql('DELETE FROM overrides WHERE id=?',[$id]);
        else csp_sql('INSERT INTO overrides VALUES (?,?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET action=excluded.action,train=excluded.train,actor=excluded.actor,updated=excluded.updated',[$id,date('Y-m-d'),$station,$tipo,$action,csp_json($t),$actor,time()]);
        csp_sql('INSERT INTO audit(stamp,actor,action,detail) VALUES (?,?,?,?)',[time(),$actor,$action,csp_json(['station'=>$station,'tipo'=>$tipo,'train'=>$t])]);
        // Manual operations must be queued immediately, including before the first worker poll.
        if(csp_targets('channel') && getenv('EAV_TELEGRAM_BOT_TOKEN')) {
            $label=['remove'=>'RIMOSSO DAL TABELLONE','suppress'=>'SOPPRESSO','restore'=>'MODIFICA MANUALE RIPRISTINATA'][$action];
            $msg=$action==='suppress'?csp_brief('suppress',$t,$name):csp_short('ℹ️ Corsa '.$t['number'].' · '.$name.': '.($action==='remove'?'rimossa dal tabellone':'visualizzazione ripristinata').'.');
            csp_queue('manual:'.bin2hex(random_bytes(12)),$msg,'channel',$action==='suppress'?'HTML':'');
        }
        // Align the known snapshot so the next poll does not repeat the manual event.
        $snap=csp_sql('SELECT payload,day FROM snapshots WHERE id=?',[$station.'|'.$tipo])->fetch(PDO::FETCH_ASSOC);
        if($snap && $snap['day']===date('Y-m-d')) {
            $map=json_decode($snap['payload'],true);
            if($action==='remove') unset($map[$id]);
            else {$t['id']=$id;$t['suppressed']=$action==='suppress'||csp_suppressed($t);$map[$id]=$t;}
            csp_sql('UPDATE snapshots SET payload=? WHERE id=?',[csp_json($map),$station.'|'.$tipo]);
        }
        csp_align_snapshots([$station],(string)$t['number']);
        return ['ok'=>true];
    });
}
function csp_health(string $id,array $p): void {csp_sql('INSERT INTO health VALUES (?,?) ON CONFLICT(id) DO UPDATE SET payload=excluded.payload',[$id,csp_json($p)]);}
function csp_poll(string $station,string $tipo,string $name): void {
    $source=csp_source($station,$tipo,$name,true);
    csp_tx(function() use($source,$station,$tipo,$name) {
        $p=csp_apply($source);$current=[];foreach($p['trains'] as $t) $current[$t['id']]=$t;
        $id=$station.'|'.$tipo;$old=csp_sql('SELECT * FROM snapshots WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
        $previous=$old && $old['day']===date('Y-m-d')?json_decode($old['payload'],true):null;
        $destinations=csp_sql('SELECT id,terminal FROM delay_destinations WHERE day=?',[date('Y-m-d')])->fetchAll(PDO::FETCH_KEY_PAIR);
        $notified=[];foreach(csp_sql("SELECT event_key FROM outbox WHERE created>=? AND status IN ('pending','sent') AND event_key LIKE 'terminal-delay:%'",[strtotime('today')])->fetchAll(PDO::FETCH_COLUMN) as $eventKey)$notified[preg_replace('/:[0-9]+$/','',$eventKey)]=true;
        $destinationUpdates=[];
        foreach($current as $k=>$t) {
            $before=$previous[$k]??null;$events=[];
            if($tipo==='P')csp_record_delay_destination($t,$destinations,$destinationUpdates);
            if($t['suppressed'] && (!$before || !$before['suppressed']))$events[]=['suppress',$t['suppressionScope']??''];
            elseif(!$t['suppressed']){
                if($before && $before['suppressed'])$events[]=['service_restore',''];
                // Automatic platform notifications are disabled; manual messages remain available.
                if(csp_terminal_delay_eligible($station,$tipo,$t,$destinations))csp_queue_terminal_delay($station,$t);
                elseif(isset($notified['terminal-delay:'.hash('sha256',date('Y-m-d').'|'.(str_starts_with((string)csp_known_line($t),'F')?'Linee Flegree':'Linee Vesuviane').'|'.trim((string)$t['number']))]) && ($t['delayKnown']??true) && (int)($t['delay']??-1)===0 && csp_terminal_delay_eligible($station,$tipo,array_merge($t,['delay'=>9]),$destinations))csp_queue_terminal_delay_recovery($station,$t);
                if((!$before || ($before['info']??'')!==($t['info']??'')) && preg_match('/limitat[oa]|limitazion|termina|capolinea|interrott/iu',$t['info']??''))$events[]=['limit',csp_short($t['info'],100)];
            }
            foreach($events as [$kind,$detail])if(csp_targets('channel') && getenv('EAV_TELEGRAM_BOT_TOKEN')) {
                $msg=csp_brief($kind,$t,$name,$detail);
                $event='auto:'.hash('sha256',date('Y-m-d').'|'.$t['number'].'|'.$kind.'|'.$msg.'|'.intdiv(time(),60));
                csp_queue($event,$msg,'channel','HTML');
            }
        }
        csp_ops_batch('delay_destinations',$destinationUpdates,'ON CONFLICT(id) DO UPDATE SET terminal=excluded.terminal');
        // Disappearance alone is never classified as cancellation.
        csp_sql('INSERT INTO snapshots VALUES (?,?,?) ON CONFLICT(id) DO UPDATE SET payload=excluded.payload,day=excluded.day',[$id,csp_json($current),date('Y-m-d')]);
        csp_health('station:'.$id,['ok'=>true,'updated'=>time(),'count'=>count($current)]);
    });
}
// Atomically reserve delivery in the shared database, including across monitor instances.
function csp_claim_outbox(int $id): bool {
    $now=time();
    return csp_sql("UPDATE outbox SET next_try=? WHERE id=? AND status='pending' AND next_try<=?",[$now+120,$id,$now])->rowCount()===1;
}
function csp_flush(): void {
    static $legacyChecked=false;if(!$legacyChecked){csp_cancel_legacy_delays();$legacyChecked=true;}
    $token=trim((string)getenv('EAV_TELEGRAM_BOT_TOKEN'));if($token==='') return;
    $rows=csp_sql('SELECT * FROM outbox WHERE status=\'pending\' AND next_try<=? ORDER BY id LIMIT 20',[time()])->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $r) {
        if(!csp_claim_outbox((int)$r['id']))continue;
        try {
            $response=http_request('https://api.telegram.org/bot'.$token.'/sendMessage',['method'=>'POST','body'=>http_build_query(['chat_id'=>$r['target'],'text'=>$r['message'],'parse_mode'=>$r['parse_mode']?:null]),'timeout'=>20,'headers'=>['Content-Type: application/x-www-form-urlencoded']]);
            $data=json_decode($response['body'],true);
            if(!($data['ok']??false)) throw new RuntimeException('Telegram HTTP '.$response['status'].' · '.($data['description']??'risposta non valida'),(int)($data['parameters']['retry_after']??0));
            csp_sql('UPDATE outbox SET status=\'sent\',last_error=NULL WHERE id=?',[$r['id']]);
        } catch(Throwable $e) {
            $attempt=(int)$r['attempts']+1;
            // Do not persist exception URLs because they may include the bot token.
            $detail=str_replace($token,'[token]', $e->getMessage());
            if(str_contains($detail,'401')||str_contains($detail,'Unauthorized')) $detail='Telegram 401: token non valido o revocato. Correggere EAV_TELEGRAM_BOT_TOKEN su Render e verificare il bot.';
            csp_sql('UPDATE outbox SET attempts=?,next_try=?,last_error=? WHERE id=?',[$attempt,time()+max($e->getCode(),min(3600,30*(2**min($attempt,7)))),$detail,$r['id']]);
        }
        csp_health('worker-heartbeat',['heartbeat'=>time()]);
        usleep(1100000); // Respect the per-chat Telegram rate limit.
    }
}
function csp_session(): void {
    if(session_status()===PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode','1');session_name('CSP_ADMIN');
    session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','samesite'=>'Strict','path'=>rtrim(dirname($_SERVER['SCRIPT_NAME']??'/'),'/').'/']);
    if(!session_start())throw new RuntimeException('Impossibile avviare la sessione.');
    if(isset($_SESSION['admin']) && ($_SESSION['last']??0)<time()-3600) {$_SESSION=[];session_regenerate_id(true);}
    if(isset($_SESSION['admin'])) csp_refresh_identity();
    if(isset($_SESSION['admin'])) $_SESSION['last']=time();
}
function csp_require_admin(): void {csp_session();if(empty($_SESSION['admin'])) throw new RuntimeException('Accesso interno richiesto.',401);}
function csp_csrf(): void {if(!hash_equals($_SESSION['csrf']??'',(string)($_POST['csrf']??''))) throw new RuntimeException('Sessione scaduta: ricaricare la pagina.',403);}
function csp_legacy_credentials_valid(string $user,string $password): bool {
    $expected=(string)(getenv('CSP_ADMIN_USER')?:'admin');
    $hash=(string)getenv('CSP_ADMIN_PASSWORD_HASH');
    if($hash!=='') return hash_equals($expected,$user) && password_verify($password,$hash);
    // Runtime-only secret: never written to a file, database or response.
    $secret=(string)getenv('CSP_ADMIN_PASSWORD');
    if($secret==='') return false;
    $temporaryHash=password_hash($secret,PASSWORD_DEFAULT);
    return hash_equals($expected,$user) && hash_equals($secret,$password) && password_verify($password,$temporaryHash);
}
function csp_login(string $user,string $password): bool {
    csp_session();$user=trim($user);$ip=$_SERVER['REMOTE_ADDR']??'local';
    $r=csp_sql('SELECT * FROM attempts WHERE ip=?',[$ip])->fetch(PDO::FETCH_ASSOC);
    if($r && $r['count']>=10 && $r['stamp']>time()-900) throw new RuntimeException('Troppi tentativi: riprovare tra 15 minuti.',429);
    $account=csp_authenticate($user,$password);
    if(!$account) {
        $count=$r && $r['stamp']>time()-900?(int)$r['count']+1:1;
        csp_sql('INSERT INTO attempts VALUES (?,?,?) ON CONFLICT(ip) DO UPDATE SET count=excluded.count,stamp=excluded.stamp',[$ip,$count,time()]);return false;
    }
    csp_sql('DELETE FROM attempts WHERE ip=?',[$ip]);session_regenerate_id(true);
    $_SESSION=['admin'=>$account['username'],'user_id'=>(int)$account['id'],'revision'=>(int)$account['revision'],'role'=>$account['role'],'must_change'=>(bool)$account['must_change'],'last'=>time(),'csrf'=>bin2hex(random_bytes(32))];
    csp_audit($account['username'],'login',[]);return true;
}

require_once __DIR__.'/accounts.php';
require_once __DIR__.'/operations.php';

require_once __DIR__ . "/communications.php";

function csp_delay_terminals(): array {
    return ['1'=>'Napoli Porta Nolana','62'=>'Sorrento','25'=>'Sarno','84'=>'Baiano','70'=>'Volla','41'=>'Torre Annunziata / Oplonti','22'=>'Poggiomarino','801'=>'Montesanto','830'=>'Licola','819'=>'Torregaveta','821'=>'Soccavo','834'=>'Monte Sant’Angelo'];
}
function csp_terminal_delay_eligible(string $station,string $tipo,array $t,?array $destinations=null): bool {
    if($tipo!=='A'||!isset(csp_delay_terminals()[$station])||!empty($t['suppressed'])||!empty($t['retained'])||(int)($t['delay']??0)<9)return false;
    if(($t['delayKnown']??true)===false)return false;
    // Actual destination, not inferred line endpoints, determines the terminal.
    $known=$destinations!==null?($destinations[csp_delay_service_key($t)]??false):csp_sql('SELECT terminal FROM delay_destinations WHERE id=? AND day=?',[csp_delay_service_key($t),date('Y-m-d')])->fetchColumn();
    // Do not guess direction from the origin field on arrival boards.
    return $known!==false && (string)$known===$station;
}
function csp_queue_terminal_delay(string $station,array $t): void {
    if(!csp_terminal_delay_eligible($station,'A',$t))return;
    if(!csp_targets('channel')||!getenv('EAV_TELEGRAM_BOT_TOKEN'))return;
    $line=csp_known_line($t);$network=str_starts_with((string)$line,'F')?'Linee Flegree':'Linee Vesuviane';
    // Arrival boards may carry the origin in the destination column.
    $t['destination']=csp_delay_terminals()[$station];$t['network']=$network;$t['arrivalNotice']=true;
    $key='terminal-delay:'.hash('sha256',date('Y-m-d').'|'.$network.'|'.trim((string)$t['number']));
    $already=csp_sql("SELECT COUNT(*) FROM outbox WHERE (event_key=? OR event_key LIKE ?) AND status IN ('pending','sent')",[$key,$key.':%'])->fetchColumn();
    if($already)return;
    csp_queue($key,csp_brief('delay',$t),'channel','HTML');
}

function csp_delay_service_key(array $t): string {
    return hash('sha256',date('Y-m-d').'|'.(str_starts_with((string)csp_known_line($t),'F')?'F':'V').'|'.trim((string)$t['number']));
}
function csp_record_delay_destination(array $t,?array &$known=null,?array &$updates=null): void {
    $line=csp_known_line($t);if($line===null)return;
    $text=mb_strtolower(trim((string)($t['destination']??'')));
    $aliases=['62'=>['sorrento'],'25'=>['sarno'],'84'=>['baiano'],'70'=>['volla'],'41'=>['torre annunziata','torre a.ta','t.annunziata','oplonti'],'22'=>['poggiomarino','p.marino'],'801'=>['montesanto'],'830'=>['licola'],'819'=>['torregaveta'],'821'=>['soccavo'],'834'=>['monte sant','monte s.angelo'],'1'=>['napoli porta nolana','napoli p. nolana','napoli p.nolana','napoli nolana']];
    $terminal=null;foreach($aliases as $id=>$words)foreach($words as $word)if(str_contains($text,$word)){$terminal=(string)$id;break 2;}
    if($terminal===null && $text==='napoli'&&!str_starts_with($line,'F'))$terminal='1';
    if($terminal===null)return;
    $id=csp_delay_service_key($t);
    if($known!==null&&($known[$id]??null)===$terminal)return;
    if($updates!==null){$updates[$id]=[$id,date('Y-m-d'),$terminal];$known[$id]=$terminal;return;}
    csp_sql('INSERT INTO delay_destinations VALUES (?,?,?) ON CONFLICT(id) DO UPDATE SET terminal=excluded.terminal',[$id,date('Y-m-d'),$terminal]);
}

function csp_cancel_legacy_delays(): void {
    foreach(csp_sql("SELECT id,message FROM outbox WHERE event_key LIKE 'terminal-delay:%' AND status='pending'")->fetchAll(PDO::FETCH_ASSOC) as $r){
        if(preg_match('/\+(\d+)\s*minut/u',$r['message'],$m)&&(int)$m[1]<9)csp_sql("UPDATE outbox SET status='cancelled',last_error='Soglia aggiornata: ritardi da 9 minuti inclusi.' WHERE id=?",[$r['id']]);
    }

    $groups=[];
    foreach(csp_sql("SELECT id,event_key,status FROM outbox WHERE event_key LIKE 'terminal-delay:%' AND status IN ('pending','sent') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r){if(preg_match('/^(terminal-delay:[a-f0-9]{64})(?::[0-9]+)?$/',$r['event_key'],$m))$groups[$m[1]][]=$r;}
    foreach($groups as $rows){$sent=array_filter($rows,fn($r)=>$r['status']==='sent');$keep=$sent?null:$rows[0]['id'];foreach($rows as $r)if($r['status']==='pending'&&$r['id']!==$keep)csp_sql("UPDATE outbox SET status='cancelled',last_error='Duplicato ritardo annullato: un solo messaggio per corsa.' WHERE id=?",[$r['id']]);}

    csp_sql("UPDATE outbox SET status='cancelled',last_error='Avviso superato: ritardi al solo capolinea e cambi binario automatici disattivati.' WHERE status='pending' AND ((event_key LIKE 'platform:%') OR (event_key LIKE 'auto:%' AND (message LIKE '%Ritardo attuale:%' OR message LIKE '%<b>Binario:</b>%')))");
}

function csp_queue_terminal_delay_recovery(string $station,array $t): void {
    if(!csp_targets('channel')||!getenv('EAV_TELEGRAM_BOT_TOKEN'))return;
    $network=str_starts_with((string)csp_known_line($t),'F')?'Linee Flegree':'Linee Vesuviane';
    $hash=hash('sha256',date('Y-m-d').'|'.$network.'|'.trim((string)$t['number']));
    $notified=csp_sql("SELECT COUNT(*) FROM outbox WHERE (event_key=? OR event_key LIKE ?) AND status IN ('pending','sent')",['terminal-delay:'.$hash,'terminal-delay:'.$hash.':%'])->fetchColumn();if(!$notified)return;
    $t['destination']=csp_delay_terminals()[$station];$t['network']=$network;$t['arrivalNotice']=true;
    csp_queue('terminal-ontime:'.$hash,csp_brief('ontime',$t),'channel','HTML');
}
