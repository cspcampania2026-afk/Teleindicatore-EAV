<?php
if(PHP_SAPI!=='cli') {http_response_code(404);exit;}
require_once __DIR__.'/csp_core.php';
csp_db();
$lock=fopen(rtrim(getenv('CSP_DATA_DIR'),'/').'/monitor.lock','c');
if(!flock($lock,LOCK_EX|LOCK_NB)) exit(0);
$stations=array_intersect_key(csp_stations(),csp_delay_terminals());$selection=trim((string)getenv('EAV_MONITOR_STATIONS'));
if($selection!=='') {$ids=array_map('trim',explode(',',$selection));foreach($ids as $id) if(!isset($stations[$id])) throw new RuntimeException('Stazione monitor non valida: '.$id);$stations=array_intersect_key($stations,array_flip($ids));}
$types=array_map('trim',explode(',',getenv('EAV_MONITOR_TYPES')?:'P,A'));
foreach($types as $type) if(!in_array($type,['P','A'],true)) throw new RuntimeException('EAV_MONITOR_TYPES deve essere P,A oppure P oppure A.');
// Departures identify the actual destination; arrival columns can show the origin.
if(in_array('A',$types,true))$types=array_values(array_unique(array_merge(['P'],$types)));
$once=in_array('--once',$argv,true);$interval=max(15,(int)(getenv('EAV_POLL_SECONDS')?:60));
$terminalStations=$stations;
do {
    $stations=$terminalStations;
    foreach(csp_sql('SELECT station FROM history_watch')->fetchAll(PDO::FETCH_COLUMN) as $watched)if(isset(csp_stations()[$watched]))$stations[(string)$watched]=csp_stations()[$watched];
    $start=time();$departures=[];csp_health('worker',['started'=>$start,'heartbeat'=>time(),'stations'=>count($stations),'types'=>$types,'state'=>'reading-destinations']);
    // Resolve all destinations before evaluating arrivals, independent of station order.
    if(in_array('P',$types,true))foreach($stations as $station=>$name){
        csp_health('worker',['started'=>$start,'heartbeat'=>time(),'stations'=>count($stations),'types'=>$types,'state'=>'reading-destinations','current'=>$station.'|P']);
        try{csp_poll((string)$station,'P',$name);$cached=csp_sql('SELECT payload FROM cache WHERE id=?',[$station.'|P'])->fetchColumn();if($cached)$departures[$station]=json_decode($cached,true);}
        catch(Throwable $e){csp_health('station:'.$station.'|P',['ok'=>false,'updated'=>time(),'error'=>$e->getMessage()]);}
    }
    csp_health('worker',['started'=>$start,'heartbeat'=>time(),'stations'=>count($stations),'types'=>$types,'state'=>'running']);csp_flush();
    foreach($stations as $station=>$name) {
      $sourceCycle=[];
      foreach($types as $tipo) {
        try {if($tipo==='P'){if(isset($departures[$station]))$sourceCycle['P']=$departures[$station];continue;}csp_poll((string)$station,$tipo,$name);$cached=csp_sql('SELECT payload FROM cache WHERE id=?',[$station.'|'.$tipo])->fetchColumn();if($cached)$sourceCycle[$tipo]=json_decode($cached,true);} catch(Throwable $e) {csp_health('station:'.$station.'|'.$tipo,['ok'=>false,'updated'=>time(),'error'=>$e->getMessage()]);}
        csp_health('worker',['started'=>$start,'heartbeat'=>time(),'stations'=>count($stations),'types'=>$types,'state'=>'running','current'=>$station.'|'.$tipo]);
        csp_flush();
      }
      try {csp_operations_board((string)$station,$name,$sourceCycle);}catch(Throwable $e){csp_health('history:'.$station,['ok'=>false,'error'=>$e->getMessage(),'updated'=>time()]);}
    }
    csp_health('worker',['heartbeat'=>time(),'completed'=>time(),'duration'=>time()-$start,'stations'=>count($stations),'types'=>$types,'state'=>$once?'completed':'waiting']);
    csp_finalize_previous_days();
    csp_sql('DELETE FROM overrides WHERE day<?',[date('Y-m-d')]);
    csp_sql('DELETE FROM route_rules WHERE day<?',[date('Y-m-d')]);
    csp_sql('DELETE FROM operations_events WHERE day<?',[date('Y-m-d')]);
    csp_sql('DELETE FROM operations_rows WHERE day<?',[date('Y-m-d')]);
    csp_sql('DELETE FROM outbox WHERE status IN (\'sent\',\'cancelled\') AND created<?',[time()-30*86400]);
    csp_sql('DELETE FROM notices WHERE expires<?',[time()-30*86400]);
    csp_sql('DELETE FROM audit WHERE stamp<?',[time()-90*86400]);
    if(!$once) { $next=max(time(),$start+$interval);while(time()<$next) {csp_flush();csp_health('worker',['heartbeat'=>time(),'completed'=>$start,'state'=>'waiting','stations'=>count($stations)]);sleep(2);} }
} while(!$once);
