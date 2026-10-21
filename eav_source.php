<?php
const EAV_BASE = 'https://orariotreni.eavsrl.it/teleindicatori/';
const STATION_CACHE = '/tmp/eav_vesuviane_stations_v3.json';
const STATION_CACHE_TTL = 21600;

const VESUVIANE_NAMES = [
    'Stabia Scavi',
    'Acerra',
    'Argine Palasport',
    'Avella',
    'Baiano',
    'Barra',
    'Bartolo Longo',
    'Boscoreale',
    'Boscotrecase',
    'Botteghelle',
    'Brusciano',
    'Camposano',
    'Casalnuovo',
    'Casilli',
    'Castelcisterna',
    'Castellammare di Stabia',
    'Cercola',
    'Cicciano',
    'Cimitile',
    'De Ruggiero',
    'Ercolano Miglio d\'Oro',
    'Ercolano Scavi Vesuvio',
    'Flocco',
    'Guindazzi',
    'La Pigna',
    'Leopardi',
    'Madonna dell\'Arco',
    'Madonnelle',
    'Marigliano',
    'Meta',
    'Moregine',
    'Napoli Centro Direzionale',
    'Napoli P. Garibaldi',
    'Napoli Porta Nolana',
    'Nola',
    'Ottaviano',
    'Parco Piemonte',
    'Piano',
    'Pioppaino',
    'Poggiomarino',
    'Poggioreale',
    'Pollena Trocchia',
    'Pomigliano d\'Arco',
    'Pompei Santuario',
    'Pompei Scavi Villa Misteri',
    'Ponticelli',
    'Portici Bellavista',
    'Portici Via Libertà',
    'Pozzano',
    'Pratola Ponte',
    'Rione Trieste',
    'Roccarainola',
    'S. Agnello',
    'S. Anastasia',
    'S. Giorgio a Cremano',
    'S. Giorgio Cavalli di Bronzo',
    'S. Giovanni a Teduccio',
    'S. Giuseppe',
    'S. Leonardo',
    'S. Maria del Pozzo',
    'S. Pietro',
    'S. Valentino Torio',
    'S. Vitaliano',
    'Salice',
    'Sarno',
    'Saviano',
    'Scafati',
    'Scisciano',
    'Scrajo',
    'Seiano',
    'Somma Vesuviana',
    'Sorrento',
    'Striano',
    'Talona',
    'Terzigno',
    'Torre Annunziata',
    'Torre del Greco',
    'Trecase',
    'Vesuvio De Meis (SGV)',
    'Vesuvio De Meis (SA)',
    'Via Cangiani',
    'Via dei Monaci',
    'Via del Monte',
    'Via Gianturco',
    'Via S. Antonio',
    'Via Vittorio Veneto',
    'Via Viuli',
    'Vico Equense',
    'Villa Augustea',
    'Villa delle Ginestre',
    'Villa Regina',
    'Villa Visconti',
    'San Giovanni a Teduccio',
    'San Giorgio a Cremano',
    'San Giuseppe'
];

function http_request($url, array $options = []) {
    $method = strtoupper($options['method'] ?? 'GET');
    $timeout = (int)($options['timeout'] ?? 25);
    $headers = $options['headers'] ?? [];
    $body = $options['body'] ?? null;
    $follow = !empty($options['follow_redirects']);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => $options['user_agent'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/152 Safari/537.36',
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        if ($headers) $curlOptions[CURLOPT_HTTPHEADER] = $headers;
        if ($body !== null) $curlOptions[CURLOPT_POSTFIELDS] = $body;
        curl_setopt_array($ch, $curlOptions);
        $response = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            throw new RuntimeException('HTTP request fallita' . ($err ? ': ' . $err : ''));
        }
        return ['body' => $response, 'status' => $code];
    }

    // Fallback per hosting dove l'estensione PHP cURL non è installata.
    $headerLines = $headers;
    $headerLines[] = 'Connection: close';
    $contextOptions = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => $follow ? 1 : 0,
            'max_redirects' => 3,
        ],
    ];
    if ($body !== null) $contextOptions['http']['content'] = $body;
    $context = stream_context_create($contextOptions);
    $response = @file_get_contents($url, false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $m)) {
            $status = (int)$m[1];
        }
    }
    if ($response === false) {
        $detail = $status ? ' HTTP ' . $status : '';
        throw new RuntimeException('HTTP request fallita.' . $detail);
    }
    return ['body' => $response, 'status' => $status];
}

function fetch_url($url, $timeout = 25) {
    $result = http_request($url, [
        'method' => 'GET',
        'timeout' => $timeout,
        'follow_redirects' => true,
        'headers' => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: it-IT,it;q=0.9,en-US;q=0.8,en;q=0.7',
        ],
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/17 Safari/605.1.15',
    ]);
    if ($result['status'] < 200 || $result['status'] >= 400) {
        throw new RuntimeException('HTTP ' . $result['status']);
    }
    return $result['body'];
}

