<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../database.php';
function db_check($ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL: '.$label);echo 'PASS: '.$label.PHP_EOL;}
[$dsn,$user,$password]=csp_postgres_config('postgresql://name:p%40ss%3Bword@ep-test-pooler.example.com/neondb?sslmode=require&channel_binding=require');
db_check($user==='name'&&$password==='p@ss;word'&&str_contains($dsn,'sslmode=require')&&str_contains($dsn,'channel_binding=require'),'URL PostgreSQL e password codificata interpretate senza includere segreti nel DSN');
foreach(['mysql://u:p@example.com/db','postgresql://u:p@example.com/db?sslmode=disable','postgresql://u:p@example.com/db?sslmode=prefer','postgresql://u:p@example.com/db%3Bsslmode%3Ddisable','postgresql://u:p@example.com/db?sslmode[]=require'] as $url){
    $rejected=false;try{csp_postgres_config($url);}catch(RuntimeException $e){$rejected=true;}db_check($rejected,'configurazione non valida o TLS non obbligatorio rifiutati');
}
$dir=sys_get_temp_dir().'/csp-db-failure-'.bin2hex(random_bytes(6));putenv('CSP_DATA_DIR='.$dir);putenv('DATABASE_URL=postgresql://fake:PrivateTestPassword@127.0.0.1:1/postgres?sslmode=disable');
try{
    $failed=false;try{csp_db();}catch(RuntimeException $e){$failed=true;db_check(!str_contains($e->getMessage(),'PrivateTestPassword')&&!str_contains($e->getMessage(),'127.0.0.1'),'errore connessione senza segreti o indirizzo del database');}
    db_check($failed&&!file_exists($dir.'/csp.sqlite'),'errore PostgreSQL non crea né usa un database SQLite alternativo');
}finally{foreach(glob($dir.'/*')?:[]as $f)unlink($f);if(is_dir($dir))rmdir($dir);}

foreach([
 ['password authentication failed for user SECRET_USER','connection','Autenticazione'],
 ['could not translate host name SECRET_HOST','connection','non risolvibile'],
 ['SSL certificate verify failed SECRET_HOST','connection','Certificato TLS'],
 ['permission denied for schema SECRET_SCHEMA','schema','Permessi'],
 ['syntax error at SECRET_SQL','schema','tabelle'],
 ['connection refused SECRET_HOST','connection','non raggiungibile'],
 ['endpoint is disabled SECRET_ENDPOINT','connection','quota'],
] as [$raw,$stage,$expected]){
 $e=new PDOException($raw);$reason=csp_database_failure($e,$stage);
 db_check(str_contains($reason,$expected)&&!str_contains($reason,'SECRET_'),'diagnostica sicura: '.$expected);
}

$e=new PDOException('connection to server at "secret.example.com" (10.1.2.3), port 5432 failed: SSL connection failed: FATAL: Tenant or user not found');
db_check(str_contains(csp_database_failure($e,'connection'),'Utente del pooler'),'errore tenant non confuso con TLS');
$detail=csp_database_driver_detail(new PDOException('server secret.example.com user secretuser password P@ssword query "private value" IP 10.1.2.3 SSL error: certificate verify failed'),'postgresql://secretuser:P%40ssword@secret.example.com/postgres?sslmode=require','connection');
db_check(!str_contains($detail,'secret.example.com')&&!str_contains($detail,'secretuser')&&!str_contains($detail,'P@ssword')&&!str_contains($detail,'private value')&&!str_contains($detail,'10.1.2.3')&&str_contains($detail,'certificate verify failed'),'dettaglio driver utile senza credenziali o indirizzi');

$sslDir=sys_get_temp_dir().'/csp-ssl-'.bin2hex(random_bytes(6));mkdir($sslDir,0700);
$vars=['PGSSLCERT','PGSSLKEY','PGSSLROOTCERT','PGSSLCRL'];$saved=[];foreach($vars as $v){$saved[$v]=getenv($v);putenv($v);}
try{
 csp_postgres_ssl_files($sslDir);
 db_check(getenv('PGSSLCERT')===$sslDir.'/.postgresql/postgresql.crt'&&getenv('PGSSLROOTCERT')===$sslDir.'/.postgresql/root.crt','certificati opzionali cercati nella cartella dati accessibile, senza usare la home root');
 putenv('PGSSLCERT=/custom/client.crt');csp_postgres_ssl_files($sslDir);db_check(getenv('PGSSLCERT')==='/custom/client.crt','certificati personalizzati espliciti conservati');
 db_check(str_contains(csp_database_failure(new PDOException('could not open certificate file SECRET_PATH: Permission denied'),'connection'),'processo'),'permessi file certificato distinti dai privilegi sulle tabelle');
}finally{foreach($saved as $v=>$value)putenv($value===false?$v:$v.'='.$value);rmdir($sslDir);}
