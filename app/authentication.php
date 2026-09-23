<?php

declare(strict_types=1);

function handle_login_page(): never
{
    $error = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $identifier = strtolower(trim($_POST['identifier'] ?? ''));
        $statement = db()->prepare(
            'SELECT *
             FROM users
             WHERE (login_identifier = ? OR email = ?) AND active = 1
             LIMIT 1'
        );
        $statement->execute([$identifier, $identifier]);
        $account = $statement->fetch();

        if ($account && password_verify($_POST['password'] ?? '', $account['password'])) {
            session_regenerate_id(true);
            unset($account['password']);
            $_SESSION['user'] = $account;
            audit('login', 'user', (int) $account['id']);

            redirect(
                !empty($account['must_change_password'])
                    ? 'index.php?page=change-password'
                    : 'index.php'
            );
        }

        $error = 'Identifiants incorrects.';
    }

    ?>
    <!doctype html>
    <html lang="fr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width">
        <title>Connexion — lgs-ecsc</title>
        <link rel="stylesheet" href="assets/app.css">
        <link rel="stylesheet" href="assets/branding.css">
        <link rel="stylesheet" href="assets/password-toggle.css?v=105">
        <script src="assets/app.js?v=105" defer></script>
    </head>
    <body class="login-body">
    <main class="login-card">
        <img
            class="login-logo"
            src="assets/logo-sainte-charlotte.png"
            alt="Logo École et Collège Sainte Charlotte"
        >
        <p class="eyebrow">Portail pédagogique</p>
        <h1>Bienvenue</h1>
        <p class="muted">Connectez-vous avec votre identifiant ou votre adresse e-mail.</p>
        <?php if ($error !== null): ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="_token" value="<?= csrf() ?>">
            <label>
                Identifiant ou adresse e-mail
                <input name="identifier" required autofocus autocomplete="username">
            </label>
            <label>
                Mot de passe
                <input type="password" name="password" required autocomplete="current-password">
            </label>
            <button>Se connecter</button>
        </form>
    </main>
    </body>
    </html>
    <?php
    exit;
}

function handle_logout(): never
{
    session_destroy();
    redirect('index.php?page=login');
}
