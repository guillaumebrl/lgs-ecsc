<?php
declare(strict_types=1);

function handle_admin_management_actions(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $action = $_POST['action'] ?? '';
    if (!in_array($action, ['update_user', 'update_subject', 'add_group', 'save_group_students', 'update_course_group'], true)) return;
    require_role(['admin']);
    $pdo = db();

    if ($action === 'update_user') {
        $id = (int)$_POST['user_id'];
        $before = one('SELECT id,name,email,role,can_teach,can_be_principal,active FROM users WHERE id=?', [$id]);
        $isAdmin = ($_POST['account_type'] ?? '') === 'admin';
        $canTeach = $isAdmin ? 0 : (isset($_POST['can_teach']) ? 1 : 0);
        $canPrincipal = $isAdmin ? 0 : (isset($_POST['can_be_principal']) ? 1 : 0);
        $role = $isAdmin ? 'admin' : ($canPrincipal ? 'principal' : 'teacher');
        $stmt = $pdo->prepare('UPDATE users SET name=?,email=?,role=?,can_teach=?,can_be_principal=?,active=? WHERE id=?');
        $stmt->execute([trim($_POST['name']), strtolower(trim($_POST['email'])), $role, $canTeach, $canPrincipal, isset($_POST['active']) ? 1 : 0, $id]);
        audit('update', 'user', $id, $before, ['role'=>$role,'can_teach'=>$canTeach,'can_be_principal'=>$canPrincipal]);
        flash('Compte et qualifications mis à jour.');
        redirect('index.php?page=admin-management&tab=accounts');
    }

    if ($action === 'update_subject') {
        $id = (int)$_POST['subject_id'];
        $before = one('SELECT * FROM subjects WHERE id=?', [$id]);
        $pdo->prepare('UPDATE subjects SET name=?,coefficient=? WHERE id=?')->execute([trim($_POST['name']), max(.01, (float)$_POST['coefficient']), $id]);
        audit('update', 'subject', $id, $before, ['name'=>trim($_POST['name']),'coefficient'=>(float)$_POST['coefficient']]);
        flash('Matière et coefficient mis à jour.');
        redirect('index.php?page=admin-management&tab=subjects');
    }

    if ($action === 'add_group') {
        $stmt = $pdo->prepare('INSERT INTO class_groups(class_id,name) VALUES(?,?)');
        $stmt->execute([(int)$_POST['class_id'], trim($_POST['name'])]);
        audit('create', 'class_group', (int)$pdo->lastInsertId());
        flash('Groupe créé.');
        redirect('index.php?page=admin-management&tab=groups');
    }

    if ($action === 'save_group_students') {
        $groupId = (int)$_POST['group_id'];
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM group_students WHERE group_id=?')->execute([$groupId]);
            $stmt = $pdo->prepare('INSERT INTO group_students(group_id,student_id) VALUES(?,?)');
            foreach ($_POST['student_ids'] ?? [] as $studentId) $stmt->execute([$groupId, (int)$studentId]);
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        audit('update', 'group_students', $groupId);
        flash('Composition du groupe enregistrée.');
        redirect('index.php?page=admin-management&tab=groups&group_id='.$groupId);
    }

    if ($action === 'update_course_group') {
        $courseId = (int)$_POST['course_id'];
        $groupId = $_POST['class_group_id'] !== '' ? (int)$_POST['class_group_id'] : null;
        $pdo->prepare('UPDATE courses SET class_group_id=? WHERE id=?')->execute([$groupId, $courseId]);
        audit('update', 'course', $courseId, null, ['class_group_id'=>$groupId]);
        flash('Groupe du cours mis à jour.');
        redirect('index.php?page=admin-management&tab=groups');
    }
}

