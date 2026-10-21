<?php
declare(strict_types=1);
function csp_report_context(string $station,string $day): void {
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$day);
    if(!isset(csp_stations()[$station])||!$date||$date->format('Y-m-d')!==$day)throw new InvalidArgumentException('Stazione o giornata non valida.');
}
function csp_history_watch(string $station): void {
    csp_sql('INSERT INTO history_watch VALUES (?,?) ON CONFLICT(station) DO UPDATE SET updated=excluded.updated',[$station,time()]);
}
function csp_record_coverage(string $station): void {
    $day=date('Y-m-d');csp_sql('INSERT INTO history_coverage VALUES (?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET last_seen=excluded.last_seen',[$day.'|'.$station,$day,$station,time(),time()]);
}
function csp_row_status(array $row): array {
    if(!empty($row['suppressedA'])||!empty($row['suppressedP'])||str_starts_with($row['historyStatus']??'','SOPPRESS'))return ['kind'=>'suppressed','label'=>'SOPPRESSO'];
    if(str_starts_with($row['historyStatus']??'','PASSAGGIO AUTOMATICO'))return ['kind'=>'automatic','label'=>'PASSAGGIO AUTOMATICO'];
    if(!empty($row['completedManually'])||str_contains($row['historyStatus']??'','manual'))return ['kind'=>'manual','label'=>'PASSAGGIO MANUALE'];
    if(str_starts_with($row['historyStatus']??'','RIMOSSA'))return ['kind'=>'removed','label'=>'RIMOSSO MANUALMENTE'];
    return ['kind'=>'pending','label'=>!empty($row['historyStatus'])?'ESITO NON RILEVATO':'DA EFFETTUARE'];
}
function csp_operation_covered(array $partial,array $complete): bool {
    if(($partial['id']??'')===($complete['id']??'')||(string)($partial['station']??'')!==(string)($complete['station']??'')||(string)$partial['number']!==(string)$complete['number'])return false;
    $refs=array_column($partial['refs']??[],'id');$other=array_column($complete['refs']??[],'id');
    return count($refs)>0&&count($other)>count($refs)&&!array_diff($refs,$other);
}
function csp_compact_history(array $rows): array {
    $groups=[];foreach($rows as $row)$groups[$row['station'].'|'.$row['number']][]=$row;
    return array_values(array_filter($rows,function($row)use($groups){
        if(!str_starts_with($row['historyStatus']??'','PASSAGGIO AUTOMATICO'))return true;
        foreach($groups[$row['station'].'|'.$row['number']] as $other)if(csp_operation_covered($row,$other))return false;
        return true;
    }));
}
function csp_report_edit_row(array $row,?array $edit): array {
    $row=csp_complete_effective($row);
    if(!$edit)return $row;
    foreach(json_decode($edit['payload'],true) as $key=>$value)$row[$key]=$value;
    $row['historyEdit']=['actor'=>$edit['actor'],'updated'=>(int)$edit['updated']];
    return csp_complete_effective($row);
}
function csp_save_history_edit(array $input,string $actor): array {
    $station=(string)($input['station']??'');$day=(string)($input['day']??'');$id=(string)($input['id']??'');csp_report_context($station,$day);
    if(!preg_match('/^[a-f0-9]{64}$/',$id))throw new InvalidArgumentException('Corsa non valida.');
    return csp_tx(function()use($station,$day,$id,$input,$actor){
        $saved=csp_sql('SELECT payload FROM operations_history WHERE id=? AND station=? AND day=?',[$id,$station,$day])->fetchColumn();
        if(!$saved)throw new InvalidArgumentException('La corsa non è nel resoconto. Aggiornare la scheda.');
        $row=json_decode($saved,true);$old=csp_sql('SELECT * FROM history_edits WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC)?:null;$before=csp_report_edit_row($row,$old);$patch=$old?json_decode($old['payload'],true):[];
        foreach(['note'=>1000,'reportInfo'=>2000,'destination'=>160] as $key=>$limit)if(array_key_exists($key,$input)){$value=trim((string)$input[$key]);if(mb_strlen($value)>$limit||($key==='destination'&&$value===''))throw new InvalidArgumentException('Campo '.$key.' non valido.');$patch[$key]=$value;}
        foreach(['A','P'] as $type){
            foreach(['theory','actual'] as $prefix){$key=$prefix.$type;if(!array_key_exists($key,$input))continue;$value=trim((string)$input[$key]);if($value!==''&&!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/',$value))throw new InvalidArgumentException('Orario non valido.');$patch[$key]=$value;if($prefix==='actual'&&$value!==($before[$key]??''))$patch['actualSource'.$type]=$value===''?'':'report';}
            $key='bin'.$type;if(array_key_exists($key,$input)){$value=trim((string)$input[$key]);if(mb_strlen($value)>12)throw new InvalidArgumentException('Binario troppo lungo.');$patch[$key]=$value;}
            $key='delay'.$type;if(array_key_exists($key,$input)){$value=trim((string)$input[$key]);if($value!==''&&!preg_match('/^[0-9]{1,3}$/D',$value))throw new InvalidArgumentException('Ritardo non valido: 0–999 minuti.');$patch[$key]=$value===''?null:(int)$value;$patch['delayKnown'.$type]=$value!=='';}
        }
        $merged=csp_complete_effective(array_replace($before,$patch));foreach(['A','P'] as $type)if(($merged['actualSource'.$type]??'')==='calculated'){$patch['actual'.$type]=$merged['actual'.$type];$patch['actualSource'.$type]='calculated';}
        csp_sql('INSERT INTO history_edits VALUES (?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET payload=excluded.payload,actor=excluded.actor,updated=excluded.updated',[$id,$day,$station,csp_json($patch),$actor,time()]);
        csp_sql('INSERT INTO audit(stamp,actor,action,detail) VALUES (?,?,?,?)',[time(),$actor,'history_edit',csp_json(['id'=>$id,'number'=>$row['number'],'station'=>$station,'day'=>$day,'before'=>$before,'values'=>$patch])]);
        return ['ok'=>true,'message'=>'Resoconto aggiornato.'];
    });
}
function csp_operations_history(string $station,string $day,array $options=[]): array {
    csp_report_context($station,$day);
    $entries=csp_sql('SELECT payload,status,archived FROM operations_history WHERE station=? AND day=? ORDER BY archived,id',[$station,$day])->fetchAll(PDO::FETCH_ASSOC);$rows=[];$edits=[];foreach(csp_sql('SELECT * FROM history_edits WHERE station=? AND day=?',[$station,$day])->fetchAll(PDO::FETCH_ASSOC) as $edit)$edits[$edit['id']]=$edit;
    foreach($entries as $entry){$row=json_decode($entry['payload'],true);$row=csp_report_edit_row($row,$edits[$row['id']]??null);$row['historyStatus']=str_replace('NON CONCLUSA','ESITO NON RILEVATO',$entry['status']);$row['archivedAt']=(int)$entry['archived'];$row['statusBadge']=csp_row_status($row);$rows[]=$row;}
    $rows=csp_compact_history($rows);
    usort($rows,fn($a,$b)=>strcmp(($a['theoryA']?:$a['theoryP']).'|'.$a['number'].'|'.$a['id'],($b['theoryA']?:$b['theoryP']).'|'.$b['number'].'|'.$b['id']));
    $totalAll=count($rows);$line=(string)($options['line']??'ALL');$query=mb_strtolower(trim((string)($options['query']??'')));
    if($line!=='ALL')$rows=array_values(array_filter($rows,fn($r)=>csp_known_line($r)===$line));
    if($query!=='')$rows=array_values(array_filter($rows,fn($r)=>str_contains(mb_strtolower($r['number'].' '.$r['destination']),$query)));
    $total=count($rows);$pageSize=max(10,min(100,(int)($options['page_size']??25)));$pages=max(1,(int)ceil($total/$pageSize));$page=max(1,min($pages,(int)($options['page']??1)));
    if(array_key_exists('page',$options))$rows=array_slice($rows,($page-1)*$pageSize,$pageSize);
    $coverage=csp_sql('SELECT first_seen,last_seen FROM history_coverage WHERE station=? AND day=?',[$station,$day])->fetch(PDO::FETCH_ASSOC)?:null;
    return ['rows'=>$rows,'station'=>$station,'day'=>$day,'total'=>$total,'totalAll'=>$totalAll,'page'=>$page,'pages'=>$pages,'pageSize'=>$pageSize,'coverage'=>$coverage];
}
function csp_report_audit(string $day): array {
    $start=strtotime($day.' 00:00:00');$end=strtotime($day.' +1 day');
    return csp_sql('SELECT stamp,actor,action,detail FROM audit WHERE (stamp>=? AND stamp<?) OR (action=? AND detail LIKE ?) ORDER BY stamp,id',[$start,$end,'history_edit','%"day":"'.$day.'"%'])->fetchAll(PDO::FETCH_ASSOC);
}
function csp_train_events(string $number,array $audit): array {
    $events=[];foreach($audit as $entry){$d=json_decode($entry['detail'],true)?:[];$n=(string)($d['number']??$d['train']['number']??'');if($n!==$number)continue;$entry['detail']=$d;$events[]=$entry;}return $events;
}
function csp_train_detail(string $id,string $station,string $day): array {
    csp_report_context($station,$day);if(!preg_match('/^[a-f0-9]{64}$/',$id))throw new InvalidArgumentException('Corsa non valida.');
    $r=csp_sql('SELECT payload,status,archived FROM operations_history WHERE id=? AND station=? AND day=?',[$id,$station,$day])->fetch(PDO::FETCH_ASSOC);
    if($r){$row=json_decode($r['payload'],true);$row['historyStatus']=str_replace('NON CONCLUSA','ESITO NON RILEVATO',$r['status']);$row['archivedAt']=(int)$r['archived'];}
    else{$payload=csp_sql('SELECT payload FROM operations_rows WHERE id=? AND station=? AND day=?',[$id,$station,$day])->fetchColumn();if(!$payload)throw new InvalidArgumentException('Corsa non disponibile per questa giornata e stazione.');$row=json_decode($payload,true);}
    $edit=csp_sql('SELECT * FROM history_edits WHERE id=? AND station=? AND day=?',[$id,$station,$day])->fetch(PDO::FETCH_ASSOC)?:null;$row=csp_report_edit_row($row,$edit);
    return ['editable'=>(bool)$r,'row'=>$row,'status'=>csp_row_status($row),'events'=>csp_train_events((string)$row['number'],csp_report_audit($day)),'day'=>$day,'station'=>$station];
}
function csp_daily_report(string $station,string $day,bool $withEvents=true): array {
    $report=csp_operations_history($station,$day);$rows=$report['rows'];$ids=array_fill_keys(csp_sql('SELECT id FROM operations_history WHERE station=? AND day=?',[$station,$day])->fetchAll(PDO::FETCH_COLUMN),true);
    foreach(csp_sql('SELECT payload FROM operations_rows WHERE station=? AND day=?',[$station,$day])->fetchAll(PDO::FETCH_COLUMN) as $payload){$r=json_decode($payload,true);if(!isset($ids[$r['id']])){$covered=false;foreach($rows as $existing)if(csp_operation_covered($r,$existing)){$covered=true;break;}if($covered)continue;$r['statusBadge']=csp_row_status($r);$rows[]=$r;}}
    usort($rows,fn($a,$b)=>strcmp(($a['theoryA']?:$a['theoryP']).'|'.$a['number'].'|'.$a['id'],($b['theoryA']?:$b['theoryP']).'|'.$b['number'].'|'.$b['id']));
    if($withEvents){$audit=csp_report_audit($day);foreach($rows as &$r)$r['events']=csp_train_events((string)$r['number'],$audit);unset($r);}
    $report['rows']=$rows;$report['stationName']=csp_stations()[$station];$report['generatedAt']=date('d/m/Y H:i:s');return $report;
}
function csp_finalize_previous_days(): void {
    foreach(csp_sql('SELECT id,day,station,payload FROM operations_rows WHERE day<?',[date('Y-m-d')])->fetchAll(PDO::FETCH_ASSOC) as $r){
        $row=json_decode($r['payload'],true);$status=csp_history_status($row)??'ESITO NON RILEVATO · raccolta terminata senza conferma di passaggio';
        csp_sql('INSERT INTO operations_history VALUES (?,?,?,?,?,?) ON CONFLICT DO NOTHING',[$r['id'],$r['day'],$r['station'],$r['payload'],$status,time()]);
    }
}

// One observation per recorded train/movement at the selected station, never per poll.
function csp_delay_statistics(string $station,string $day,string $line='ALL'): array {
    $report=csp_daily_report($station,$day,false);$buckets=array_fill_keys(['0–4 min','5–8 min','9–14 min','15–29 min','30+ min'],0);
    $hours=[];for($h=0;$h<24;$h++)$hours[$h]=['hour'=>sprintf('%02d:00',$h),'count'=>0,'sum'=>0,'mean'=>null];
    $sum=0;$max=0;$count=0;$important=0;$unknown=0;$suppressed=0;$arrivals=0;$departures=0;
    foreach($report['rows'] as $row){
        $covered=false;foreach($report['rows'] as $other)if(csp_operation_covered($row,$other)){$covered=true;break;}if($covered)continue;
        if($line!=='ALL'&&csp_known_line($row)!==$line)continue;
        $type=!empty($row['theoryA'])?'A':'P'; // Arrival takes precedence; departure-only origins are included.
        if(!empty($row['suppressedA'])||!empty($row['suppressedP'])){$suppressed++;continue;}
        $delay=$row['delay'.$type]??null;$theory=$row['theory'.$type]??'';
        if($delay===null||!is_numeric($delay)||($row['delayKnown'.$type]??true)===false||!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]/',$theory,$m)){$unknown++;continue;}
        $delay=max(0,(int)$delay);$sum+=$delay;$max=max($max,$delay);$count++;if($delay>=9)$important++;
        if($type==='A')$arrivals++;else $departures++;
        $key=$delay<5?'0–4 min':($delay<9?'5–8 min':($delay<15?'9–14 min':($delay<30?'15–29 min':'30+ min')));$buckets[$key]++;
        $hour=(int)$m[1];$hours[$hour]['count']++;$hours[$hour]['sum']+=$delay;
    }
    foreach($hours as &$hour){if($hour['count'])$hour['mean']=round($hour['sum']/$hour['count'],1);unset($hour['sum']);}unset($hour);
    return ['station'=>$station,'day'=>$day,'count'=>$count,'mean'=>$count?round($sum/$count,1):null,'maximum'=>$count?$max:null,'important'=>$important,'importantPercent'=>$count?round(100*$important/$count,1):null,'unknown'=>$unknown,'suppressed'=>$suppressed,'arrivals'=>$arrivals,'departures'=>$departures,'buckets'=>$buckets,'hours'=>array_values($hours),'coverage'=>$report['coverage']];
}

// Analysis is read-only: all stored observations, with one sample per train/line/day.
function csp_analysis_samples(string $station,string $day,string $scope='network'): array {
    csp_report_context($station,$day);if(!in_array($scope,['station','network'],true))throw new InvalidArgumentException('Ambito analisi non valido.');
    $where='day=?'.($scope==='station'?' AND station=?':'');$params=$scope==='station'?[$day,$station]:[$day];$saved=[];
    foreach(csp_sql('SELECT id,payload,updated FROM operations_rows WHERE '.$where,$params)->fetchAll(PDO::FETCH_ASSOC) as $r){$row=json_decode($r['payload'],true);$row['_updated']=(int)$r['updated'];$saved[$r['id']]=$row;}
    foreach(csp_sql('SELECT id,payload,status,archived FROM operations_history WHERE '.$where,$params)->fetchAll(PDO::FETCH_ASSOC) as $r){$row=json_decode($r['payload'],true);$row['historyStatus']=$r['status'];$row['_updated']=max((int)$r['archived'],$saved[$r['id']]['_updated']??0);$saved[$r['id']]=$row;}
    foreach(csp_sql('SELECT * FROM history_edits WHERE '.$where,$params)->fetchAll(PDO::FETCH_ASSOC) as $edit)if(isset($saved[$edit['id']])){$saved[$edit['id']]=csp_report_edit_row($saved[$edit['id']],$edit);$saved[$edit['id']]['_updated']=max($saved[$edit['id']]['_updated'],(int)$edit['updated']);}
    // Compact old partial A/P pairings before ranking station observations.
    $groups=[];foreach($saved as $r)$groups[$r['station'].'|'.$r['number']][]=$r;$chosen=[];
    foreach($groups as $group)foreach($group as $row){
        $covered=false;foreach($group as $other)if(csp_operation_covered($row,$other)){$covered=true;break;}if($covered)continue;
        $line=csp_known_line($row)??'UNKNOWN';$type=!empty($row['theoryA'])?'A':'P';$probe=$row;$probe['theoryP']='';$terminal=$type==='A'&&csp_movement_absence($probe,'P')==='Non prevista';
        $key=$line.'|'.trim((string)$row['number']);$priority=[$terminal?1:0,$row['_updated'],$type==='A'?1:0,count($row['refs']??[]),(string)$row['station']];
        if(isset($chosen[$key])&&$priority<=$chosen[$key]['priority'])continue;
        $delay=$row['delay'.$type]??null;$known=$delay!==null&&is_numeric($delay)&&($row['delayKnown'.$type]??true)!==false;
        $time=$row['theory'.$type]??'';$hour=preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]/',$time,$m)?(int)$m[1]:null;
        $chosen[$key]=['priority'=>$priority,'sample'=>['id'=>$row['id'],'number'=>(string)$row['number'],'line'=>$line,'destination'=>$row['destination'],'station'=>(string)$row['station'],'time'=>$time,'hour'=>$hour,'type'=>$type,'delay'=>$known?max(0,(int)$delay):null,'suppressed'=>!empty($row['suppressedA'])||!empty($row['suppressedP']),'terminal'=>$terminal,'updated'=>$row['_updated']]];
    }
    $coverage=csp_sql('SELECT station,first_seen,last_seen FROM history_coverage WHERE '.$where,$params)->fetchAll(PDO::FETCH_ASSOC);
    return ['station'=>$station,'day'=>$day,'scope'=>$scope,'samples'=>array_values(array_column($chosen,'sample')),'coverage'=>$coverage,'generatedAt'=>time()];
}
