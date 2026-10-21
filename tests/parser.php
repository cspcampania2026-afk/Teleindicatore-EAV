<?php
// Tests never inherit the application's production DATABASE_URL.
if(PHP_SAPI==='cli'){
    $testDatabase=(string)getenv('CSP_TEST_DATABASE_URL');
    if($testDatabase!==''&&!in_array(parse_url($testDatabase,PHP_URL_HOST),['localhost','127.0.0.1'],true))throw new RuntimeException('I test PostgreSQL richiedono un database locale isolato.');
    putenv('DATABASE_URL='.$testDatabase);
}
if(PHP_SAPI!=='cli') {http_response_code(404);exit;}
require __DIR__.'/../eav_source.php';
function parser_check($ok,$label){if(!$ok)throw new RuntimeException('FAIL: '.$label);echo 'PASS: '.$label.PHP_EOL;}
$trains=parse_trains(file_get_contents(__DIR__.'/eav-1.html'));
parser_check(count($trains)===3 && $trains[0]['number']==='6177' && $trains[0]['platform']==='9','corsa e binario dalle celle ufficiali EAV');
parser_check($trains[0]['time']==='17:46' && $trains[2]['time']==='07:45','ordine live EAV conservato, senza riordino dalla mezzanotte');
$sg=parse_trains(file_get_contents(__DIR__.'/eav-27.html'));
parser_check($sg[0]['number']==='11737' && $sg[0]['delay']===2,'ritardo letto dalla cella ufficiale EAV');
$cfg=extract_ajax_config_from_page(file_get_contents(__DIR__.'/fixtures-page.html'),'801','Montesanto');
parser_check($cfg['codLoc']==='801' && $cfg['touchpoint']==='' && $cfg['endpoint']==='ws_getData_pis.php','parametri live Flegree con touchpoint vuoto');