function render_admin_management_page(): never {
    require_role(['admin']);
    $tab = $_GET['tab'] ?? 'accounts';
    $users = rows('SELECT * FROM users ORDER BY name');
    $subjects = rows('SELECT * FROM subjects ORDER BY name');
    $classes = rows('SELECT c.*,y.name year_name FROM classes c JOIN school_years y ON y.id=c.school_year_id ORDER BY y.starts_on DESC,c.name');
    $groups = rows('SELECT cg.*,c.name class_name,COUNT(gs.student_id) student_count FROM class_groups cg JOIN classes c ON c.id=cg.class_id LEFT JOIN group_students gs ON gs.group_id=cg.id GROUP BY cg.id ORDER BY c.name,cg.name');
    $courses = rows('SELECT co.id,co.class_id,co.class_group_id,c.name class_name,s.name subject FROM courses co JOIN classes c ON c.id=co.class_id JOIN subjects s ON s.id=co.subject_id ORDER BY c.name,s.name');
    $selectedGroupId = (int)($_GET['group_id'] ?? 0);
    $selectedGroup = $selectedGroupId ? one('SELECT * FROM class_groups WHERE id=?', [$selectedGroupId]) : null;
    $groupStudents = $selectedGroup ? rows('SELECT s.id,s.last_name,s.first_name,IF(gs.student_id IS NULL,0,1) selected FROM enrollments e JOIN students s ON s.id=e.student_id LEFT JOIN group_students gs ON gs.student_id=s.id AND gs.group_id=? WHERE e.class_id=? ORDER BY s.last_name,s.first_name', [$selectedGroupId,$selectedGroup['class_id']]) : [];
    header_html('Administration des données');
    ?><link rel="stylesheet" href="assets/families.css"><link rel="stylesheet" href="assets/admin.css">
    <div class="tabs admin-tabs"><a class="button <?=$tab==='accounts'?'':'secondary'?>" href="?page=admin-management&tab=accounts">Comptes</a><a class="button <?=$tab==='subjects'?'':'secondary'?>" href="?page=admin-management&tab=subjects">Matières</a><a class="button <?=$tab==='groups'?'':'secondary'?>" href="?page=admin-management&tab=groups">Groupes</a></div>
    <?php if ($tab === 'accounts'): ?>
      <section class="panel"><p class="eyebrow">Accès et qualifications</p><h2>Modifier les comptes</h2><div class="admin-card-grid">
      <?php foreach($users as $account): ?><form class="edit-card" method="post"><input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="update_user"><input type="hidden" name="user_id" value="<?=$account['id']?>"><div class="form-row"><label>Nom<input name="name" required value="<?=e($account['name'])?>"></label><label>E-mail<input name="email" type="email" required value="<?=e($account['email'])?>"></label></div><label>Type de compte<select name="account_type"><option value="staff" <?=$account['role']!=='admin'?'selected':''?>>Personnel pédagogique</option><option value="admin" <?=$account['role']==='admin'?'selected':''?>>Administrateur</option></select></label><?php if($account['role']!=='admin'):?><div class="inline-checks"><label class="check"><input type="checkbox" name="can_teach" value="1" <?=$account['can_teach']?'checked':''?>> Enseignant</label><label class="check"><input type="checkbox" name="can_be_principal" value="1" <?=$account['can_be_principal']?'checked':''?>> Professeur principal / instituteur</label></div><?php endif?><label class="check"><input type="checkbox" name="active" value="1" <?=$account['active']?'checked':''?>> Compte actif</label><button>Enregistrer</button></form><?php endforeach?></div></section>
    <?php elseif ($tab === 'subjects'): ?>
      <section class="panel"><p class="eyebrow">Référentiel</p><h2>Matières et coefficients</h2><div class="admin-card-grid"><?php foreach($subjects as $subject):?><form class="edit-card compact" method="post"><input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="update_subject"><input type="hidden" name="subject_id" value="<?=$subject['id']?>"><label>Matière<input required name="name" value="<?=e($subject['name'])?>"></label><label>Coefficient<input required type="number" min="0.01" step="0.01" name="coefficient" value="<?=e($subject['coefficient'])?>"></label><button>Mettre à jour</button></form><?php endforeach?></div></section>
    <?php else: ?>
      <div class="two-col"><section><form class="panel" method="post"><h2>Créer un groupe</h2><input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="add_group"><label>Classe et niveau<select name="class_id"><?php foreach($classes as $class):?><option value="<?=$class['id']?>"><?=e($class['name'].' · '.$class['level'].' · '.$class['year_name'])?></option><?php endforeach?></select></label><label>Nom du groupe<input required name="name" placeholder="Groupe A"></label><button>Créer</button></form><section class="panel"><h2>Groupes existants</h2><?php foreach($groups as $group):?><a class="family-card <?=$selectedGroupId===$group['id']?'selected':''?>" href="?page=admin-management&tab=groups&group_id=<?=$group['id']?>"><span><strong><?=e($group['name'])?></strong><small><?=e($group['class_name'])?></small></span><span><?=e($group['student_count'])?> élève(s)</span></a><?php endforeach?></section></section><section><?php if($selectedGroup):?><form class="panel" method="post"><h2>Élèves — <?=e($selectedGroup['name'])?></h2><input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="save_group_students"><input type="hidden" name="group_id" value="<?=$selectedGroupId?>"><div class="student-checklist"><?php foreach($groupStudents as $student):?><label class="check"><input type="checkbox" name="student_ids[]" value="<?=$student['id']?>" <?=$student['selected']?'checked':''?>> <?=e($student['last_name'].' '.$student['first_name'])?></label><?php endforeach?></div><button>Enregistrer la composition</button></form><?php endif?><section class="panel"><h2>Affecter un groupe aux cours</h2><?php foreach($courses as $course):?><form class="course-group-row" method="post"><input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="update_course_group"><input type="hidden" name="course_id" value="<?=$course['id']?>"><strong><?=e($course['class_name'].' · '.$course['subject'])?></strong><select name="class_group_id"><option value="">Classe entière</option><?php foreach($groups as $group): if($group['class_id']===$course['class_id']):?><option value="<?=$group['id']?>" <?=$course['class_group_id']===$group['id']?'selected':''?>><?=e($group['name'])?></option><?php endif; endforeach?></select><button>Associer</button></form><?php endforeach?></section></section></div>
    <?php endif; footer_html(); exit;
}
