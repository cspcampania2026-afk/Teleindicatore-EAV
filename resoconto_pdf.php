<?php
require_once __DIR__.'/csp_core.php';
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');
try{
    csp_require_admin();csp_authorize_action('report_pdf');session_write_close();
    $station=(string)($_GET['station']??'');$day=(string)($_GET['day']??date('Y-m-d'));
    $report=csp_daily_report($station,$day);require_once __DIR__.'/report_pdf.php';$pdf=csp_report_pdf($report);
    header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="resoconto-'.$station.'-'.$day.'.pdf"');header('Content-Length: '.strlen($pdf));echo $pdf;
}catch(Throwable $e){http_response_code(in_array($e->getCode(),[401,403],true)?$e->getCode():($e instanceof InvalidArgumentException?400:503));header('Content-Type: application/json; charset=UTF-8');echo csp_json(['error'=>$e instanceof PDOException?'Resoconto temporaneamente non disponibile.':$e->getMessage()]);}
