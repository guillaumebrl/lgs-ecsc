<?php

declare(strict_types=1);

function nav_active(string $name): string
{
    global $page;

    return $page === $name ? 'active' : '';
}

function header_html(string $title): void
{
    global $settings, $flash;
    ?>
    <!doctype html>
    <html lang="fr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title><?= e($title) ?> — lgs-ecsc</title>
        <link rel="stylesheet" href="assets/app.css?v=25">
        <link rel="stylesheet" href="assets/branding.css?v=93">
        <link rel="stylesheet" href="assets/accounts.css?v=25">
        <link rel="stylesheet" href="assets/structure.css?v=25">
    </head>
    <body>
    <aside class="sidebar">
        <a class="brand" href="index.php">
            <img class="brand-logo" src="assets/logo-sainte-charlotte.png" alt="Logo Sainte Charlotte">
            <strong>lgs-ecsc</strong>
        </a>
        <nav>
            <a class="<?= nav_active('dashboard') ?>" href="index.php">⌂ <span>Tableau de bord</span></a>
            <?php if (management_mode_active()): ?>
                <a class="<?= nav_active('students') ?>" href="?page=students">♙ <span>Élèves</span></a>
            <?php endif; ?>
            <a class="<?= nav_active('my-students') ?>" href="?page=my-students">♙ <span>Mes élèves</span></a>
            <a class="<?= nav_active('grades') ?>" href="?page=grades">▦ <span>Évaluations</span></a>
            <?php if (principal_mode_active() || direction_mode_active()): ?>
                <a class="<?= nav_active('council') ?>" href="?page=council">◫ <span>Conseil</span></a>
                <a class="<?= nav_active('direction') ?>" href="?page=direction">✓ <span>Documents</span></a>
            <?php endif; ?>
            <?php if (school_life_mode_active()): ?>
                <a class="<?= nav_active('school-life') ?>" href="?page=school-life">◷ <span>Vie scolaire</span></a>
            <?php endif; ?>
            <?php if (management_mode_active()): ?>
                <a class="<?= nav_active('structure') ?>" href="?page=structure">◇ <span>Organisation</span></a>
            <?php endif; ?>
            <?php if (is_admin()): ?>
                <a class="<?= nav_active('users') ?>" href="?page=users">♧ <span>Comptes</span></a>
                <a class="<?= nav_active('settings') ?>" href="?page=settings">⚙ <span>Réglages</span></a>
            <?php endif; ?>
        </nav>
        <div class="sidebar-user">
            <small><?= e(is_admin() ? 'Administrateur' : account_qualifications(user())) ?></small>
            <strong><?= e(user()['name']) ?></strong>
            <a href="?page=change-password">Changer mon mot de passe</a>
            <a href="?page=logout">Se déconnecter</a>
        </div>
    </aside>
    <main class="main">
        <header class="topbar">
            <div>
                <p class="eyebrow"><?= e($settings['school_name']) ?></p>
                <h1><?= e($title) ?></h1>
            </div>
            <div class="today"><?= date('d/m/Y') ?></div>
        </header>
        <?php if ($flash): ?>
            <div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>
    <?php
}

function footer_html(): void
{
    global $settings;
    ?>
        <span class="user-role-marker" data-role="<?= e(user()['role']) ?>" hidden></span>
        <span class="settings-marker" data-comment-limit="<?= e($settings['comment_max_length'] ?? 500) ?>" hidden></span>
        <?php if (user()['role'] === 'admin'): ?>
            <span class="admin-marker" hidden></span>
        <?php endif; ?>
        <link rel="stylesheet" href="assets/icons.css?v=60">
        <link rel="stylesheet" href="assets/unified-ui.css?v=103">
        <link rel="stylesheet" href="assets/password-toggle.css?v=105">
        <footer>lgs-ecsc · gestion scolaire sécurisée</footer>
    </main>
    <script src="assets/app.js?v=105"></script>
    </body>
    </html>
    <?php
}
