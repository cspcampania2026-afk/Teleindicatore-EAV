<?php
require_once __DIR__.'/csp_core.php';
header('Cache-Control: no-store, no-cache, must-revalidate');header('Pragma: no-cache');header('Expires: 0');header('X-Frame-Options: DENY');header('X-Content-Type-Options: nosniff');
csp_session();
if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));
$error='';
try {
    if(($_SERVER['REQUEST_METHOD']??'')==='POST') {
        csp_csrf();
        if(isset($_POST['logout'])) {if(!empty($_SESSION['admin']))csp_audit($_SESSION['admin'],'logout',[]);$_SESSION=[];session_regenerate_id(true);header('Location: admin.php');exit;}
        if(!csp_login((string)($_POST['user']??''),(string)($_POST['password']??''))) $error='Credenziali non valide o accesso non configurato.';
    }
} catch(Throwable $e) {$error=$e->getMessage();}
if(empty($_SESSION['admin'])) {
?>
<!doctype html><html lang="it"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>CSP · Accesso interno</title>
<style>body{margin:0;background:#0c1422;color:#edf2f7;font:17px system-ui;display:grid;min-height:100vh;place-items:center}form{width:min(360px,80vw);background:#182438;padding:32px;border-radius:18px}label,input,button{display:block;width:100%;box-sizing:border-box;margin:12px 0}input,button{padding:14px;border-radius:7px;border:1px solid #72829b}button{background:#a9d8ff;font-weight:bold;color:#102438}p{color:#ffc3c3}</style>
<form method="post"><h1>CSP · Area interna</h1><div>Gestione teleindicatori e Telegram</div><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'])?>"><label>Nome utente<input name="user" autocomplete="username" required></label><label>Password<input type="password" name="password" autocomplete="current-password" required></label><button>Accedi</button><p role="alert"><?=htmlspecialchars($error)?></p></form></html>
<?php exit; }
if(!empty($_SESSION['must_change'])) {header('Location: utenti.php');exit;}
$identity=['username'=>$_SESSION['admin'],'role'=>$_SESSION['role']];
$csrf=$_SESSION['csrf'];session_write_close();
$html=file_get_contents(__DIR__.'/admin_dashboard.html');
// Serve the current dashboard script together with the uncached authenticated page.
$html=str_replace('<script src="admin_dashboard.js"></script>','<script>'.str_replace('</script','<\\/script',file_get_contents(__DIR__.'/admin_dashboard.js')).'</script>',$html);
$bootstrap=['telegram'=>['channel'=>(bool)csp_targets('channel'),'group'=>(bool)csp_targets('group')],'csrf'=>$csrf,'routes'=>csp_routes(),'stations'=>csp_stations(),'trainLines'=>csp_train_lines()];
$html=str_replace('__BOOTSTRAP_JSON__',json_encode($bootstrap,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE),$html);
$html=str_replace('__IDENTITY_HTML__',htmlspecialchars($identity['username'].' · '.($identity['role']==='admin'?'Amministratore':'Operatore')),$html);
if($identity['role']!=='admin') $html=str_replace(['id="retryQueue"','id="cancelQueue"','id="checkBot"','id="readChatIds"'],['id="retryQueue" hidden disabled','id="cancelQueue" hidden disabled','id="checkBot" hidden disabled','id="readChatIds" hidden disabled'],$html);
echo str_replace('__CSRF_HTML__',htmlspecialchars($csrf),$html);
