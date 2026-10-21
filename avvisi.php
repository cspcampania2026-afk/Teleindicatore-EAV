<?php
require_once __DIR__.'/csp_core.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
try {[$station,$tipo,$name]=csp_context($_GET);echo csp_json(['notices'=>csp_public_notices($station),'updated'=>date(DATE_ATOM)]);}
catch(Throwable $e){http_response_code($e instanceof InvalidArgumentException?400:503);echo csp_json(['error'=>'Avvisi temporaneamente non disponibili.']);}
