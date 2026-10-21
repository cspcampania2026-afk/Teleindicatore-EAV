<?php
require_once __DIR__.'/csp_core.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
try {
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST') throw new RuntimeException('Metodo non consentito.',405);
    [$station,$tipo,$name]=csp_context($_POST);$payload=csp_apply(csp_source($station,$tipo,$name));
    foreach($payload['trains'] as &$train) unset($train['operatorNote']);unset($train);
    unset($payload['removed']); // Only the protected admin API can retrieve hidden runs.
    echo csp_json($payload);
} catch(Throwable $e) {http_response_code(in_array($e->getCode(),[405],true)?$e->getCode():($e instanceof InvalidArgumentException?400:502));echo csp_json(['error'=>$e->getMessage()]);}