function norm($s) {
    $s = html_entity_decode((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = strip_tags($s);
    $s = preg_replace('/\s+/u', ' ', trim($s));
    return mb_strtoupper($s, 'UTF-8');
}

function clean_text($s) {
    $s = html_entity_decode((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', trim(strip_tags($s))));
}

function station_name_from_html($html) {
    // Cerca prima i nomi ufficiali della rete dentro la pagina del teleindicatore.
    $nhtml = norm($html);
    foreach (VESUVIANE_NAMES as $name) {
        if (mb_strpos($nhtml, norm($name)) !== false) return $name;
    }
    // Fallback su intestazioni.
    if (preg_match_all('/<h[1-4][^>]*>(.*?)<\/h[1-4]>/is', $html, $m)) {
        foreach ($m[1] as $h) {
            $c = clean_text($h);
            if ($c && !preg_match('/EAV|PARTENZE|ARRIVI|LISTA TRENI|DEPARTURES|ARRIVALS/i', $c) && mb_strlen($c) < 100) return $c;
        }
    }
    return '';
}


function extract_ajax_config_from_page($html, $stationId, $stationName = '') {
    $dom=new DOMDocument();libxml_use_internal_errors(true);@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);libxml_clear_errors();
    $xp=new DOMXPath($dom);$values=[];
    foreach($xp->query('//input[@name]') as $input) $values[$input->getAttribute('name')]=$input->getAttribute('value');
    $endpoint='ws_getData_moova.php';
    if(str_contains($html,'ws_getData_pis.php')) $endpoint='ws_getData_pis.php';
    $config=['name'=>$stationName?:('Stazione '.$stationId),'device'=>$values['nomeDevice']??'','codLoc'=>$values['codLoc']??'','touchpoint'=>$values['touchpoint']??'','endpoint'=>$endpoint];
    return $config['device']!=='' && $config['codLoc']!=='' ? $config : null;
}

function eav_stations_config() {
    // Parametri verificati / derivati dalla chiamata AJAX EAV fornita.
    // Per le Flegree EAV usa ws_getData_pis.php con:
    // device=M01T<ID>M, codLoc=<ID>, touchpoint vuoto.
    $cfg = [
        '4' => [
            'name' => 'Via Gianturco',
            'device' => 'M01T4M',
            'codLoc' => 'TNPNTS00000000000054',
            'touchpoint' => '',
            'endpoint' => 'ws_getData_moova.php'
        ],
        '33' => [
            'name' => 'Torre del Greco',
            'device' => 'M01T33M',
            'codLoc' => 'TNPNTS00000000000047',
            'touchpoint' => 'TPOINT00000000000002',
            'endpoint' => 'ws_getData_moova.php'
        ],
        '62' => [
            'name' => 'Sorrento',
            'device' => 'M01T62M',
            'codLoc' => 'TNPNTS00000000000080',
            'touchpoint' => 'TPOINT00000000000107',
            'endpoint' => 'ws_getData_moova.php'
        ]
    ];

    // Linee Flegree: configurazione basata sulla POST AJAX reale fornita.
    $flegree = [
        '801'=>'Montesanto','802'=>'Corso V. Emanuele','803'=>'Fuorigrotta','804'=>'Mostra',
        '805'=>'Edenlandia','806'=>'Agnano','808'=>'Bagnoli','810'=>'Dazio','811'=>'Gerolamini',
        '107'=>'Pozzuoli','815'=>'Arco Felice','816'=>'Lucrino','817'=>'Baia','818'=>'Fusaro','819'=>'Torregaveta',
        '820'=>'Piave','821'=>'Soccavo','822'=>'Rione Traiano','823'=>'La Trencia','824'=>'Pianura','825'=>'Pisani',
        '827'=>'Quarto Centro','109'=>'Quarto','828'=>'Quarto Officina','829'=>'Grotta Del Sole','830'=>'Licola',
        '834'=>"Monte Sant'Angelo"
    ];
    foreach ($flegree as $id => $name) {
        $cfg[$id] = [
            'name' => $name,
            'device' => 'M01T' . $id . 'M',
            'codLoc' => $id,
            'touchpoint' => '',
            'endpoint' => 'ws_getData_pis.php'
        ];
    }
    return $cfg;
}

function parse_trains($html) {
    $dom=new DOMDocument();libxml_use_internal_errors(true);@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);libxml_clear_errors();$xp=new DOMXPath($dom);$out=[];$seen=[];
    foreach($xp->query('//tr') as $tr) {
        $cells=[];foreach($xp->query('./td', $tr) as $td) $cells[]=clean_text($td->textContent);
        if(count($cells)<7) continue;
        $field=function($class,$fallback) use($xp,$tr,$cells) {
            $node=$xp->query('.//*[contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")]', $tr)->item(0);
            return $node?clean_text($node->textContent):($cells[$fallback]??'');
        };
        $numberText=$field('numTreno',0);
        if(!preg_match('/^([0-9]{3,6})$/',trim($numberText),$n)) continue;
        $time=$field('orario',5);if(!preg_match('/^[0-9]{1,2}:[0-9]{2}(?::[0-9]{2})?$/',$time)) continue;
        $platform=$field('binario',4);if(in_array($platform,['','.', '-', '—'],true)) $platform='—';
        $rawDelay=$field('ritardo',6);$delay=0;
        if(preg_match('/[+]?[0-9]+/',$rawDelay,$d)) $delay=(int)$d[0];
        $info=$field('informazioni',3);$destination=$field('destinazione',2);
        $key=$n[1].'|'.$time.'|'.$destination;if(isset($seen[$key])) continue;$seen[$key]=true;
        $out[]=['number'=>$n[1],'category'=>$field('categoria',1),'destination'=>$destination,'info'=>$info,'platform'=>$platform,'time'=>$time,'delay'=>$delay,'delayKnown'=>preg_match('/[+]?[0-9]+/',$rawDelay)===1,'estimatedArrival'=>'','color'=>''];
    }
    // Do not sort: the official board starts with live runs and can wrap to next day.
    return $out;
}

function eav_fetch($station, $tipo = "P", $postedStationName = "") {
    $pageUrl=EAV_BASE.'?stazione='.rawurlencode($station).'&tipo='.$tipo;
    $file=rtrim((string)getenv('CSP_DATA_DIR'),'/').'/eav_ajax_config.json';
    $cache=is_file($file)?json_decode((string)file_get_contents($file),true):[];$cached=$cache[$station]??null;
    $st=$cached && ($cached['updated']??0)>time()-300?($cached['config']??null):null;
    if(!$st) {
        try {$page=fetch_url($pageUrl,10);$st=extract_ajax_config_from_page($page,$station,$postedStationName);}
        catch(Throwable $e) {$st=null;}
        if(!$st) $st=eav_stations_config()[$station]??null;
        if(!$st) throw new RuntimeException('Parametri live EAV non disponibili per la stazione '.$station.'.');
        $cache[$station]=['updated'=>time(),'config'=>$st];
        if(is_dir(dirname($file))) file_put_contents($file,json_encode($cache),LOCK_EX);
    }
    $st['name']=$postedStationName?:$st['name'];
    $endpoint = EAV_BASE . ($st['endpoint'] ?? 'ws_getData_moova.php');

    // Replichiamo direttamente la POST AJAX reale di EAV.
    $params = [
        'device' => $st['device'],
        'tipoLista' => $tipo,
        'codLoc' => $st['codLoc'],
        'touchpoint' => $st['touchpoint'],
        'visualizzazione' => 'mobile'
    ];

    $result = http_request($endpoint, [
        'method' => 'POST',
        'body' => http_build_query($params, '', '&'),
        'timeout' => 15,
        'follow_redirects' => false,
        'headers' => [
            'Accept: text/html, */*; q=0.01',
            'Accept-Language: it-IT,it;q=0.9,en-US;q=0.8,en;q=0.7',
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            'Origin: https://orariotreni.eavsrl.it',
            'Referer: ' . $pageUrl,
            'X-Requested-With: XMLHttpRequest',
        ],
    ]);
    $response = $result['body'];
    $httpCode = $result['status'];
    $err = '';

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException('Richiesta AJAX EAV fallita. HTTP ' . $httpCode . ($err ? ' - ' . $err : ''));
    }
    if (trim((string)$response) === '') {
        throw new RuntimeException('EAV ha restituito una risposta vuota.');
    }

    if (stripos($response, '# PARAMETRI NON CORRETTI') !== false) {
        unset($cache[$station]);if(is_dir(dirname($file)))file_put_contents($file,json_encode($cache),LOCK_EX);
        throw new RuntimeException('EAV ha rifiutato i parametri AJAX della stazione.');
    }

    $trains = parse_trains($response);

    $dom=new DOMDocument();libxml_use_internal_errors(true);@$dom->loadHTML('<?xml encoding="UTF-8">'.$response);libxml_clear_errors();$xp=new DOMXPath($dom);$notice=$xp->query('//*[contains(concat(" ",normalize-space(@class)," ")," InfoSupplementare ")]')->item(0);
    return ['station'=>$station, 'stationName'=>$st['name'], 'tipo'=>$tipo, 'trains'=>$trains, 'sourceNotice'=>$notice?clean_text($notice->textContent):''];
}
