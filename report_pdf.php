<?php
// Standalone PDF writer: no external service, no paid library, no filesystem export.
declare(strict_types=1);
final class CspReportPdf {
    private array $columns=[55,140,130,65,90,33,40,65,90,33,40];
    private array $pages=[];private string $stream='';private string $continuation='';private float $y=0;private array $report;private array $widths;
    public function __construct(array $report){$this->report=$report;$this->widths=json_decode(file_get_contents(__DIR__.'/pdf_helvetica_widths.json'),true);$this->page();}
    private function encode(string $s): string{return mb_convert_encoding(str_replace(['→','–','—','\u{00a0}'],[' -> ','-','-',' '],$s),'Windows-1252','UTF-8');}
    private function escape(string $s): string{return str_replace(['\\','(',')',"\r","\n"],['\\\\','\\(','\\)','',' '],$this->encode($s));}
    private function text(float $x,float $y,string $s,float $size=9,bool $bold=false,array $rgb=[.12,.18,.26]): void {
        $this->stream.=sprintf("%.3f %.3f %.3f rg BT /%s %.1f Tf %.1f %.1f Td (%s) Tj ET\n",...array_merge($rgb,[$bold?'F2':'F1',$size,$x,595.28-$y,$this->escape($s)]));
    }
    private function rect(float $x,float $y,float $w,float $h,array $rgb):void{$this->stream.=sprintf("%.3f %.3f %.3f rg %.1f %.1f %.1f %.1f re f\n",...array_merge($rgb,[$x,595.28-$y-$h,$w,$h]));}
    private function wrap(string $s,float $width,float $size): array {
        $lines=[];foreach(explode("\n",str_replace("\r",'',trim($s)))as $paragraph){$line='';$used=0;
            foreach(preg_split('/(\s+)/u',$paragraph,-1,PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY) as $word){$wordWidth=0;foreach(mb_str_split($word)as $char){$e=$this->encode($char);$wordWidth+=($this->widths[(string)ord($e[0]??' ')]??600)*$size/1000;}
                if($used+$wordWidth>$width&&trim($line)!==''){$lines[]=rtrim($line);$line='';$used=0;}if(trim($word)===''&&$line==='')continue;
                foreach(mb_str_split($word)as $char){$e=$this->encode($char);$cw=($this->widths[(string)ord($e[0]??' ')]??600)*$size/1000;if($used+$cw>$width&&$line!==''){$lines[]=rtrim($line);$line='';$used=0;}if($line===''&&$char===' ')continue;$line.=$char;$used+=$cw;}
            }if($line!=='')$lines[]=$line;}
        return $lines?:['-'];
    }
    private function page(): void {
        if($this->stream!=='')$this->pages[]=$this->stream;$this->stream='';
        $this->rect(0,0,841.89,72,[.07,.13,.22]);$this->text(30,29,'CSP | RESOCONTO OPERATIVO',16,true,[1,1,1]);
        $this->text(30,50,$this->report['stationName'].' | Giornata '.$this->report['day'],11,false,[.78,.88,.98]);
        $this->text(30,90,'Generato: '.$this->report['generatedAt'].' (Europe/Rome) | Corse registrate: '.count($this->report['rows']),9);
        $coverage=$this->report['coverage'];$this->text(30,106,$coverage?'Raccolta: '.date('H:i:s',(int)$coverage['first_seen']).' - '.date('H:i:s',(int)$coverage['last_seen']).'. Nessuna ricostruzione delle corse mai lette.':'Copertura storica non disponibile. Il documento include soltanto dati registrati.',8);
        $this->text(30,122,'Effettivi: CALC. = teorico + ritardo; MAN. = inserito dall’operatore; CORR. = corretto nel resoconto.',8);
        $this->text(30,134,'Esito non rilevato: manca la conferma di passaggio. Non indica una corsa non effettuata.',8);
        $this->y=147;$this->tableHead();if($this->continuation!==''){$this->text(33,$this->y+13,$this->continuation,10,true);$this->y+=26;}
    }
    private function ensure(float $height): void{if($this->y+$height>555)$this->page();}
    private function block(string $label,string $text,bool $bold=false): void {
        $lines=$this->wrap($label.$text,775,9.5);foreach($lines as $line){$this->ensure(15);$this->text(33,$this->y,$line,9.5,$bold);$this->y+=14;}
    }
    private function line(float $x,float $y,float $x2,float $y2): void {
        $this->stream.=sprintf("0.76 0.81 0.86 RG 0.4 w %.1f %.1f m %.1f %.1f l S\n",$x,595.28-$y,$x2,595.28-$y2);
    }
    private function tableHead(): void {
        $y=$this->y;$this->rect(30,$y,781,32,[.13,.24,.35]);
        $this->text(35,$y+19,'CORSA',8,true,[1,1,1]);$this->text(90,$y+19,'DESTINAZIONE / DIREZIONE',8,true,[1,1,1]);$this->text(230,$y+19,'STATO',8,true,[1,1,1]);
        foreach([355=>'ARRIVO',583=>'PARTENZA'] as $x=>$label){$this->text($x+5,$y+11,$label,8,true,[1,1,1]);$this->line($x,$y+16,$x+228,$y+16);foreach([[0,'Teorico'],[65,'Effettivo'],[155,'Bin.'],[188,'Rit.']] as [$dx,$name])$this->text($x+$dx+4,$y+27,$name,7.5,true,[1,1,1]);}
        $x=30;foreach($this->columns as $w){$this->line($x,$y,$x,$y+32);$x+=$w;}$this->line(811,$y,811,$y+32);$this->y+=32;
    }
    public function row(array $r,int $i): void {
        $this->continuation='';$status=csp_row_status($r);$direction=csp_internal_direction($r);$parity=$direction['parity']==='even'?'PARI':($direction['parity']==='odd'?'DISPARI':'');
        $values=[$r['number'].($parity?"\n".$parity:''),$r['destination']."\n".$direction['direction'],$status['label']];
        foreach(['A','P'] as $type){
            if(empty($r['theory'.$type])){$values[]=csp_movement_absence($r,$type);array_push($values,'-','-','-');continue;}
            $source=$r['actualSource'.$type]??'';$tag=['calculated'=>'CALC.','manual'=>'MAN.','report'=>'CORR.','source'=>'EAV'][$source]??'';
            $actual=($r['actual'.$type]??'')?:'-';if($actual!=='-'&&$tag)$actual.="\n".$tag;
            array_push($values,$r['theory'.$type],$actual,($r['bin'.$type]??'')?:'-',!empty($r['suppressed'.$type])?'SOP':(($r['delay'.$type]??null)===null?'-':$r['delay'.$type]." min"));
        }
        $lines=[];$height=30;foreach($values as $k=>$value){$lines[$k]=$this->wrap((string)$value,($this->columns[$k]-9)/1.12,9);$height=max($height,count($lines[$k])*12+14);}
        $this->ensure($height+2);$top=$this->y;$x=30;
        $bg=$status['kind']==='suppressed'?[.98,.91,.92]:($i%2?[.95,.97,.99]:[1,1,1]);$this->rect(30,$top,781,$height,$bg);$this->line(30,$top,811,$top);
        foreach($lines as $k=>$cell){$this->line($x,$top,$x,$top+$height);$color=$k===2&&$status['kind']==='suppressed'?[.55,.16,.23]:[.12,.18,.26];foreach($cell as $n=>$line)$this->text($x+4,$top+14+$n*12,$line,9,true,$color);$x+=$this->columns[$k];}
        $this->line(811,$top,811,$top+$height);$this->line(30,$top+$height,811,$top+$height);$this->y+=$height+12;$this->continuation='Corsa '.$r['number'].' - note (continuazione)';
        if(!empty($r['originA']))$this->block('Provenienza arrivo (EAV): ',$r['originA']);
        if(!empty($r['scope']))$this->block('Soppressione: ',$r['scope'],true);
        elseif($status['kind']==='suppressed')$this->block('Soppressione: ','presente nella sorgente EAV o nella gestione locale; ambito non specificato.',true);
        if(!empty($r['suppressionDetails'])){$d=$r['suppressionDetails'];$names=csp_stations();$this->block('Stazioni interessate: ',implode(' -> ',array_map(fn($id)=>$names[$id]??$id,$d['stations'])));$this->block('Registrata da: ',$d['actor'].' | '.date('H:i:s',(int)$d['updated']));}
        if(!empty($r['archivedAt']))$this->block('Registrazione nel resoconto: ',date('d/m/Y H:i:s',(int)$r['archivedAt']).($status['kind']==='automatic'?'. Il passaggio automatico non certifica un orario effettivo.':'.'));
        if(!empty($r['reportInfo']))$this->block('Integrazioni e correzioni: ',$r['reportInfo']);
        if(!empty($r['historyEdit']))$this->block('Ultima modifica resoconto: ',$r['historyEdit']['actor'].' | '.date('d/m/Y H:i:s',$r['historyEdit']['updated']));
        if(!empty($r['note']))$this->block('Nota interna: ',$r['note']);
        if(!empty($r['sourceInfoA'])||!empty($r['sourceInfoP'])){if(!empty($r['sourceInfoA']))$this->block('Informazioni arrivo EAV: ',$r['sourceInfoA']);if(!empty($r['sourceInfoP']))$this->block('Informazioni partenza EAV: ',$r['sourceInfoP']);}elseif(!empty($r['sourceInfo']))$this->block('Informazioni EAV: ',$r['sourceInfo']);
        foreach($r['events']??[] as $event){$d=$event['detail'];$parts=[];foreach(['station'=>'Stazione','label'=>'Ambito','action'=>'Azione','operation'=>'Operazione']as $key=>$label)if(isset($d[$key])&&is_scalar($d[$key]))$parts[]=$label.': '.$d[$key];foreach($d['values']??[]as $key=>$value)if($value!=='')$parts[]=$key.': '.$value;if(isset($d['train']['info']))$parts[]=$d['train']['info'];$this->block('Attività: ',date('H:i:s',(int)$event['stamp']).' | '.$event['actor'].' | '.$event['action'].($parts?' | '.implode(' | ',$parts):''));}
        $this->y+=6;
    }
    public function bytes(): string {
        if(!$this->report['rows'])$this->block('','Nessuna corsa registrata per questa stazione e giornata.');
        $this->pages[]=$this->stream;$this->stream='';$objects=[];$objects[1]='<< /Type /Catalog /Pages 2 0 R >>';$kids=[];
        $objects[3]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';$objects[4]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';$count=count($this->pages);
        foreach($this->pages as $i=>$content){$this->stream='';$this->text(30,578,'CSP - Dati operativi registrati | Pagina '.($i+1).' di '.$count,8);$content.=$this->stream;$page=5+$i*2;$stream=$page+1;$kids[]=$page.' 0 R';$objects[$page]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 841.89 595.28] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.$stream.' 0 R >>';$objects[$stream]='<< /Length '.strlen($content).' >>'."\nstream\n".$content.'endstream';}
        $objects[2]='<< /Type /Pages /Count '.$count.' /Kids ['.implode(' ',$kids).'] >>';ksort($objects);$pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";$offsets=[0];foreach($objects as $id=>$object){$offsets[$id]=strlen($pdf);$pdf.=$id." 0 obj\n".$object."\nendobj\n";}$start=strlen($pdf);$pdf.='xref'."\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";for($id=1;$id<=count($objects);$id++)$pdf.=sprintf("%010d 00000 n \n",$offsets[$id]);$pdf.='trailer << /Size '.(count($objects)+1).' /Root 1 0 R >>'."\nstartxref\n".$start."\n%%EOF\n";return $pdf;
    }
}
function csp_report_pdf(array $report): string {$pdf=new CspReportPdf($report);foreach($report['rows']as $i=>$row)$pdf->row($row,$i);return $pdf->bytes();}
