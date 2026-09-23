<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
$error = null;
$installed = false;
try {
    $installed = (bool)db()->query("SHOW TABLES LIKE 'users'")->fetchColumn() && (bool)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
} catch (Throwable $e) {
    $error = $e->getMessage();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installed) {
    verify_csrf();
    try {
        $sql = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
        foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sql))) as $statement) {
            db()->exec($statement);
        }
        $firstName = trim($_POST['first_name']);
        $lastName = strtoupper(trim($_POST['last_name']));
        $email = strtolower(trim($_POST['email'] ?? '')) ?: null;
        $stmt = db()->prepare('INSERT INTO users(first_name,last_name,name,login_identifier,email,password,role) VALUES(?,?,?,?,?,?,"admin")');
        $stmt->execute([$firstName, $lastName, trim($firstName.' '.$lastName), strtolower(trim($_POST['login_identifier'])), $email, password_hash($_POST['password'], PASSWORD_DEFAULT)]);
        $installed = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Installation</title><link rel="stylesheet" href="assets/app.css"><link rel="stylesheet" href="assets/branding.css"></head><body class="login-body"><main class="login-card"><img class="login-logo" src="assets/logo-sainte-charlotte.png" alt="Logo École et Collège Sainte Charlotte"><h1>Installation de lgs-ecsc</h1><?php if ($installed): ?><div class="alert success">Installation terminée. Supprimez ou renommez <code>install.php</code>.</div><a class="button" href="index.php?page=login">Se connecter</a><?php else: ?><?php if ($error): ?><div class="alert error"><?=e($error)?></div><?php endif; ?><p>La connexion MySQL doit d’abord être renseignée dans <code>.env</code>.</p><form method="post"><input type="hidden" name="_token" value="<?=csrf()?>"><div class="form-row"><label>Nom<input required name="last_name"></label><label>Prénom<input required name="first_name"></label></div><label>Identifiant de connexion<input required name="login_identifier"></label><label>Adresse e-mail <small>(facultatif)</small><input type="email" name="email"></label><label>Mot de passe initial<input required minlength="12" type="password" name="password"></label><button>Créer la base et le compte</button></form><?php endif; ?></main></body></html>
