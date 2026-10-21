<?php
function eav_fetch($station,$tipo,$name) {return ['station'=>$station,'stationName'=>$name,'tipo'=>$tipo,'trains'=>$GLOBALS['fixture'][$station.'|'.$tipo]??[]];}
function http_request($url,array $options=[]) {if(isset($GLOBALS['telegramMock'])){$GLOBALS['mockUrl']=$url;return $GLOBALS['telegramMock'];}throw new RuntimeException('Rete disabilitata nei test.');}
