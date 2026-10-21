<?php
declare(strict_types=1);
// Connection strings must never appear as arguments in exception traces.
ini_set('zend.exception_ignore_args','1');

function csp_data_directory(): string {
    $dir=rtrim((string)getenv('CSP_DATA_DIR'),'/');
    if(!$dir||$dir[0]!=='/')throw new RuntimeException('Impostare CSP_DATA_DIR con un percorso assoluto esterno alla cartella web.');
    if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Impossibile creare la cartella dati.');
    $real=realpath($dir);$web=realpath($_SERVER['DOCUMENT_ROOT']??__DIR__)?:__DIR__;
    if($real===$web||str_starts_with($real,$web.'/')||$real===__DIR__||str_starts_with($real,__DIR__.'/'))throw new RuntimeException('CSP_DATA_DIR deve essere esterno alla cartella web.');
    return $dir;
}
function csp_postgres_config(string $url): array {
    $p=parse_url($url);
    if(!$p||!in_array($p['scheme']??'',['postgres','postgresql'],true))throw new RuntimeException('DATABASE_URL deve essere un collegamento PostgreSQL valido.');
    $host=$p['host']??'';$port=(int)($p['port']??5432);$name=rawurldecode(ltrim($p['path']??'','/'));$user=rawurldecode($p['user']??'');$password=rawurldecode($p['pass']??'');
    if(!preg_match('/^[a-zA-Z0-9.:-]+$/D',$host)||$port<1||$port>65535||!preg_match('/^[a-zA-Z0-9_-]+$/D',$name)||$user===''||$password==='')throw new RuntimeException('DATABASE_URL incompleta o non valida. Copia la connection string dal database.');
    parse_str($p['query']??'',$q);$ssl=$q['sslmode']??'require';
    $local=in_array($host,['localhost','127.0.0.1','::1'],true);
    if(!is_string($ssl)||!in_array($ssl,['require','verify-ca','verify-full'],true)&&!($local&&$ssl==='disable'))throw new RuntimeException('Il database esterno deve usare TLS (sslmode=require).');
    $dsn='pgsql:host='.$host.';port='.$port.';dbname='.$name.';sslmode='.$ssl.';connect_timeout=15';
    if(in_array($ssl,['verify-ca','verify-full'],true))$dsn.=';sslrootcert=/etc/ssl/certs/ca-certificates.crt';
    if(isset($q['channel_binding'])){
        if(!is_string($q['channel_binding'])||!in_array($q['channel_binding'],['require','prefer'],true))throw new RuntimeException('Parametro channel_binding non valido.');
        $dsn.=';channel_binding='.$q['channel_binding'];
    }
    return [$dsn,$user,$password];
}
function csp_database_kind(): string {return trim((string)getenv('DATABASE_URL'))!==''?'pgsql':'sqlite';}
function csp_database_failure(PDOException $error,string $stage): string {
    // Inspect the driver text locally; return only fixed, credential-free diagnostics.
    $message=strtolower($error->getMessage());
    if(str_contains($message,'password authentication failed')||str_contains($message,'authentication failed')||str_contains($message,'no password supplied'))return 'Autenticazione PostgreSQL fallita: ricopiare utente e password dalla finestra Connect del database.';
    if(str_contains($message,'could not translate host name')||str_contains($message,'name or service not known')||str_contains($message,'name resolution'))return 'Nome server PostgreSQL non risolvibile: controllare il server nel collegamento copiato dal fornitore.';
    if(str_contains($message,'tenant or user not found'))return 'Utente del pooler Supabase non riconosciuto: ricopiare lo username completo postgres.CODICE_PROGETTO e il server dalla stessa finestra Session pooler.';
    if(str_contains($message,'certificate verify failed')||str_contains($message,'self-signed certificate'))return 'Certificato TLS PostgreSQL non verificabile: controllare il certificato CA configurato.';
    if(str_contains($message,'root certificate file')&&str_contains($message,'does not exist'))return 'File del certificato CA PostgreSQL assente nel server.';
    if(str_contains($message,'ssl syscall error')||str_contains($message,'ssl connection has been closed'))return 'Connessione PostgreSQL chiusa durante la comunicazione TLS: controllare disponibilità e limiti del pooler.';
    if(str_contains($message,'channel_binding')||str_contains($message,'channel binding'))return 'Negoziazione channel_binding non riuscita: verificare compatibilità del client e impostazioni TLS.';
    if(str_contains($message,'ssl error')||str_contains($message,'ssl is not enabled')||str_contains($message,'does not support ssl')||str_contains($message,'ssl certificate')||str_contains($message,'tls handshake'))return 'Connessione TLS PostgreSQL non riuscita: verificare certificati e parametri SSL.';
    if(str_contains($message,'does not exist')&&str_contains($message,'database'))return 'Database richiesto inesistente: ricopiare il nome database dalla finestra Connect del database.';
    if(str_contains($message,'permission denied')&&(str_contains($message,'certificate file')||str_contains($message,'private key file')))return 'File certificato PostgreSQL non accessibile al processo: verificare percorsi PGSSL e permessi locali.';
    if(str_contains($message,'permission denied')||str_contains($message,'insufficient privilege'))return 'Permessi PostgreSQL insufficienti: utilizzare il ruolo proprietario del database.';
    if(str_contains($message,'quota')||str_contains($message,'compute time')||str_contains($message,'endpoint is disabled')||str_contains($message,'endpoint has been disabled'))return 'Risorse database sospese o quota esaurita: controllare lo stato del progetto e Usage sul fornitore.';
    if(str_contains($message,'timeout')||str_contains($message,'timed out')||str_contains($message,'connection refused')||str_contains($message,'network is unreachable')||str_contains($message,'connection to server'))return 'Server PostgreSQL non raggiungibile: controllare stato del database, rete e limitazioni di accesso.';
    $state=(string)($error->errorInfo[0]??$error->getCode());
    $code=preg_match('/^[A-Z0-9]{5}$/D',$state)?' SQLSTATE '.$state.'.':'';
    return ($stage==='schema'?'Creazione o aggiornamento delle tabelle PostgreSQL non riuscito.':'Apertura o configurazione della connessione PostgreSQL non riuscita.').$code;
}
function csp_database_driver_detail(PDOException $error,string $url,string $stage): string {
    // Server logs only. Strip URL, all known credential variants, quoted values and addresses.
    $text=$error->getMessage();$parts=parse_url($url)?:[];$secrets=[$url];
    foreach(['user','pass','host','path'] as $key)if(!empty($parts[$key])){
        $value=(string)$parts[$key];if($key==='path')$value=ltrim($value,'/');
        if($value!=='')foreach(array_unique([$value,rawurldecode($value),rawurlencode(rawurldecode($value)),urlencode(rawurldecode($value))]) as $variant)if($variant!=='')$secrets[]=$variant;
    }
    usort($secrets,fn($a,$b)=>strlen($b)<=>strlen($a));
    $text=str_replace($secrets,'[redatto]',$text);
    $text=preg_replace('~postgres(?:ql)?://[^\s]+~i','[URL redatto]',$text);
    $text=preg_replace('~[\x22\x27][^\x22\x27]*[\x22\x27]~','[valore redatto]',$text);
    $text=preg_replace('~\b(?:[0-9]{1,3}\.){3}[0-9]{1,3}\b~','[IP redatto]',$text);
    $text=preg_replace('/[\r\n\t]+/',' ',$text);
    return '[CSP DB diagnostica 2; fase '.$stage.'] '.substr($text,0,1800);
}
function csp_postgres_ssl_files(string $dir): void {
    // libpq otherwise probes the inherited root home after Supervisor drops privileges.
    // Missing optional client certificates are allowed; inaccessible root paths are not.
    $sslDir=$dir.'/.postgresql';
    foreach(['PGSSLCERT'=>'postgresql.crt','PGSSLKEY'=>'postgresql.key','PGSSLROOTCERT'=>'root.crt','PGSSLCRL'=>'root.crl'] as $name=>$file){
        if(getenv($name)===false||getenv($name)==='')putenv($name.'='.$sslDir.'/'.$file);
    }
}
function csp_db(): PDO {
    static $db;if($db)return $db;
    $dir=csp_data_directory();$url=trim((string)getenv('DATABASE_URL'));
    $stage='connection';
    try{
        if($url!==''){
            if(!extension_loaded('pdo_pgsql'))throw new RuntimeException('Driver PostgreSQL assente: pubblicare il Dockerfile aggiornato.');
            [$dsn,$user,$password]=csp_postgres_config($url);
            csp_postgres_ssl_files($dir);
            $connection=new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::PGSQL_ATTR_DISABLE_PREPARES=>true]);
            $connection->exec("SET statement_timeout = '20s'; SET lock_timeout = '15s'");
            $stage='schema';csp_postgres_schema($connection);
        }else{
            $connection=new PDO('sqlite:'.$dir.'/csp.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $connection->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=10000;');
            $connection->exec(csp_schema());
            $columns=$connection->query('PRAGMA table_info(outbox)')->fetchAll(PDO::FETCH_COLUMN,1);
            if(!in_array('parse_mode',$columns,true)){
                $connection->exec('BEGIN IMMEDIATE');
                try{
                    $columns=$connection->query('PRAGMA table_info(outbox)')->fetchAll(PDO::FETCH_COLUMN,1);
                    if(!in_array('parse_mode',$columns,true))$connection->exec("ALTER TABLE outbox ADD COLUMN parse_mode TEXT DEFAULT ''");
                    $connection->exec('COMMIT');
                }catch(Throwable $e){$connection->exec('ROLLBACK');throw $e;}
            }
        }
        $db=$connection;return $db;
    }catch(PDOException $e){
        // Keep driver details out of public responses; server logs receive a redacted diagnostic.
        if($url!=='')error_log(csp_database_driver_detail($e,$url,$stage));
        throw new RuntimeException($url!==''?'Database esterno non disponibile. '.csp_database_failure($e,$stage).' Nessun passaggio automatico al database locale.':'Database locale non disponibile. Verificare CSP_DATA_DIR.');
    }
}
function csp_postgres_schema(PDO $db): void {
    if($db->query("SELECT to_regclass('public.csp_schema_version')")->fetchColumn()){
        if((int)$db->query('SELECT version FROM csp_schema_version WHERE id=1')->fetchColumn()>=3)return;
    }
    $db->beginTransaction();
    try{
        $db->query('SELECT pg_advisory_xact_lock(84301000)');
        $schema=str_replace(['INTEGER PRIMARY KEY AUTOINCREMENT',' COLLATE NOCASE'],['BIGSERIAL PRIMARY KEY',''],csp_schema());
        $db->exec($schema);
        $db->exec("ALTER TABLE outbox ADD COLUMN IF NOT EXISTS parse_mode TEXT DEFAULT ''");
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS csp_username_lower ON users (lower(username))');
        $db->exec('CREATE TABLE IF NOT EXISTS csp_schema_version (id INTEGER PRIMARY KEY, version INTEGER NOT NULL)');
        $db->exec('INSERT INTO csp_schema_version VALUES (1,3) ON CONFLICT(id) DO UPDATE SET version=excluded.version');
        $db->commit();
    }catch(Throwable $e){$db->rollBack();throw $e;}
}
function csp_sql(string $sql,array $args=[]): PDOStatement {
    $s=csp_db()->prepare($sql);$s->execute($args);return $s;
}
function csp_tx(callable $f) {
    $db=csp_db();$pg=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql';
    $db->exec($pg?'BEGIN':'BEGIN IMMEDIATE');
    try{
        // Transaction-scoped lock also works through transaction-pooling services.
        if($pg)$db->query('SELECT pg_advisory_xact_lock(84301001)');
        $r=$f();$db->exec('COMMIT');return $r;
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function csp_schema(): string {
    return <<<'SQL'
CREATE TABLE IF NOT EXISTS overrides (id TEXT PRIMARY KEY, day TEXT, station TEXT, tipo TEXT, action TEXT, train TEXT, actor TEXT, updated INTEGER);
      CREATE TABLE IF NOT EXISTS cache (id TEXT PRIMARY KEY, payload TEXT, updated INTEGER);
      CREATE TABLE IF NOT EXISTS snapshots (id TEXT PRIMARY KEY, payload TEXT, day TEXT);
      CREATE TABLE IF NOT EXISTS audit (id INTEGER PRIMARY KEY AUTOINCREMENT, stamp INTEGER, actor TEXT, action TEXT, detail TEXT);
      CREATE TABLE IF NOT EXISTS outbox (id INTEGER PRIMARY KEY AUTOINCREMENT, event_key TEXT, target TEXT, message TEXT, status TEXT DEFAULT 'pending', attempts INTEGER DEFAULT 0, next_try INTEGER DEFAULT 0, last_error TEXT, created INTEGER, UNIQUE(event_key,target));
      CREATE TABLE IF NOT EXISTS health (id TEXT PRIMARY KEY, payload TEXT);
      CREATE TABLE IF NOT EXISTS attempts (ip TEXT PRIMARY KEY, count INTEGER, stamp INTEGER);
      CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL COLLATE NOCASE UNIQUE, password_hash TEXT NOT NULL, role TEXT NOT NULL CHECK(role IN ('admin','operator')), active INTEGER NOT NULL DEFAULT 1, must_change INTEGER NOT NULL DEFAULT 0, revision INTEGER NOT NULL DEFAULT 1, created INTEGER NOT NULL);
      CREATE TABLE IF NOT EXISTS route_rules (id TEXT PRIMARY KEY, day TEXT, number TEXT, line TEXT, scope TEXT, stations TEXT, label TEXT, actor TEXT, updated INTEGER);
      CREATE TABLE IF NOT EXISTS operations_rows (id TEXT PRIMARY KEY, day TEXT, station TEXT, number TEXT, payload TEXT, updated INTEGER);
      CREATE TABLE IF NOT EXISTS operations_events (id TEXT PRIMARY KEY, day TEXT, refs TEXT, payload TEXT, actor TEXT, updated INTEGER);
      CREATE TABLE IF NOT EXISTS delay_destinations (id TEXT PRIMARY KEY, day TEXT, terminal TEXT);
      CREATE TABLE IF NOT EXISTS operations_recovery (id TEXT PRIMARY KEY, day TEXT, station TEXT, payload TEXT);
      CREATE TABLE IF NOT EXISTS operations_history (id TEXT PRIMARY KEY, day TEXT, station TEXT, payload TEXT, status TEXT, archived INTEGER);
      CREATE TABLE IF NOT EXISTS notices (id TEXT PRIMARY KEY, kind TEXT, text TEXT, station TEXT, created INTEGER, expires INTEGER, active INTEGER, actor TEXT);
      CREATE TABLE IF NOT EXISTS history_edits (id TEXT PRIMARY KEY, day TEXT, station TEXT, payload TEXT, actor TEXT, updated INTEGER);
      CREATE TABLE IF NOT EXISTS history_watch (station TEXT PRIMARY KEY, updated INTEGER);
      CREATE TABLE IF NOT EXISTS history_coverage (id TEXT PRIMARY KEY, day TEXT, station TEXT, first_seen INTEGER, last_seen INTEGER);
SQL;
}
