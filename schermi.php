<?php
header('Cache-Control: no-store');
$html = file_get_contents(__DIR__.'/index.html');
echo str_replace(['data-circuit="public"','← Indietro'], ['data-circuit="station"','Cambia stazione'], $html);
