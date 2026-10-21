<?php
declare(strict_types=1);
function csp_known_line(array $row): ?string {
    $n=trim((string)$row['number']);
    // L7 shuttles use five-digit 5xxxx numbers, shared prefix with L5.
    $station=(string)($row['station']??'');$dest=mb_strtolower((string)($row['destination']??''));
    if($station==='834'||str_contains($dest,'monte sant')||str_contains($dest,'monte s.angelo')||($station==='821'&&preg_match('/^5[0-9]{4}$/',$n)))return 'F7';
    if(preg_match('/^1[0-9]{3}$/',$n))return 'L1';
    if(preg_match('/^1[0-9]{4}$/',$n))return 'TORRE';
    foreach(['L4'=>'4','L6'=>'6','L8'=>'8'] as $line=>$prefix)if(preg_match('/^'.$prefix.'[0-9]+$/',$n))return $line;
    foreach(['F5'=>'5','F9'=>'9','F7'=>'7'] as $line=>$prefix) if(preg_match('/^'.$prefix.'[0-9]{3,4}$/',$n)) return $line;
    $map=csp_train_lines();if(isset($map[$n])) return $map[$n];
    $dest=mb_strtolower($row['destination']);foreach(['L1'=>'sorrento','L6'=>'sarno','L4'=>'poggiomarino','L8'=>'baiano','TORRE'=>'oplonti'] as $line=>$word) if(str_contains($dest,$word)) return $line;
    return null;
}
function csp_internal_direction(array $row): array {
    $line=csp_known_line($row);$n=trim((string)$row['number']);
    if(!preg_match('/^[0-9]+$/',$n)||!$line)return ['parity'=>'','direction'=>'Direzione non determinata'];
    $even=((int)substr($n,-1)%2)===0;
    if($line==='F7')$direction=$even?'Monte Sant’Angelo → Soccavo':'Soccavo → Monte Sant’Angelo';
    elseif(str_starts_with($line,'F'))$direction=$even?'Da Napoli verso la linea':'Verso Napoli Montesanto';
    else $direction=$even?'Verso Napoli Porta Nolana':'Da Napoli verso la linea';
    return ['parity'=>$even?'even':'odd','direction'=>$direction];
}
function csp_movement_absence(array $row,string $type): string {
    if(!empty($row['theory'.$type]))return '';
    $line=csp_known_line($row);$station=(string)($row['station']??'');
    $terminals=['L1'=>['1','62'],'TORRE'=>['1','41'],'L4'=>['1','22'],'L6'=>['1','25'],'L8'=>['1','84','70'],'F5'=>['801','830'],'F9'=>['801','819'],'F7'=>['821','834']];
    $direction=csp_internal_direction($row);$parity=$direction['parity'];
    if(!$parity||!in_array($station,$terminals[$line]??[],true))return 'Non rilevato';
    $arrival=$line==='F7'?($parity==='even'?'821':'834'):(str_starts_with($line,'F')?($parity==='odd'?'801':null):($parity==='even'?'1':null));
    $ends=$terminals[$line];$atArrival=$arrival!==null?$arrival===$station:!in_array($station,['1','801'],true);
    return ($type==='P'&&$atArrival)||($type==='A'&&!$atArrival)?'Non prevista':'Non rilevato';
}
function csp_routes(): array {return json_decode(file_get_contents(__DIR__.'/percorsi.json'),true,512,JSON_THROW_ON_ERROR);}
function csp_rule_matches(array $r,string $station,string $tipo): bool {
    $ids=json_decode($r['stations'],true);if(!in_array($station,$ids,true)) return false;
    if($r['scope']==='range' && count($ids)>1) {
        if($station===$ids[0] && $tipo==='A') return false;
        if($station===$ids[count($ids)-1] && $tipo==='P') return false;
    }
    return true;
}
function csp_apply_network(array $trains,string $station,string $tipo,bool $retain=true): array {
    $rules=csp_sql('SELECT * FROM route_rules WHERE day=?',[date('Y-m-d')])->fetchAll(PDO::FETCH_ASSOC);
    $seen=array_fill_keys(array_column($trains,'id'),true);
    $previous=csp_sql('SELECT payload FROM snapshots WHERE id=? AND day=?',[$station.'|'.$tipo,date('Y-m-d')])->fetchColumn();
    $overrideActions=[];if($retain&&$previous)$overrideActions=csp_sql('SELECT id,action FROM overrides WHERE day=? AND station=? AND tipo=?',[date('Y-m-d'),$station,$tipo])->fetchAll(PDO::FETCH_KEY_PAIR);
    if($retain && $previous) foreach(json_decode($previous,true) as $old) {
        if(isset($seen[$old['id']])) continue;
        $removed=$overrideActions[$old['id']]??null;if($removed==='remove') continue;
        $scheduled=strtotime(date('Y-m-d').' '.($old['time']??''));
        if(!$scheduled || time()>$scheduled+10800) continue;
        foreach($rules as $r) if((string)$old['number']===$r['number'] && csp_rule_matches($r,$station,$tipo)) {$old['retained']=true;$trains[]=$old;$seen[$old['id']]=true;break;}
    }
    $events=csp_sql('SELECT refs,payload FROM operations_events WHERE day=?',[date('Y-m-d')])->fetchAll(PDO::FETCH_ASSOC);$byId=[];
    foreach($events as $e) foreach(json_decode($e['refs'],true) as $id) $byId[$id]=json_decode($e['payload'],true);
    foreach($trains as &$t) {
        foreach($rules as $r) if((string)$t['number']===$r['number'] && csp_rule_matches($r,$station,$tipo)) {
            $t['suppressed']=true;$t['suppressionScope']=$r['label'];$t['suppressionRuleId']=$r['id'];$t['suppressionDetails']=['scope'=>$r['scope'],'label'=>$r['label'],'stations'=>json_decode($r['stations'],true),'actor'=>$r['actor'],'updated'=>$r['updated']];
        }
        $e=$byId[$t['id']]??null;if($e) {
            $actual=$tipo==='A'?($e['actualA']??''):($e['actualP']??'');$bin=$tipo==='A'?($e['binA']??''):($e['binP']??'');
            if($bin!=='') $t['platform']=$bin;
            if($actual!=='') {$t['actualTime']=$actual;$t['actualTimeSource']='manual';}
            $t['completedManually']=!empty($e['completed']);
            if(!empty($e['note'])) $t['operatorNote']=$e['note'];
        }
    }unset($t);return $trains;
}
function csp_complete_effective(array $row): array {
    foreach(['A','P'] as $type){
        $source=$row['actualSource'.$type]??'';
        if(!empty($row['actual'.$type])&&$source!=='calculated')continue;
        if(!empty($row['suppressed'.$type])){if($source==='calculated'){$row['actual'.$type]='';$row['actualSource'.$type]='';}continue;}
        $theory=$row['theory'.$type]??'';$delay=$row['delay'.$type]??null;
        if(!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/',$theory)){if($source==='calculated'){$row['actual'.$type]='';$row['actualSource'.$type]='';}continue;}
        // Internal display convention: no reported delay means theoretical time.
        $row['actualAssumedZero'.$type]=$delay===null||!is_numeric($delay)||($row['delayKnown'.$type]??true)===false;
        if($row['actualAssumedZero'.$type])$delay=0;
        $parts=explode(':',$theory);$seconds=((int)$parts[0]*3600+(int)$parts[1]*60+(int)($parts[2]??0)+(int)$delay*60)%86400;if($seconds<0)$seconds+=86400;
        $row['actual'.$type]=sprintf('%02d:%02d',intdiv($seconds,3600),intdiv($seconds%3600,60)).(count($parts)===3?sprintf(':%02d',$seconds%60):'');
        $row['actualSource'.$type]='calculated';
    }
    return $row;
}
function csp_ops_id(array $row): string {return hash('sha256',date('Y-m-d').'|'.$row['station'].'|'.$row['number'].'|'.implode('|',array_column($row['refs'],'id')));}
function csp_operations_board(string $station,string $name,?array $sourceCycle=null): array {
    if($sourceCycle===null)csp_history_watch($station);
    $feeds=[];$errors=[];$updated=[];
    foreach(['A','P'] as $tipo) {
        try {if($sourceCycle!==null){if(!isset($sourceCycle[$tipo]))throw new RuntimeException('Lettura non disponibile in questo ciclo del monitor.');$p=$sourceCycle[$tipo];}else $p=csp_source($station,$tipo,$name,false,15);$updated[$tipo]=$p['fetchedAt']??null;}
        catch(Throwable $e) {
            $errors[$tipo]=$e->getMessage();$cached=csp_sql('SELECT payload FROM cache WHERE id=?',[$station.'|'.$tipo])->fetchColumn();
            $p=$cached?json_decode($cached,true):['station'=>$station,'tipo'=>$tipo,'trains'=>[]];
            $updated[$tipo]=$p['fetchedAt']??null;
        }
        $p=csp_apply($p);$feeds[$tipo]=$p['trains'];
        foreach(csp_apply_network($p['removed'],$station,$tipo,false) as $t) {$t['hidden']=true;$feeds[$tipo][]=$t;}
    }
    $positions=[];foreach(['A','P'] as $tipo)foreach($feeds[$tipo] as $i=>$train)$positions[$tipo][$train['id']]=$i;
    $group=[];foreach(['A','P'] as $tipo) foreach($feeds[$tipo] as $t) $group[(string)$t['number']][$tipo][]=$t;
    $rows=[];$savedRows=[];
    foreach($group as $number=>$lists) {
        $arr=$lists['A']??[];$dep=$lists['P']??[];
        // Only merge a unique number in both boards. Duplicate numbers remain separate.
        $pairs=[];
        if(count($arr)<=1 && count($dep)<=1) $pairs[]=['A'=>$arr[0]??null,'P'=>$dep[0]??null];
        else {foreach($arr as $t) $pairs[]=['A'=>$t,'P'=>null];foreach($dep as $t) $pairs[]=['A'=>null,'P'=>$t];}
        foreach($pairs as $pair) {
            $a=$pair['A'];$p=$pair['P'];$base=$p??$a;$refs=[];
            foreach(['A'=>$a,'P'=>$p] as $tipo=>$t) if($t) $refs[]=['id'=>$t['id'],'tipo'=>$tipo,'hidden'=>!empty($t['hidden'])];
            $row=['station'=>$station,'number'=>(string)$number,'destination'=>$base['destination'],'category'=>$base['category']??'','refs'=>$refs,
              'orderA'=>$a?$positions['A'][$a['id']]:PHP_INT_MAX,'orderP'=>$p?$positions['P'][$p['id']]:PHP_INT_MAX,
              'theoryA'=>$a['time']??'','theoryP'=>$p['time']??'',
              'actualA'=>$a['actualTime']??$a['actualArrival']??'','actualP'=>$p['actualTime']??$p['actualDeparture']??'',
              'actualSourceA'=>$a['actualTimeSource']??(!empty($a['actualArrival'])?'source':''),'actualSourceP'=>$p['actualTimeSource']??(!empty($p['actualDeparture'])?'source':''),
              'delayA'=>$a['delay']??null,'delayP'=>$p['delay']??null,'delayKnownA'=>$a['delayKnown']??true,'delayKnownP'=>$p['delayKnown']??true,'binA'=>$a['platform']??'','binP'=>$p['platform']??'',
              'suppressedA'=>!empty($a['suppressed']),'suppressedP'=>!empty($p['suppressed']),
              'scope'=>$a['suppressionScope']??$p['suppressionScope']??'','ruleId'=>$a['suppressionRuleId']??$p['suppressionRuleId']??'',
              'suppressionDetails'=>$a['suppressionDetails']??$p['suppressionDetails']??null,
              'completedManually'=>!empty($a['completedManually'])||!empty($p['completedManually']),
              'note'=>$a['operatorNote']??$p['operatorNote']??'', 'sourceInfo'=>$base['info']??'', 'sourceInfoA'=>$a['info']??'', 'sourceInfoP'=>$p['info']??''];
            $row['originA']=$a['destination']??'';
            if($a&&!$p&&csp_movement_absence($row,'P')==='Non prevista'){$row['destination']=$name;$row['arrivalOnlyTerminal']=true;}
            $row=csp_complete_effective($row);
            $row['id']=csp_ops_id($row);$row['sortTime']=$row['theoryA']?:$row['theoryP'];
            $savedRows[]=[$row['id'],date('Y-m-d'),$station,(string)$number,csp_json($row),time()];
            $rows[]=$row;
        }
    }
    csp_ops_batch('operations_rows',$savedRows,'ON CONFLICT(id) DO UPDATE SET payload=excluded.payload,updated=excluded.updated');
    if(count($errors)<2)csp_record_coverage($station);
    // Keep the upstream live sequence rather than sorting from midnight.
    $rows=csp_reconcile_operations($station,$rows,$errors);
    return ['rows'=>$rows,'errors'=>$errors,'updated'=>$updated,'station'=>$station,'stationName'=>$name];
}
function csp_ops_row(string $id): array {
    if(!preg_match('/^[a-f0-9]{64}$/',$id)) throw new InvalidArgumentException('Identificativo corsa non valido.');
    $row=csp_sql('SELECT payload FROM operations_rows WHERE id=? AND day=?',[$id,date('Y-m-d')])->fetchColumn();
    if(!$row) throw new InvalidArgumentException('Corsa scaduta: aggiornare il pannello.');return json_decode($row,true);
}
function csp_save_actual(array $input,string $actor): array {
    $row=csp_ops_row((string)($input['id']??''));$p=[];
    foreach(['actualA','actualP'] as $key) {
        $value=trim((string)($input[$key]??''));
        if($value!=='' && !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/',$value)) throw new InvalidArgumentException('Orario non valido (HH:MM oppure HH:MM:SS).');
        if($value!=='' && $row[$key==='actualA'?'theoryA':'theoryP']==='') throw new InvalidArgumentException('Il movimento non è presente nella sorgente.');
        $p[$key]=$value;
    }
    foreach(['binA','binP'] as $key) {$value=trim((string)($input[$key]??''));if(mb_strlen($value)>12) throw new InvalidArgumentException('Binario troppo lungo.');$p[$key]=$value;}
    $p['note']=trim((string)($input['note']??''));if(mb_strlen($p['note'])>500) throw new InvalidArgumentException('Nota: massimo 500 caratteri.');
    $refs=array_column($row['refs'],'id');
    csp_tx(function() use($input,$p,$refs,$actor,$row) {
        foreach($refs as $ref) csp_sql('INSERT INTO operations_events VALUES (?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET payload=excluded.payload,actor=excluded.actor,updated=excluded.updated',[$ref,date('Y-m-d'),csp_json([$ref]),csp_json($p),$actor,time()]);
        csp_sql('INSERT INTO audit(stamp,actor,action,detail) VALUES (?,?,?,?)',[time(),$actor,'actual_times',csp_json(['station'=>$row['station'],'number'=>$row['number'],'values'=>$p])]);
        $changes=[];foreach(['A','P'] as $tipo){$bin=$p['bin'.$tipo]??'';if($bin!=='' && $row['bin'.$tipo]!==$bin) $changes[]=($tipo==='A'?'arrivo':'partenza').' '.$bin;}
        // Platform edits update the board; Telegram messages require explicit composition.
        csp_align_snapshots([$row['station']],$row['number']);
    });return ['ok'=>true];
}
function csp_align_snapshots(array $ids,string $number): void {
    foreach($ids as $station) foreach(['A','P'] as $tipo) {
        $key=$station.'|'.$tipo;$saved=csp_sql('SELECT payload FROM snapshots WHERE id=? AND day=?',[$key,date('Y-m-d')])->fetchColumn();
        $cache=csp_sql('SELECT payload FROM cache WHERE id=?',[$key])->fetchColumn();if(!$saved||!$cache) continue;
        $map=json_decode($saved,true);$live=csp_apply(json_decode($cache,true));
        foreach($live['trains'] as $t) if((string)$t['number']===$number) $map[$t['id']]=$t;
        csp_sql('UPDATE snapshots SET payload=? WHERE id=?',[csp_json($map),$key]);
    }
}
function csp_save_scope(array $input,string $actor): array {
    $row=csp_ops_row((string)($input['id']??''));$line=(string)($input['line']??'');$route=csp_routes()[$line]??null;
    if(!$route || !in_array($row['station'],$route['ids'],true)) throw new InvalidArgumentException('Scegliere un percorso che comprende la stazione della corsa.');
    $known=csp_known_line($row);if($known && $line!==$known && !($known==='TORRE' && $line==='L4')) throw new InvalidArgumentException('La corsa appartiene a un altro percorso: scegliere la linea corretta.');
    $scope=(string)($input['scope']??'');$names=csp_stations();
    if($scope==='line') {$ids=$route['ids'];$label='Intero percorso · '.$route['label'];}
    elseif($scope==='station') {$ids=[$row['station']];$label='Stazione · '.$names[$row['station']];}
    elseif($scope==='range') {
        $from=(string)($input['from']??'');$to=(string)($input['to']??'');$a=array_search($from,$route['ids'],true);$b=array_search($to,$route['ids'],true);
        if($a===false || $b===false || $a===$b) throw new InvalidArgumentException('Selezionare due stazioni diverse del percorso.');
        $ids=array_slice($route['ids'],min($a,$b),abs($a-$b)+1);if($a>$b) $ids=array_reverse($ids);
        $label='Tratta · '.$names[$from].' → '.$names[$to];
    } else throw new InvalidArgumentException('Ambito di soppressione non valido.');
    $id=hash('sha256',date('Y-m-d').'|'.$row['number'].'|'.$line);
    return csp_tx(function() use($id,$row,$line,$scope,$ids,$label,$actor) {
        $old=csp_sql('SELECT * FROM route_rules WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
        $changed=!$old || $old['scope']!==$scope || $old['stations']!==csp_json($ids);
        csp_sql('INSERT INTO route_rules VALUES (?,?,?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET scope=excluded.scope,stations=excluded.stations,label=excluded.label,actor=excluded.actor,updated=excluded.updated',[$id,date('Y-m-d'),$row['number'],$line,$scope,csp_json($ids),$label,$actor,time()]);
        if($changed) {
            csp_sql('INSERT INTO audit(stamp,actor,action,detail) VALUES (?,?,?,?)',[time(),$actor,'suppress_scope',csp_json(['number'=>$row['number'],'label'=>$label,'stations'=>$ids])]);
            if(csp_targets('channel') && getenv('EAV_TELEGRAM_BOT_TOKEN')) csp_queue('scope:'.bin2hex(random_bytes(12)),csp_brief('suppress',$row,'',$label),'channel','HTML');
            csp_align_snapshots(array_unique(array_merge($ids,$old?json_decode($old['stations'],true):[])),$row['number']);
        }
        return ['ok'=>true,'ruleId'=>$id,'label'=>$label];
    });
}
function csp_restore_scope(string $id,string $actor): array {
    if(!preg_match('/^[a-f0-9]{64}$/',$id)) throw new InvalidArgumentException('Regola non valida.');
    return csp_tx(function() use($id,$actor) {
        $r=csp_sql('SELECT * FROM route_rules WHERE id=? AND day=?',[$id,date('Y-m-d')])->fetch(PDO::FETCH_ASSOC);if(!$r) return ['ok'=>true,'unchanged'=>true];
        csp_sql('DELETE FROM route_rules WHERE id=?',[$id]);
        csp_sql('INSERT INTO audit(stamp,actor,action,detail) VALUES (?,?,?,?)',[time(),$actor,'restore_scope',csp_json($r)]);
        if(csp_targets('channel') && getenv('EAV_TELEGRAM_BOT_TOKEN')) csp_queue('scope-restore:'.bin2hex(random_bytes(12)),csp_brief('restore',json_decode(csp_sql('SELECT payload FROM operations_rows WHERE number=? AND day=? ORDER BY updated DESC LIMIT 1',[$r['number'],date('Y-m-d')])->fetchColumn()?:csp_json(['number'=>$r['number'],'destination'=>'']),true),'',$r['label']),'channel','HTML');
        csp_align_snapshots(json_decode($r['stations'],true),$r['number']);return ['ok'=>true];
    });
}

// Internal history never removes or changes the official public board.
function csp_history_status(array $row): ?string {
    if(!empty($row['suppressedA'])||!empty($row['suppressedP'])) return 'SOPPRESSA';
    if(!empty($row['completedManually']))return 'EFFETTUATA · passaggio segnato manualmente';
    if((!empty($row['actualA'])&&($row['actualSourceA']??'')==='manual')||(!empty($row['actualP'])&&($row['actualSourceP']??'')==='manual')) return 'EFFETTUATA · registrazione manuale';
    if(!empty($row['refs']) && count(array_filter($row['refs'],fn($r)=>!empty($r['hidden'])))===count($row['refs'])) return 'RIMOSSA MANUALMENTE';
    return null;
}
// Keep database round trips bounded as train counts grow (also below SQLite variable limits).
function csp_ops_batch(string $table,array $records,string $conflict): void {
    if(!in_array($table,['operations_rows','operations_history','delay_destinations'],true))throw new LogicException('Tabella batch non valida.');
    foreach(array_chunk($records,100) as $chunk){$width=count($chunk[0]);$values=implode(',',array_fill(0,count($chunk),'('.implode(',',array_fill(0,$width,'?')).')'));csp_sql('INSERT INTO '.$table.' VALUES '.$values.' '.$conflict,array_merge(...$chunk));}
}
function csp_ops_delete(string $table,array $ids): void {
    if(!in_array($table,['operations_history','operations_recovery'],true))throw new LogicException('Tabella eliminazione non valida.');
    foreach(array_chunk(array_values(array_unique($ids)),500) as $chunk)csp_sql('DELETE FROM '.$table.' WHERE id IN ('.implode(',',array_fill(0,count($chunk),'?')).')',$chunk);
}
function csp_reconcile_operations(string $station,array $rows,array $errors): array {
    return csp_tx(function()use($station,$rows,$errors){
        $day=date('Y-m-d');
        $recovered=csp_sql('SELECT id,payload FROM operations_recovery WHERE station=? AND day=?',[$station,$day])->fetchAll(PDO::FETCH_ASSOC);
        foreach($recovered as $saved){$restored=json_decode($saved['payload'],true);$same=false;foreach($rows as $live)if(array_intersect(array_column($restored['refs'],'id'),array_column($live['refs'],'id'))){$same=true;break;}
            if($same)csp_sql('DELETE FROM operations_recovery WHERE id=?',[$saved['id']]);else{$restored=csp_refresh_recovery($restored);$restored['recoveredInternal']=true;$rows[]=$restored;csp_sql('UPDATE operations_rows SET payload=?,updated=? WHERE id=?',[csp_json($restored),time(),$restored['id']]);}}
        $liveIds=[];$liveRefs=[];$active=[];
        foreach($rows as $row){$liveIds[$row['id']]=true;foreach($row['refs'] as $ref)$liveRefs[$ref['id']]=true;}
        $archive=[];$clearHistory=[];$clearRecovery=[];$automatic=[];
        foreach($rows as $row){
            $status=csp_history_status($row);
            if($status){$clearRecovery[]=$row['id'];$archive[]=[$row['id'],$day,$station,csp_json($row),$status,time()];}
            else {$clearHistory[]=$row['id'];$active[]=$row;}
        }
        $old=csp_sql('SELECT id,payload FROM operations_rows WHERE station=? AND day=?',[$station,$day])->fetchAll(PDO::FETCH_ASSOC);
        foreach($old as $saved){
            if(isset($liveIds[$saved['id']]))continue;
            $row=json_decode($saved['payload'],true);$overlap=false;$healthy=true;
            foreach($row['refs'] as $ref){if(isset($liveRefs[$ref['id']]))$overlap=true;if(isset($errors[$ref['tipo']]))$healthy=false;}
            // A changed arrival/departure pairing is not a completed train.
            if($overlap){$clearHistory[]=$saved['id'];continue;}
            if(!$healthy)continue;
            $status=csp_history_status($row)??'PASSAGGIO AUTOMATICO · non più nel teleindicatore';
            $automatic[]=[$saved['id'],$day,$station,csp_json($row),$status,time()];
        }
        // Old arrival/departure pairings can leave a partial copy of the same movement.
        $automaticRows=[];foreach($automatic as $entry){$r=json_decode($entry[3],true);$r['historyStatus']=$entry[4];$automaticRows[]=$r;}
        $completeIds=array_fill_keys(array_column(csp_compact_history($automaticRows),'id'),true);
        foreach($automatic as $entry)if(!isset($completeIds[$entry[0]]))$clearHistory[]=$entry[0];
        $automatic=array_values(array_filter($automatic,fn($entry)=>isset($completeIds[$entry[0]])));
        csp_ops_delete('operations_recovery',$clearRecovery);
        csp_ops_delete('operations_history',$clearHistory);
        csp_ops_batch('operations_history',$archive,'ON CONFLICT(id) DO UPDATE SET payload=excluded.payload,status=excluded.status');
        csp_ops_batch('operations_history',$automatic,'ON CONFLICT DO NOTHING');
        return $active;
    });
}
function csp_mark_passed(string $id,string $actor): array {
    $row=csp_ops_row($id);
    return csp_tx(function()use($row,$actor){
        foreach($row['refs'] as $ref){$old=csp_sql('SELECT payload FROM operations_events WHERE id=? AND day=?',[$ref['id'],date('Y-m-d')])->fetchColumn();$event=$old?json_decode($old,true):[];$event['completed']=true;
            csp_sql('INSERT INTO operations_events VALUES (?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET payload=excluded.payload,actor=excluded.actor,updated=excluded.updated',[$ref['id'],date('Y-m-d'),csp_json([$ref['id']]),csp_json($event),$actor,time()]);}
        csp_sql('INSERT INTO audit(stamp,actor,action,detail) VALUES (?,?,?,?)',[time(),$actor,'mark_passed',csp_json(['station'=>$row['station'],'number'=>$row['number']])]);return ['ok'=>true];
    });
}

function csp_recover_operation(string $id,string $actor): array {
    $row=csp_ops_row($id);
    return csp_tx(function()use($row,$actor,$id){
        foreach($row['refs'] as $ref){
            $saved=csp_sql('SELECT payload FROM operations_events WHERE id=? AND day=?',[$ref['id'],date('Y-m-d')])->fetchColumn();$event=$saved?json_decode($saved,true):[];
            unset($event['completed']);$event['actualA']='';$event['actualP']='';
            csp_sql('UPDATE operations_events SET payload=?,actor=?,updated=? WHERE id=?',[csp_json($event),$actor,time(),$ref['id']]);
            csp_sql('DELETE FROM overrides WHERE id=? AND day=?',[$ref['id'],date('Y-m-d')]);
        }
        $rules=csp_sql('SELECT * FROM route_rules WHERE number=? AND day=?',[$row['number'],date('Y-m-d')])->fetchAll(PDO::FETCH_ASSOC);
        foreach($rules as $rule)if(in_array($row['station'],json_decode($rule['stations'],true),true))csp_sql('DELETE FROM route_rules WHERE id=?',[$rule['id']]);
        $row['completedManually']=false;$row['actualA']='';$row['actualP']='';$row['actualSourceA']='';$row['actualSourceP']='';$row['suppressedA']=false;$row['suppressedP']=false;$row['scope']='';$row['ruleId']='';foreach($row['refs'] as &$ref)$ref['hidden']=false;unset($ref);
        csp_sql('INSERT INTO operations_recovery VALUES (?,?,?,?) ON CONFLICT(id) DO UPDATE SET day=excluded.day,station=excluded.station,payload=excluded.payload',[$id,date('Y-m-d'),$row['station'],csp_json($row)]);
        csp_sql('UPDATE operations_rows SET payload=?,updated=? WHERE id=?',[csp_json($row),time(),$id]);csp_sql('DELETE FROM operations_history WHERE id=?',[$id]);
        csp_sql('INSERT INTO audit(stamp,actor,action,detail) VALUES (?,?,?,?)',[time(),$actor,'recover_operation',csp_json(['station'=>$row['station'],'number'=>$row['number']])]);
        return ['ok'=>true,'message'=>'Registrazioni manuali annullate. Se EAV mantiene una soppressione, la corsa resta soppressa. Se non è più nel dato EAV, viene recuperata solo nell’elenco interno.'];
    });
}

function csp_refresh_recovery(array $row): array {
    $rules=csp_sql('SELECT * FROM route_rules WHERE number=? AND day=?',[$row['number'],date('Y-m-d')])->fetchAll(PDO::FETCH_ASSOC);
    foreach($row['refs'] as $ref){$tipo=$ref['tipo'];$saved=csp_sql('SELECT payload FROM operations_events WHERE id=? AND day=?',[$ref['id'],date('Y-m-d')])->fetchColumn();$e=$saved?json_decode($saved,true):[];
        if(!empty($e['completed']))$row['completedManually']=true;
        if(!empty($e['actual'.$tipo])){$row['actual'.$tipo]=$e['actual'.$tipo];$row['actualSource'.$tipo]='manual';}
        foreach($rules as $rule)if(csp_rule_matches($rule,$row['station'],$tipo)){$row['suppressed'.$tipo]=true;$row['scope']=$rule['label'];$row['ruleId']=$rule['id'];}
    }
    return csp_complete_effective($row);
}

function csp_train_lines(?string $day=null): array {
    $old=json_decode(file_get_contents(__DIR__.'/corse_linee.json'),true,512,JSON_THROW_ON_ERROR);
    $new=json_decode(file_get_contents(__DIR__.'/corse_linee_20261017.json'),true,512,JSON_THROW_ON_ERROR);
    return ($day??date('Y-m-d'))>='2026-10-17'?$new+$old:$old+$new;
}

require_once __DIR__.'/reports.php';
