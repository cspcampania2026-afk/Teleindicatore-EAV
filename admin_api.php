<?php
require_once __DIR__.'/csp_core.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
try {
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST') throw new RuntimeException('Metodo non consentito.',405);
    csp_require_admin();csp_csrf();$action=$_POST['action']??'';csp_authorize_action($action);$actor=$_SESSION['admin'];session_write_close();
    if($action==='board') {[$s,$t,$n]=csp_context($_POST);$result=csp_apply(csp_source($s,$t,$n));}
    elseif($action==='operations') {[$s,$t,$n]=csp_context($_POST);$result=csp_operations_board($s,$n);}
    elseif($action==='recover_operation') $result=csp_recover_operation((string)($_POST['id']??''),$actor);
    elseif($action==='mark_passed') $result=csp_mark_passed((string)($_POST['id']??''),$actor);
    elseif($action==='queue_retry') {csp_telegram_rpc('getMe');csp_sql('UPDATE outbox SET next_try=0 WHERE status=\'pending\'');csp_audit($actor,'queue_retry',[]);$result=['ok'=>true];}
    elseif($action==='queue_cancel') {csp_sql('UPDATE outbox SET status=\'cancelled\' WHERE status=\'pending\'');csp_audit($actor,'queue_cancel',[]);$result=['ok'=>true];}
    elseif($action==='line_analysis') $result=csp_analysis_samples((string)($_POST['station']??''),(string)($_POST['day']??date('Y-m-d')),(string)($_POST['scope']??'network'));
    elseif($action==='delay_statistics') $result=csp_delay_statistics((string)($_POST['station']??''),(string)($_POST['day']??date('Y-m-d')),(string)($_POST['line']??'ALL'));
    elseif($action==='history_edit') $result=csp_save_history_edit($_POST,$actor);
    elseif($action==='history') $result=csp_operations_history((string)($_POST['station']??''),(string)($_POST['day']??date('Y-m-d')),['page'=>$_POST['page']??1,'page_size'=>$_POST['page_size']??25,'line'=>$_POST['line']??'ALL','query'=>$_POST['query']??'']);
    elseif($action==='train_detail') $result=csp_train_detail((string)($_POST['id']??''),(string)($_POST['station']??''),(string)($_POST['day']??date('Y-m-d')));
    elseif($action==='actual_times') $result=csp_save_actual($_POST,$actor);
    elseif($action==='suppress_scope') $result=csp_save_scope($_POST,$actor);
    elseif($action==='restore_scope') $result=csp_restore_scope((string)($_POST['rule_id']??''),$actor);
    elseif($action==='rules') $result=['rules'=>csp_sql('SELECT id,number,line,scope,label,stations,actor,updated FROM route_rules WHERE day=? ORDER BY updated DESC',[date('Y-m-d')])->fetchAll(PDO::FETCH_ASSOC)];
    elseif($action==='train') $result=csp_manual($_POST,$actor);
    elseif($action==='message_preview') $result=['simulation'=>true,'text'=>csp_manual_message($_POST),'target'=>($_POST['target']??'group'),'queued'=>false,'parseMode'=>($_POST['structured']??'0')==='1'?'HTML':''];
    elseif($action==='telegram_check') {$bot=csp_telegram_rpc('getMe');$result=['ok'=>true,'username'=>$bot['username']??'','name'=>$bot['first_name']??''];}
    elseif($action==='telegram_chats') $result=csp_telegram_chats();
    elseif($action==='notice_publish') $result=csp_save_notice($_POST,$actor);
    elseif($action==='notice_revoke') $result=csp_revoke_notice((string)($_POST['id']??''),$actor);
    elseif($action==='notices') $result=['notices'=>csp_sql('SELECT * FROM notices WHERE active=1 AND expires>? ORDER BY created DESC LIMIT 100',[time()])->fetchAll(PDO::FETCH_ASSOC)];
    elseif($action==='message') {
        $message=csp_manual_message($_POST);$target=(string)($_POST['target']??'group');
        if(!in_array($target,['group','channel'],true))throw new InvalidArgumentException('Destinazione non valida.');
        $request=(string)($_POST['request_id']??'');if(!preg_match('/^[a-f0-9]{32}$/',$request))throw new InvalidArgumentException('Richiesta non valida.');
        $id=csp_tx(function()use($request,$message,$target,$actor){$id=csp_queue('message:'.$request,$message,$target,($_POST['structured']??'0')==='1'?'HTML':'');csp_audit($actor,'message_queue',['id'=>$id,'target'=>$target]);return $id;});$result=['ok'=>true,'queued'=>true,'id'=>$id];
    } elseif($action==='status') {
        $health=csp_sql('SELECT * FROM health')->fetchAll(PDO::FETCH_ASSOC);
        foreach($health as &$h) $h['payload']=json_decode($h['payload'],true);unset($h);
        $result=['database'=>csp_database_kind(),'health'=>$health,'outbox'=>csp_sql('SELECT id,target,status,attempts,last_error,created FROM outbox ORDER BY id DESC LIMIT 30')->fetchAll(PDO::FETCH_ASSOC), 'telegram'=>['channel'=>!empty(csp_targets('channel')),'group'=>!empty(csp_targets('group')),'token'=>(bool)getenv('EAV_TELEGRAM_BOT_TOKEN')], 'audit'=>csp_sql('SELECT stamp,actor,action,detail FROM audit ORDER BY id DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC)];
    } else throw new InvalidArgumentException('Comando non valido.');
    echo csp_json($result);
} catch(Throwable $e) {http_response_code(in_array($e->getCode(),[401,403,405,429],true)?$e->getCode():($e instanceof InvalidArgumentException?400:500));echo csp_json(['error'=>$e->getMessage()]);}
