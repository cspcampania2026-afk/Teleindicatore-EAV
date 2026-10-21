<?php
declare(strict_types=1);

function csp_audit(string $actor,string $action,array $detail): void {
    csp_sql('INSERT INTO audit(stamp,actor,action,detail) VALUES (?,?,?,?)',[time(),$actor,$action,csp_json($detail)]);
}
function csp_credentials_valid(string $user,string $password): bool {
    $r=csp_sql('SELECT * FROM users WHERE LOWER(username)=LOWER(?)',[trim($user)])->fetch(PDO::FETCH_ASSOC);
    if($r) return (bool)$r['active'] && password_verify($password,$r['password_hash']);
    return !(int)csp_sql('SELECT COUNT(*) FROM users')->fetchColumn() && csp_legacy_credentials_valid(trim($user),$password);
}
function csp_authenticate(string $user,string $password): ?array {
    // The environment credentials bootstrap exactly one account, only on an empty database.
    $r=csp_sql('SELECT * FROM users WHERE LOWER(username)=LOWER(?)',[$user])->fetch(PDO::FETCH_ASSOC);
    if($r) return $r['active'] && password_verify($password,$r['password_hash']) ? $r : null;
    if((int)csp_sql('SELECT COUNT(*) FROM users')->fetchColumn() || !csp_legacy_credentials_valid($user,$password)) return null;
    return csp_tx(function()use($user,$password){
        if((int)csp_sql('SELECT COUNT(*) FROM users')->fetchColumn()) return null;
        $name=(string)(getenv('CSP_ADMIN_USER')?:'admin');
        csp_sql('INSERT INTO users(username,password_hash,role,created) VALUES (?,?,?,?)',[$name,password_hash($password,PASSWORD_DEFAULT),'admin',time()]);
        csp_audit($name,'account_bootstrap',['username'=>$name]);
        return csp_sql('SELECT * FROM users WHERE LOWER(username)=LOWER(?)',[$name])->fetch(PDO::FETCH_ASSOC);
    });
}
function csp_refresh_identity(): void {
    $r=csp_sql('SELECT * FROM users WHERE id=?',[(int)($_SESSION['user_id']??0)])->fetch(PDO::FETCH_ASSOC);
    if(!$r || !$r['active'] || (int)$r['revision']!==(int)($_SESSION['revision']??0)) {
        $_SESSION=[];session_regenerate_id(true);return;
    }
    $_SESSION['admin']=$r['username'];$_SESSION['role']=$r['role'];$_SESSION['must_change']=(bool)$r['must_change'];
}
function csp_manager_required(): void {
    if(($_SESSION['role']??'')!=='admin') throw new RuntimeException('Funzione riservata agli amministratori.',403);
}
function csp_authorize_action(string $action): void {
    if(!empty($_SESSION['must_change'])) throw new RuntimeException('Cambia prima la password in Il mio account.',403);
    if(in_array($action,['queue_retry','queue_cancel','telegram_check','telegram_chats'],true)) csp_manager_required();
}
function csp_validate_password(string $password): void {
    if(strlen($password)<12 || strlen($password)>72) throw new InvalidArgumentException('La password deve contenere da 12 a 72 byte (con caratteri semplici: da 12 a 72 caratteri).');
}
function csp_users(): array {
    csp_manager_required();
    return csp_sql('SELECT id,username,role,active,must_change,created FROM users ORDER BY username')->fetchAll(PDO::FETCH_ASSOC);
}
function csp_create_user(array $input,string $actor): void {
    csp_manager_required();$name=trim((string)($input['username']??''));$role=(string)($input['role']??'operator');$password=(string)($input['password']??'');
    if(!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{2,63}$/D',$name)) throw new InvalidArgumentException('Nome utente: 3–64 caratteri, lettere, numeri, punto, trattino o underscore.');
    if(!in_array($role,['admin','operator'],true)) throw new InvalidArgumentException('Ruolo non valido.');
    csp_validate_password($password);$hash=password_hash($password,PASSWORD_DEFAULT);
    csp_tx(function()use($name,$role,$hash,$actor){
        if(csp_sql('SELECT id FROM users WHERE LOWER(username)=LOWER(?)',[$name])->fetchColumn())throw new InvalidArgumentException('Nome utente già presente.');
        csp_sql('INSERT INTO users(username,password_hash,role,must_change,created) VALUES (?,?,?,1,?)',[$name,$hash,$role,time()]);
        csp_audit($actor,'user_create',['username'=>$name,'role'=>$role]);
    });
}
function csp_update_user(array $input,string $actor): void {
    csp_manager_required();$id=(int)($input['id']??0);$role=(string)($input['role']??'');$active=($input['active']??'0')==='1'?1:0;
    if(!in_array($role,['admin','operator'],true))throw new InvalidArgumentException('Ruolo non valido.');
    csp_tx(function()use($id,$role,$active,$actor){
        $r=csp_sql('SELECT * FROM users WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
        if(!$r)throw new InvalidArgumentException('Utente non trovato.');
        if($id===(int)$_SESSION['user_id'] && (!$active||$role!=='admin'))throw new InvalidArgumentException('Non puoi disabilitare o declassare il tuo account.');
        if($r['active'] && $r['role']==='admin' && (!$active||$role!=='admin') && (int)csp_sql('SELECT COUNT(*) FROM users WHERE active=1 AND role=\'admin\'')->fetchColumn()<=1)throw new InvalidArgumentException('Deve restare almeno un amministratore attivo.');
        if((int)$r['active']===$active && $r['role']===$role)return;
        csp_sql('UPDATE users SET role=?,active=?,revision=revision+1 WHERE id=?',[$role,$active,$id]);
        csp_audit($actor,'user_update',['username'=>$r['username'],'role'=>$role,'active'=>$active]);
    });
}
function csp_reset_password(int $id,string $password,string $actor): void {
    csp_manager_required();csp_validate_password($password);
    if($id===(int)$_SESSION['user_id'])throw new InvalidArgumentException('Per la tua password usa Il mio account.');
    $hash=password_hash($password,PASSWORD_DEFAULT);
    csp_tx(function()use($id,$hash,$actor){
        $r=csp_sql('SELECT username FROM users WHERE id=?',[$id])->fetchColumn();if(!$r)throw new InvalidArgumentException('Utente non trovato.');
        csp_sql('UPDATE users SET password_hash=?,must_change=1,revision=revision+1 WHERE id=?',[$hash,$id]);
        csp_audit($actor,'user_password_reset',['username'=>$r]);
    });
}
function csp_change_password(string $current,string $password): void {
    csp_validate_password($password);
    csp_tx(function()use($current,$password){
        $r=csp_sql('SELECT * FROM users WHERE id=?',[(int)$_SESSION['user_id']])->fetch(PDO::FETCH_ASSOC);
        if(!$r||!$r['active']||(int)$r['revision']!==(int)$_SESSION['revision']||!password_verify($current,$r['password_hash']))throw new InvalidArgumentException('Password attuale non valida o sessione revocata.');
        if(password_verify($password,$r['password_hash']))throw new InvalidArgumentException('Scegli una password diversa da quella attuale.');
        csp_sql('UPDATE users SET password_hash=?,must_change=0,revision=revision+1 WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$r['id']]);
        csp_audit($r['username'],'password_change',[]);
        $_SESSION['revision']=(int)$r['revision']+1;$_SESSION['must_change']=false;
    });
    session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));
}
