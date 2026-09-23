<?php
declare(strict_types=1);

function account_qualifications(array $account): string
{
    if ($account['role'] === 'admin') {
        return 'Administrateur';
    }
    $labels = [];
    if ($account['can_teach']) {
        $labels[] = 'Enseignant';
    }
    if ($account['can_be_principal']) {
        $labels[] = 'Prof. principal / instituteur';
    }
    if ($account['can_school_life']) {
        $labels[] = 'Vie scolaire';
    }
    if ($account['can_direction']) {
        $labels[] = 'Direction';
    }
    return $labels ? implode(' · ', $labels) : 'Aucune qualification';
}

function handle_account_actions(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    $action = $_POST['action'] ?? '';
    if (!in_array($action, ['create_account','save_account','reset_account_password','delete_account','change_password'], true)) {
        return;
    }
    if ($action === 'change_password') {
        require_login();
        if (strlen($_POST['password'] ?? '') < 12 || ($_POST['password'] ?? '') !== ($_POST['password_confirmation'] ?? '')) {
            flash('Le mot de passe doit contenir au moins 12 caractères et les deux saisies doivent correspondre.', 'error');
            redirect('index.php?page=change-password');
        }
        db()->prepare('UPDATE users SET password=?,must_change_password=0 WHERE id=?')->execute([password_hash($_POST['password'], PASSWORD_DEFAULT),user()['id']]);
        refresh_session_user();
        flash('Mot de passe modifié.');
        redirect('index.php');
    }
    require_role(['admin']);
    if ($action === 'create_account') {
        $first = trim($_POST['first_name']);
        $last = strtoupper(trim($_POST['last_name']));
        $isAdmin = isset($_POST['is_admin']);
        $role = $isAdmin ? 'admin' : (isset($_POST['can_direction']) ? 'direction' : (isset($_POST['can_be_principal']) ? 'principal' : 'teacher'));
        $identifier = strtolower(trim($_POST['login_identifier']));
        $email = strtolower(trim($_POST['email'] ?? '')) ?: null;
        $stmt = db()->prepare('INSERT INTO users(first_name,last_name,name,login_identifier,email,password,role,can_teach,can_be_principal,can_direction,can_school_life,must_change_password) VALUES(?,?,?,?,?,?,?,?,?,?,?,1)');
        $stmt->execute([$first,$last,trim($first.' '.$last),$identifier,$email,password_hash($_POST['password'], PASSWORD_DEFAULT),$role,$isAdmin ? 0 : (int)isset($_POST['can_teach']),$isAdmin ? 0 : (int)isset($_POST['can_be_principal']),$isAdmin ? 0 : (int)isset($_POST['can_direction']),$isAdmin ? 0 : (int)isset($_POST['can_school_life'])]);
        flash('Compte créé. Le changement de mot de passe sera exigé à la première connexion.');
    } elseif ($action === 'save_account') {
        $id = (int)$_POST['user_id'];
        $first = trim($_POST['first_name']);
        $last = strtoupper(trim($_POST['last_name']));
        $isAdmin = isset($_POST['is_admin']);
        $role = $isAdmin ? 'admin' : (isset($_POST['can_direction']) ? 'direction' : (isset($_POST['can_be_principal']) ? 'principal' : 'teacher'));
        $identifier = strtolower(trim($_POST['login_identifier']));
        $email = strtolower(trim($_POST['email'] ?? '')) ?: null;
        db()->prepare('UPDATE users SET first_name=?,last_name=?,name=?,login_identifier=?,email=?,role=?,can_teach=?,can_be_principal=?,can_direction=?,can_school_life=?,active=? WHERE id=?')->execute([$first,$last,trim($first.' '.$last),$identifier,$email,$role,$isAdmin ? 0 : (int)isset($_POST['can_teach']),$isAdmin ? 0 : (int)isset($_POST['can_be_principal']),$isAdmin ? 0 : (int)isset($_POST['can_direction']),$isAdmin ? 0 : (int)isset($_POST['can_school_life']),(int)isset($_POST['active']),$id]);
        if ($id === (int)user()['id']) {
            refresh_session_user();
        } flash('Compte mis à jour.');
    } elseif ($action === 'reset_account_password') {
        $id = (int)$_POST['user_id'];
        $account = one('SELECT role FROM users WHERE id=?', [$id]);
        if (!$account || $account['role'] === 'admin') {
            flash('Le mot de passe d’un administrateur ne peut pas être réinitialisé ici.', 'error');
            redirect('index.php?page=users');
        }
        $temporary = 'ECSC-'.strtoupper(bin2hex(random_bytes(4))).'!';
        db()->prepare('UPDATE users SET password=?,must_change_password=1 WHERE id=?')->execute([password_hash($temporary, PASSWORD_DEFAULT),$id]);
        $_SESSION['temporary_password'] = $temporary;
        flash('Mot de passe temporaire créé. Copiez-le maintenant.');
    } elseif ($action === 'delete_account') {
        $id = (int)$_POST['user_id'];
        if ($id === (int)user()['id']) {
            flash('Vous ne pouvez pas supprimer votre propre compte.', 'error');
            redirect('index.php?page=users');
        }
        $account = one('SELECT role FROM users WHERE id=?', [$id]);
        if (!$account || $account['role'] === 'admin') {
            flash('Un compte administrateur ne peut pas être supprimé ici.', 'error');
            redirect('index.php?page=users');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            foreach (['assessments' => 'author_id','grades' => 'updated_by','subject_comments' => 'author_id','attendance' => 'author_id','bulletin_snapshots' => 'created_by'] as $table => $column) {
                $pdo->prepare("UPDATE $table SET $column=? WHERE $column=?")->execute([user()['id'],$id]);
            }
            $pdo->prepare('UPDATE classes SET principal_user_id=NULL WHERE principal_user_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM class_principals WHERE user_id=?')->execute([$id]);
            $pdo->prepare('UPDATE councils SET validated_by=NULL WHERE validated_by=?')->execute([$id]);
            $pdo->prepare('UPDATE audit_logs SET user_id=NULL WHERE user_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
            $pdo->commit();
            flash('Compte supprimé. L’historique pédagogique a été conservé.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
    redirect('index.php?page=users');
}

function render_change_password_page(): never
{
    require_login();
    header_html('Modifier mon mot de passe');?><form class="panel narrow" method="post"><p class="eyebrow">Sécurité</p><h2>Nouveau mot de passe obligatoire</h2><p>Choisissez un mot de passe personnel d’au moins 12 caractères.</p><input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="change_password"><label>Nouveau mot de passe<input required minlength="12" type="password" name="password"></label><label>Confirmation<input required minlength="12" type="password" name="password_confirmation"></label><button>Enregistrer mon mot de passe</button></form><?php footer_html();
    exit;
}

function render_accounts_page(): never
{
    require_role(['admin']);
    $users = rows('SELECT * FROM users ORDER BY active DESC,last_name,first_name');
    $editId = (int)($_GET['edit'] ?? 0);
    $selected = null;
    foreach ($users as $account) {
        if ((int)$account['id'] === $editId) {
            $selected = $account;
            break;
        }
    }
    $temporary = $_SESSION['temporary_password'] ?? null;
    unset($_SESSION['temporary_password']);
    header_html('Comptes utilisateurs');
    ?>
    <?php if ($temporary):?>
        <div class="alert info"><strong>Mot de passe temporaire : <?=e($temporary)?></strong><br>Il ne sera plus affiché après avoir quitté cette page.</div>
    <?php endif?>
    <div class="two-col">
        <section class="panel">
            <div class="panel-head">
                <div><p class="eyebrow">Utilisateurs</p><h2>Comptes</h2></div>
                <span class="badge"><?=count($users)?> compte<?=count($users) > 1 ? 's' : ''?></span>
            </div>
            <div class="table-wrap accounts-table-wrap" tabindex="0" aria-label="Tableau des comptes, défilement horizontal possible">
                <table class="accounts-table">
                    <thead><tr><th>Nom et prénom</th><th>Identifiant</th><th>E-mail</th><th>Qualifications</th><th>État</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($users as $account):?>
                        <tr class="<?=$selected && (int)$selected['id'] === (int)$account['id'] ? 'selected-row' : ''?>">
                            <td><strong><?=e($account['last_name'].' '.$account['first_name'])?></strong></td>
                            <td><?=e($account['login_identifier'])?></td><td><?=e($account['email'] ?: '—')?></td>
                            <td><?=e(account_qualifications($account))?></td>
                            <td><span class="badge <?=$account['active'] ? 'success' : 'muted'?>"><?=$account['active'] ? 'Actif' : 'Inactif'?></span></td>
                            <td><span class="table-actions"><a class="icon-button" href="index.php?page=users&amp;edit=<?=$account['id']?>" title="Modifier" aria-label="Modifier"><i class="bi bi-pencil-square"></i></a></span></td>
                        </tr>
                    <?php endforeach?>
                    </tbody>
                </table>
            </div>
        </section>

        <aside class="sticky account-editor">
        <?php if ($selected):?>
            <form class="panel" method="post">
                <div class="panel-head">
                    <div><p class="eyebrow">Compte sélectionné</p><h2>Modifier le compte</h2></div>
                    <a class="button secondary compact" href="index.php?page=users">Nouveau</a>
                </div>
                <input type="hidden" name="_token" value="<?=csrf()?>">
                <input type="hidden" name="action" value="save_account">
                <input type="hidden" name="user_id" value="<?=$selected['id']?>">
                <div class="form-row"><label>Nom<input required name="last_name" value="<?=e($selected['last_name'])?>"></label><label>Prénom<input required name="first_name" value="<?=e($selected['first_name'])?>"></label></div>
                <label>Identifiant de connexion<input required name="login_identifier" value="<?=e($selected['login_identifier'])?>" autocomplete="off"></label>
                <label>E-mail <small>(facultatif)</small><input type="email" name="email" value="<?=e($selected['email'])?>"></label>
                <?php account_checks($selected);?>
                <label class="check"><input type="checkbox" name="active" value="1" <?=$selected['active'] ? 'checked' : ''?>> Compte actif</label>
                <button>Enregistrer les modifications</button>
            </form>
            <?php if ($selected['role'] !== 'admin'):?>
                <div class="panel account-danger-zone">
                    <h3>Gestion du compte</h3>
                    <form method="post"><input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="reset_account_password"><input type="hidden" name="user_id" value="<?=$selected['id']?>"><button class="secondary">Réinitialiser le mot de passe</button></form>
                    <form method="post" onsubmit="return confirm('Supprimer définitivement ce compte ?');"><input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="delete_account"><input type="hidden" name="user_id" value="<?=$selected['id']?>"><button class="danger-button">Supprimer le compte</button></form>
                </div>
            <?php endif?>
        <?php else:?>
            <form class="panel" method="post">
                <p class="eyebrow">Nouvel utilisateur</p><h2>Créer un compte</h2>
                <input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="create_account">
                <div class="form-row"><label>Nom<input required name="last_name"></label><label>Prénom<input required name="first_name"></label></div>
                <label>Identifiant de connexion<input required name="login_identifier" placeholder="prenom.nom" autocomplete="off"></label>
                <label>E-mail <small>(facultatif)</small><input type="email" name="email"></label>
                <label>Mot de passe temporaire<input required minlength="12" type="password" name="password"></label>
                <?php account_checks(null);?>
                <button>Créer le compte</button>
            </form>
        <?php endif?>
        </aside>
    </div>
    <?php footer_html();
    exit;
}

function account_checks(?array $account): void
{
    $admin = ($account['role'] ?? '') === 'admin';?><div class="inline-checks qualifications" data-account-qualifications><label class="check"><input type="checkbox" name="is_admin" value="1" data-admin-qualification <?=$admin ? 'checked' : ''?>> Administrateur</label><label class="check <?=$admin ? 'qualification-disabled' : ''?>"><input type="checkbox" name="can_teach" value="1" data-staff-qualification <?=!empty($account['can_teach']) ? 'checked' : ''?> <?=$admin ? 'disabled' : ''?>> Enseignant</label><label class="check <?=$admin ? 'qualification-disabled' : ''?>"><input type="checkbox" name="can_be_principal" value="1" data-staff-qualification <?=!empty($account['can_be_principal']) ? 'checked' : ''?> <?=$admin ? 'disabled' : ''?>> Prof. principal / instituteur</label><label class="check <?=$admin ? 'qualification-disabled' : ''?>"><input type="checkbox" name="can_school_life" value="1" data-staff-qualification <?=!empty($account['can_school_life']) ? 'checked' : ''?> <?=$admin ? 'disabled' : ''?>> Vie scolaire</label><label class="check <?=$admin ? 'qualification-disabled' : ''?>"><input type="checkbox" name="can_direction" value="1" data-staff-qualification <?=!empty($account['can_direction']) ? 'checked' : ''?> <?=$admin ? 'disabled' : ''?>> Direction</label></div><?php }
