<?php
declare(strict_types=1);
function csp_short(string $s,int $max=220): string {$s=trim(preg_replace('/\s+/u',' ',$s));return mb_strlen($s)>$max?mb_substr($s,0,$max-1).'…':$s;}
function csp_html(string $s): string {return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function csp_brief(string $kind,array $t,string $station='',string $detail=''): string {
    $h=fn($v)=>csp_html(csp_short((string)$v,160));
    $number=(string)($t['number']??'');$network=$t['network']??(str_starts_with((string)(csp_known_line($t)??''),'F')?'Linee Flegree':'Linee Vesuviane');
    $time=($t['time']??'')?:($t['theoryP']??'')?:($t['theoryA']??'');if($time==='')$time='Non disponibile';
    $lines=['🚆 <b>'.mb_strtoupper($h($network)).'</b>', '<b>EAV · AVVISI DI SERVIZIO</b>', '🚆 <b>Corsa:</b> <code>'.$h($number).'</code>', '📍 <b>Destinazione:</b> <b>'.$h(($t['destination']??'')?:'Non disponibile').'</b>', '🕒 <b>'.(!empty($t['arrivalNotice'])?'Arrivo programmato':'Orario programmato').':</b> '.$h($time)];
    switch($kind){
      case 'delay':$lines[]='⏱️ <b>Ritardo attuale:</b> <b>+'.(int)($t['delay']??0).' minuti</b>';break;
      case 'suppress':$lines[]='⛔ <b>Stato:</b> <b>SOPPRESSA</b>';if($detail!=='')$lines[]='📍 <b>Ambito:</b> '.$h($detail);break;
      case 'limit':$lines[]='⚠️ <b>Limitazione:</b> '.$h($detail);break;
      case 'platform':$lines[]='🚉 <b>Binario:</b> <b>'.$h($t['platform']??'Non disponibile').'</b>';if($station!=='')$lines[]='📍 <b>Stazione:</b> '.$h($station);break;
      case 'restore':$lines[]='✅ <b>Stato:</b> soppressione manuale revocata';break;
      case 'service_restore':$lines[]='✅ <b>Stato:</b> corsa ripristinata';break;
      case 'ontime':$lines[]='✅ <b>Ritardo attuale:</b> rientrato';break;
      default:$lines[]='ℹ️ <b>Avviso:</b> '.$h($detail);
    }
    if(!empty($t['extra']))$lines[]='ℹ️ '.$h($t['extra']);
    if(in_array($kind,['delay','suppress','limit','platform'],true))$lines[]='📢 <i>Ci scusiamo con i gentili clienti EAV per il disagio arrecato.</i>';
    return implode("\n",$lines);
}
function csp_manual_message(array $input): string {
    if(($input['structured']??'0')==='1'){
      $number=trim((string)($input['number']??''));$destination=trim((string)($input['destination']??''));$time=trim((string)($input['time']??''));$kind=(string)($input['kind']??'delay');
      if(!preg_match('/^[0-9A-Za-z-]{1,12}$/',$number)||$destination===''||mb_strlen($destination)>100||!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/',$time))throw new InvalidArgumentException('Compila corsa, destinazione e orario programmato valido.');
      if(!in_array($kind,['delay','suppress','limit','platform','service_restore','ontime','general'],true))throw new InvalidArgumentException('Tipo avviso non valido.');
      $delay=(string)($input['delay']??'0');if($kind==='delay'&&(int)$delay<10)throw new InvalidArgumentException('Gli avvisi di ritardo partono da 10 minuti.');if(!ctype_digit($delay)||(int)$delay>1440)throw new InvalidArgumentException('Ritardo non valido.');
      $detail=trim((string)($input['detail']??''));$platform=trim((string)($input['platform']??''));$extra=trim((string)($input['message']??''));
      if(mb_strlen($detail)>160||mb_strlen($platform)>12||mb_strlen($extra)>280)throw new InvalidArgumentException('Informazioni troppo lunghe.');
      if($kind==='limit'&&$detail==='')throw new InvalidArgumentException('Indica la tratta o la stazione di limitazione.');if($kind==='platform'&&$platform==='')throw new InvalidArgumentException('Indica il nuovo binario.');
      $network=($input['network']??'vesuviane')==='flegree'?'Linee Flegree':'Linee Vesuviane';
      $s=csp_brief($kind,['number'=>$number,'destination'=>$destination,'time'=>$time,'delay'=>(int)$delay,'platform'=>$platform,'network'=>$network,'extra'=>$extra],(string)($input['stationName']??''),$detail);
      return (($input['test']??'0')==='1'?'[PROVA] ' :'').$s;
    }
    $s=trim((string)($input['message']??''));
    if($s===''||mb_strlen($s)>280) throw new InvalidArgumentException('Messaggio: da 1 a 280 caratteri.');
    if(preg_match('/\[(numero|destinazione|stazione|minuti|binario|tratta)\]/iu',$s)) throw new InvalidArgumentException('Sostituire i campi tra parentesi prima della prova o dell’invio.');
    return (($input['test']??'0')==='1'?'[PROVA] ':'').$s;
}
function csp_public_notices(string $station): array {
    return csp_sql('SELECT id,kind,text,station,created,expires FROM notices WHERE active=1 AND expires>? AND (station=\'ALL\' OR station=?) ORDER BY created DESC LIMIT 20',[time(),$station])->fetchAll(PDO::FETCH_ASSOC);
}
function csp_save_notice(array $input,string $actor): array {
    $message=csp_manual_message(['message'=>$input['message']??'']);$kind=(string)($input['kind']??'generale');$station=(string)($input['station']??'ALL');$hours=(int)($input['hours']??24);$id=(string)($input['request_id']??'');
    if(!in_array($kind,['generale','soppressione','limitazione','binario','ritardo'],true)||($station!=='ALL'&&!isset(csp_stations()[$station]))||$hours<1||$hours>168||!preg_match('/^[a-f0-9]{32}$/',$id)) throw new InvalidArgumentException('Ambito, categoria o durata avviso non validi.');
    $send=($input['telegram']??'0')==='1';$target=(string)($input['target']??'channel');if(!in_array($target,['channel','group'],true)) throw new InvalidArgumentException('Destinazione Telegram non valida.');
    return csp_tx(function() use($id,$kind,$message,$station,$hours,$actor,$send,$target) {
        $old=csp_sql('SELECT * FROM notices WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
        if($old && ($old['text']!==$message||$old['station']!==$station||$old['kind']!==$kind)) throw new InvalidArgumentException('Richiesta già usata per un altro avviso.');
        csp_sql('INSERT INTO notices VALUES (?,?,?,?,?,?,?,?) ON CONFLICT DO NOTHING',[$id,$kind,$message,$station,time(),time()+$hours*3600,1,$actor]);
        if($send)csp_queue('notice:'.$id,$message,$target);
        if(!$old)csp_sql('INSERT INTO audit(stamp,actor,action,detail) VALUES (?,?,?,?)',[time(),$actor,'publish_notice',csp_json(['id'=>$id,'station'=>$station,'text'=>$message,'telegram'=>$send])]);
        return ['ok'=>true,'id'=>$id,'queued'=>$send];
    });
}
function csp_revoke_notice(string $id,string $actor): array {
    if(!preg_match('/^[a-f0-9]{32}$/',$id))throw new InvalidArgumentException('Avviso non valido.');
    return csp_tx(function()use($id,$actor){csp_sql('UPDATE notices SET active=0 WHERE id=?',[$id]);csp_sql('INSERT INTO audit(stamp,actor,action,detail) VALUES (?,?,?,?)',[time(),$actor,'revoke_notice',$id]);return ['ok'=>true];});
}
function csp_telegram_rpc(string $method,array $params=[]): array {
    $token=trim((string)getenv('EAV_TELEGRAM_BOT_TOKEN'));if($token==='')throw new RuntimeException('Inserire EAV_TELEGRAM_BOT_TOKEN nella configurazione del server.');
    try {$r=http_request('https://api.telegram.org/bot'.$token.'/'.$method,['method'=>'POST','body'=>http_build_query($params),'timeout'=>15,'headers'=>['Content-Type: application/x-www-form-urlencoded']]);}
    catch(Throwable $e){throw new RuntimeException('Telegram non raggiungibile: riprovare.');}
    $data=json_decode($r['body'],true);if(($data['error_code']??$r['status']??0)===401)throw new RuntimeException('Telegram 401: token non valido o revocato. Copia il token da @BotFather in EAV_TELEGRAM_BOT_TOKEN su Render, senza virgolette né prefisso bot, poi esegui il deploy.');if(!($data['ok']??false))throw new RuntimeException(str_replace($token,'[token]',(string)($data['description']??'Risposta Telegram non valida.')));
    return $data['result'];
}
function csp_telegram_chats(): array {
    $webhook=csp_telegram_rpc('getWebhookInfo');
    if(!empty($webhook['url']))throw new RuntimeException('Questo bot ha un webhook attivo: getUpdates non è disponibile. Usa lo username del canale pubblico o l’ID già noto. Il webhook non è stato modificato.');
    $updates=csp_telegram_rpc('getUpdates',['timeout'=>0,'limit'=>100]);$chats=[];
    foreach($updates as $u){$chat=$u['message']['chat']??$u['channel_post']['chat']??$u['my_chat_member']['chat']??null;if(!$chat)continue;$chats[(string)$chat['id']]=['id'=>(string)$chat['id'],'type'=>$chat['type'],'name'=>$chat['title']??$chat['first_name']??'Chat','username'=>$chat['username']??''];}
    return ['chats'=>array_values($chats)];
}
