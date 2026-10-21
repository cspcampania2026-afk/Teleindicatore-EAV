<?php
require_once __DIR__.'/csp_core.php';
header('Cache-Control: no-store, no-cache, must-revalidate');header('Pragma: no-cache');header('X-Frame-Options: DENY');header('X-Content-Type-Options: nosniff');
csp_session();if(empty($_SESSION['admin'])){header('Location: admin.php');exit;}
$error='';$message=$_SESSION['account_message']??'';unset($_SESSION['account_message']);
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
    try{
        csp_csrf();$action=(string)($_POST['action']??'');$actor=$_SESSION['admin'];
        if($action==='password'){
            if((string)($_POST['password']??'')!==(string)($_POST['confirm']??''))throw new InvalidArgumentException('Le nuove password non coincidono.');
            csp_change_password((string)($_POST['current']??''),(string)($_POST['password']??''));$message='Password cambiata. Le altre sessioni del tuo account sono state revocate.';
        }else{
            csp_authorize_action('users');csp_manager_required();
            if($action==='create'){csp_create_user($_POST,$actor);$message='Account creato. Comunica privatamente le credenziali temporanee: la password dovrà essere cambiata al primo accesso.';}
            elseif($action==='update'){csp_update_user($_POST,$actor);$message='Utente aggiornato. Se hai modificato ruolo o accesso, le sue sessioni sono state revocate.';}
            elseif($action==='reset'){csp_reset_password((int)($_POST['id']??0),(string)($_POST['password']??''),$actor);$message='Password temporanea impostata. Sessioni revocate: l’utente dovrà cambiarla al prossimo accesso.';}
            else throw new InvalidArgumentException('Comando non valido.');
        }
        $_SESSION['account_message']=$message;header('Location: utenti.php');exit;
    }catch(Throwable $e){$error=$e instanceof PDOException?'Operazione non riuscita. Riprova.':$e->getMessage();}
}
$manager=$_SESSION['role']==='admin';$forced=!empty($_SESSION['must_change']);$users=$manager&&!$forced?csp_users():[];
$audit=$manager&&!$forced?csp_sql('SELECT stamp,actor,action,detail FROM audit ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC):[];
function h($s): string{return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function token(): void{echo '<input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'">';}
?>
<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>CSP · Account e utenti</title>
<style>body{margin:0;background:#0c1422;color:#edf2f7;font:16px system-ui}main{max-width:1100px;margin:auto;padding:24px}a{color:#a9d8ff}header,.actions{display:flex;gap:16px;align-items:center;flex-wrap:wrap}header{justify-content:space-between}section,details{background:#182438;border:1px solid #35455e;padding:22px;margin:20px 0;border-radius:12px}h1{font-size:26px}h2{font-size:21px}label{display:block;margin:14px 0}input,select,button{font:inherit;padding:11px;border:1px solid #62758e;border-radius:6px;background:#0e1a2c;color:#edf2f7}input{display:block;box-sizing:border-box;width:min(100%,420px);margin-top:6px}button{background:#a9d8ff;color:#102438;font-weight:700;cursor:pointer}summary{cursor:pointer;font-weight:700}p{line-height:1.5}.ok{padding:14px;background:#193b34}.error{padding:14px;background:#592c37}.scroll{overflow-x:auto}table{border-collapse:collapse;width:100%}td,th{padding:12px;border-bottom:1px solid #35455e;text-align:left;vertical-align:top}td form{margin:8px 0}code{overflow-wrap:anywhere}small{color:#b8c6d8}button:focus-visible,a:focus-visible,input:focus-visible,select:focus-visible{outline:3px solid #eac95f}</style></head><body><main>
<header><div><h1><?= $manager?'Account e gestione utenti':'Il mio account' ?></h1><p><?=h($_SESSION['admin'])?> · <?=$manager?'Amministratore':'Operatore'?></p></div><div class="actions"><?php if(!$forced):?><a href="admin.php">← Quadro operativo</a><?php endif?><form method="post" action="admin.php"><?php token();?><button name="logout">Esci</button></form></div></header>
<?php if($error):?><p class="error" role="alert"><?=h($error)?></p><?php endif?>
<?php if($message):?><p class="ok" role="status"><?=h($message)?></p><?php endif?>
<p>Archivio: <strong><?=csp_database_kind()==='pgsql'?'PostgreSQL esterno · indipendente dal disco Render':'SQLite locale · richiede disco persistente per conservare i dati'?></strong>.</p>
<?php if($forced):?><p class="error">Prima di usare il pannello devi sostituire la password temporanea con una personale.</p><?php endif?>
<section><h2>Cambia la tua password</h2><p>Usa almeno 12 caratteri. Le altre sessioni del tuo account saranno chiuse.</p><form method="post"><?php token();?><input type="hidden" name="action" value="password"><label>Password attuale o temporanea<input type="password" name="current" autocomplete="current-password" required maxlength="72"></label><label>Nuova password<input type="password" name="password" autocomplete="new-password" minlength="12" maxlength="72" required></label><label>Ripeti nuova password<input type="password" name="confirm" autocomplete="new-password" minlength="12" maxlength="72" required></label><button>Cambia password</button></form></section>
<?php if($manager&&!$forced):?>
<section><h2>Crea un account</h2><p>L’operatore gestisce corse e avvisi. L’amministratore gestisce anche utenti e strumenti Telegram.</p><form method="post"><?php token();?><input type="hidden" name="action" value="create"><label>Nome utente<input name="username" autocomplete="off" pattern="[a-zA-Z0-9][a-zA-Z0-9._-]{2,63}" maxlength="64" required></label><label>Ruolo <select name="role"><option value="operator">Operatore</option><option value="admin">Amministratore</option></select></label><label>Password temporanea<input type="password" name="password" autocomplete="new-password" minlength="12" maxlength="72" required></label><button>Crea account</button></form></section>
<section><h2>Utenti</h2><p>Disabilitare un account conserva il suo registro. Non puoi disabilitare o declassare te stesso e deve restare almeno un amministratore attivo.</p><div class="scroll"><table><thead><tr><th>Utente</th><th>Ruolo e accesso</th><th>Password</th></tr></thead><tbody>
<?php foreach($users as $u):$self=(int)$u['id']===(int)$_SESSION['user_id'];?><tr><td><strong><?=h($u['username'])?></strong><?=$self?' (tu)':''?><br><small><?=$u['must_change']?'Cambio password richiesto':''?></small></td><td><?php if(!$self):?><form method="post"><?php token();?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?=h($u['id'])?>"><label>Ruolo <select name="role"><option value="operator" <?=$u['role']==='operator'?'selected':''?>>Operatore</option><option value="admin" <?=$u['role']==='admin'?'selected':''?>>Amministratore</option></select></label><label>Accesso <select name="active"><option value="1" <?=$u['active']?'selected':''?>>Attivo</option><option value="0" <?=!$u['active']?'selected':''?>>Disabilitato</option></select></label><button>Salva utente</button></form><?php else:?>Amministratore · attivo<?php endif?></td><td><?php if(!$self):?><details><summary>Reimposta password</summary><form method="post"><?php token();?><input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?=h($u['id'])?>"><label>Nuova password temporanea<input name="password" type="password" autocomplete="new-password" minlength="12" maxlength="72" required></label><button>Reimposta e revoca sessioni</button></form></details><?php else:?><a href="#">Usa il modulo personale sopra</a><?php endif?></td></tr><?php endforeach?>
</tbody></table></div></section>
<section><h2>Registro attività</h2><p>Ultime 100 operazioni, comprese le attività degli utenti e gli invii manuali accodati. Le password non vengono registrate.</p><div class="scroll"><table><thead><tr><th>Data e ora</th><th>Utente</th><th>Azione</th><th>Dettagli</th></tr></thead><tbody><?php foreach($audit as $a):?><tr><td><?=h(date('d/m/Y H:i:s',(int)$a['stamp']))?></td><td><?=h($a['actor'])?></td><td><?=h($a['action'])?></td><td><code><?=h($a['detail'])?></code></td></tr><?php endforeach?></tbody></table></div></section>
<?php endif?></main></body></html>
