<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
$error = null;
$installed = false;
try {
    $installed = (bool)db()->query("SHOW TABLES LIKE 'users'")->fetchColumn() && (bool)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
} catch (Throwable $e) { $error = $e->getMessage(); }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installed) {
    verify_csrf();
    try {
        $sql = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
        foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sql))) as $statement) db()->exec($statement);
        $stmt = db()->prepare('INSERT INTO users(name,email,password,role) VALUES(?,?,?,"admin")');
        $stmt->execute([trim($_POST['name']), strtolower(trim($_POST['email'])), password_hash($_POST['password'], PASSWORD_DEFAULT)]);
        $installed = true;
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Installation</title><link rel="stylesheet" href="assets/app.css"></head><body class="login-body"><main class="login-card"><div class="brand-mark">EP</div><h1>Installation d’École Pilot</h1><?php if ($installed): ?><div class="alert success">Installation terminée. Supprimez ou renommez <code>install.php</code>.</div><a class="button" href="index.php?page=login">Se connecter</a><?php else: ?><?php if ($error): ?><div class="alert error"><?=e($error)?></div><?php endif; ?><p>La connexion MySQL doit d’abord être renseignée dans <code>.env</code>.</p><form method="post"><input type="hidden" name="_token" value="<?=csrf()?>"><label>Nom de l’administrateur<input required name="name"></label><label>Adresse e-mail<input required type="email" name="email"></label><label>Mot de passe initial<input required minlength="12" type="password" name="password"></label><button>Créer la base et le compte</button></form><?php endif; ?></main></body></html>

