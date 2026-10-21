<?php
// Tests never inherit the application's production DATABASE_URL.
if(PHP_SAPI==='cli'){
    $testDatabase=(string)getenv('CSP_TEST_DATABASE_URL');
    if($testDatabase!==''&&!in_array(parse_url($testDatabase,PHP_URL_HOST),['localhost','127.0.0.1'],true))throw new RuntimeException('I test PostgreSQL richiedono un database locale isolato.');
    putenv('DATABASE_URL='.$testDatabase);
}
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../operations.php';
foreach(['2026-10-16'=>'corse_linee.json','2026-10-17'=>'corse_linee_20261017.json'] as $day=>$file){
 $expected=json_decode(file_get_contents(__DIR__.'/../'.$file),true);$actual=csp_train_lines($day);
 foreach($expected as $number=>$line)if(($actual[$number]??null)!==$line)throw new RuntimeException('Catalogo incoerente: '.$day.' '.$number);
}
foreach(csp_train_lines() as $number=>$line){$n=(string)$number;$expected=preg_match('/^1[0-9]{3}$/',$n)?'L1':(preg_match('/^1[0-9]{4}$/',$n)?'TORRE':(['4'=>'L4','6'=>'L6','8'=>'L8'][$n[0]]??$line));if(csp_known_line(['number'=>$n,'destination'=>'NAPOLI PORTA NOLANA'])!==$expected)throw new RuntimeException('Regola ritorno non riconosciuta: '.$n);}
echo 'PASS: catalogo server e priorità delle regole di numerazione verso Napoli verificati.'.PHP_EOL;
