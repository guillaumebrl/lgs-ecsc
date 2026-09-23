<?php
declare(strict_types=1);

function handle_admin_management_actions(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    $action = $_POST['action'] ?? '';
    if (!in_array($action, ['update_user', 'update_subject', 'delete_subject', 'add_group', 'update_group', 'delete_group', 'save_group_students', 'update_course_group', 'save_assignment', 'bulk_save_assignments', 'delete_assignment', 'update_period', 'delete_period', 'add_subperiod', 'update_subperiod', 'delete_subperiod', 'set_active_year', 'update_class', 'delete_class', 'prepare_school_year'], true)) {
        return;
    }
    if (in_array($action, ['update_user','set_active_year','prepare_school_year'], true)) {
        require_role(['admin']);
    } else {
        require_management_mode();
    }
    $pdo = db();

    if ($action === 'set_active_year') {
        $yearId = (int)($_POST['year_id'] ?? 0);
        if (!one('SELECT id FROM school_years WHERE id=?', [$yearId])) {
            flash('Année scolaire introuvable.', 'error');
            redirect('index.php?page=structure&section=years');
        }
        $pdo->beginTransaction();
        try {
            $pdo->exec('UPDATE school_years SET active=0');
            $pdo->prepare('UPDATE school_years SET active=1 WHERE id=?')->execute([$yearId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        audit('activate', 'school_year', $yearId);
        flash('Année scolaire active mise à jour.');
        redirect('index.php?page=structure&section=years');
    }

    if ($action === 'update_period') {
        $periodId = (int) $_POST['period_id'];
        $status = in_array($_POST['status'] ?? '', ['open', 'closed'], true) ? $_POST['status'] : 'closed';
        $startsOn = (string) ($_POST['starts_on'] ?? '');
        $endsOn = (string) ($_POST['ends_on'] ?? '');
        if ($startsOn === '' || $endsOn === '' || $startsOn > $endsOn) {
            flash('Les dates du trimestre sont invalides.', 'error');
            redirect('index.php?page=structure&section=years&edit_period='.$periodId);
        }
        $pdo->prepare('UPDATE periods SET name=?,starts_on=?,ends_on=?,entry_opens_on=?,entry_closes_on=?,status=?,sort_order=? WHERE id=?')->execute([
            trim($_POST['name']), $startsOn, $endsOn, $_POST['entry_opens_on'] ?: null,
            $_POST['entry_closes_on'] ?: null, $status, max(1, (int) $_POST['sort_order']), $periodId,
        ]);
        flash('Trimestre mis à jour.');
        redirect('index.php?page=structure&section=years');
    }

    if ($action === 'delete_period') {
        $periodId = (int) $_POST['period_id'];
        $period = one('SELECT * FROM periods WHERE id=?', [$periodId]);
        $usage = one('SELECT (SELECT COUNT(*) FROM assessments WHERE period_id=?) assessments_count,(SELECT COUNT(*) FROM subject_comments WHERE period_id=?) comments_count,(SELECT COUNT(*) FROM councils WHERE period_id=?) councils_count,(SELECT COUNT(*) FROM attendance WHERE period_id=?) attendance_count,(SELECT COUNT(*) FROM bulletin_snapshots WHERE period_id=?) snapshots_count', [$periodId, $periodId, $periodId, $periodId, $periodId]);
        if (!$period) {
            flash('Trimestre introuvable.', 'error');
        } elseif (array_sum(array_map('intval', $usage ?: [])) > 0) {
            flash('Suppression impossible : ce trimestre contient déjà des données scolaires.', 'error');
        } else {
            $pdo->prepare('DELETE FROM periods WHERE id=?')->execute([$periodId]);
            audit('delete', 'period', $periodId, $period);
            flash('Trimestre supprimé.');
        }
        redirect('index.php?page=structure&section=years');
    }

    if ($action === 'add_subperiod' || $action === 'update_subperiod') {
        $periodId = (int) $_POST['period_id'];
        $startsOn = (string) ($_POST['starts_on'] ?? '');
        $endsOn = (string) ($_POST['ends_on'] ?? '');
        $period = one('SELECT starts_on,ends_on FROM periods WHERE id=?', [$periodId]);
        if (!$period || $startsOn < $period['starts_on'] || $endsOn > $period['ends_on'] || $startsOn > $endsOn) {
            flash('La sous-période doit être comprise dans les dates du trimestre.', 'error');
            redirect('index.php?page=structure&section=years');
        }
        $values = [trim($_POST['name']), $startsOn, $endsOn, max(1, (int) ($_POST['sort_order'] ?? 1))];
        if ($action === 'add_subperiod') {
            $pdo->prepare('INSERT INTO period_subperiods(period_id,name,starts_on,ends_on,sort_order) VALUES(?,?,?,?,?)')->execute([$periodId, ...$values]);
            audit('create', 'period_subperiod', (int) $pdo->lastInsertId());
            flash('Sous-période créée.');
        } else {
            $subperiodId = (int) $_POST['subperiod_id'];
            $pdo->prepare('UPDATE period_subperiods SET name=?,starts_on=?,ends_on=?,sort_order=? WHERE id=? AND period_id=?')->execute([...$values, $subperiodId, $periodId]);
            audit('update', 'period_subperiod', $subperiodId);
            flash('Sous-période mise à jour.');
        }
        redirect('index.php?page=structure&section=years');
    }

    if ($action === 'delete_subperiod') {
        $subperiodId = (int) $_POST['subperiod_id'];
        $pdo->prepare('DELETE FROM period_subperiods WHERE id=?')->execute([$subperiodId]);
        audit('delete', 'period_subperiod', $subperiodId);
        flash('Sous-période supprimée.');
        redirect('index.php?page=structure&section=years');
    }

    if ($action === 'update_class') {
        $level = trim($_POST['level']);
        $order = max(1, min(12, (int)$_POST['sort_order']));
        $stage = in_array($_POST['education_stage'] ?? '', ['preschool','primary','middle'], true) ? $_POST['education_stage'] : 'primary';
        $classId = (int) $_POST['class_id'];
        $principalIds = array_values(array_unique(array_filter(array_map('intval', $_POST['principal_user_ids'] ?? []))));
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE classes SET name=?,level=?,education_stage=?,sort_order=?,principal_user_id=? WHERE id=?')->execute([$level, $level, $stage, $order, $principalIds[0] ?? null, $classId]);
            $pdo->prepare('DELETE FROM class_principals WHERE class_id=?')->execute([$classId]);
            $statement = $pdo->prepare('INSERT INTO class_principals(class_id,user_id) VALUES(?,?)');
            foreach ($principalIds as $principalId) {
                $statement->execute([$classId, $principalId]);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
        flash('Niveau mis à jour.');
        redirect('index.php?page=structure&section=classes');
    }

    if ($action === 'delete_class') {
        $classId = (int)$_POST['class_id'];
        $class = one('SELECT id,level FROM classes WHERE id=?', [$classId]);
        if (!$class) {
            flash('Niveau introuvable.', 'error');
            redirect('index.php?page=structure&section=classes');
        }
        $counts = one('SELECT (SELECT COUNT(*) FROM enrollments WHERE class_id=?) students,(SELECT COUNT(*) FROM class_groups WHERE class_id=?) groups_count,(SELECT COUNT(*) FROM courses WHERE class_id=?) assignments_count', [$classId,$classId,$classId]);
        if ((int)$counts['students'] + (int)$counts['groups_count'] + (int)$counts['assignments_count'] > 0) {
            flash('Suppression impossible : retirez d’abord les élèves, groupes et affectations de ce niveau.', 'error');
            redirect('index.php?page=structure&section=classes');
        }
        $pdo->prepare('DELETE FROM classes WHERE id=?')->execute([$classId]);
        audit('delete', 'class', $classId, $class);
        flash('Niveau supprimé.');
        redirect('index.php?page=structure&section=classes');
    }

    if ($action === 'prepare_school_year') {
        $sourceYear = (int)$_POST['source_year_id'];
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE school_years SET active=0')->execute();
            $pdo->prepare('INSERT INTO school_years(name,starts_on,ends_on,active) VALUES(?,?,?,1)')->execute([trim($_POST['name']),$_POST['starts_on'],$_POST['ends_on']]);
            $newYear = (int)$pdo->lastInsertId();
            $oldClasses = rows('SELECT * FROM classes WHERE school_year_id=? ORDER BY sort_order,id', [$sourceYear]);
            $map = [];
            foreach ($oldClasses as $old) {
                $pdo->prepare('INSERT INTO classes(school_year_id,name,level,education_stage,sort_order,principal_user_id) VALUES(?,?,?,?,?,?)')->execute([$newYear,$old['level'],$old['level'],$old['education_stage'] ?? 'primary',$old['sort_order'],$old['principal_user_id']]);
                $map[(int)$old['id']] = (int)$pdo->lastInsertId();
                $classPrincipals = rows('SELECT user_id FROM class_principals WHERE class_id=?', [$old['id']]);
                $principalStatement = $pdo->prepare('INSERT INTO class_principals(class_id,user_id) VALUES(?,?)');
                foreach ($classPrincipals as $principal) {
                    $principalStatement->execute([$map[(int)$old['id']], $principal['user_id']]);
                }
            }
            foreach ($oldClasses as $index => $old) {
                $target = $oldClasses[$index + 1] ?? null;
                if (!$target) {
                    continue;
                }$students = rows('SELECT student_id FROM enrollments WHERE class_id=?', [$old['id']]);
                foreach ($students as $student) {
                    $pdo->prepare('INSERT IGNORE INTO enrollments(student_id,class_id) VALUES(?,?)')->execute([$student['student_id'],$map[(int)$target['id']]]);
                }
            }
            $groupMap = [];
            $oldGroups = rows('SELECT cg.* FROM class_groups cg JOIN classes c ON c.id=cg.class_id WHERE c.school_year_id=?', [$sourceYear]);
            foreach ($oldGroups as $group) {
                $pdo->prepare('INSERT INTO class_groups(class_id,name) VALUES(?,?)')->execute([$map[(int)$group['class_id']],$group['name']]);
                $groupMap[(int)$group['id']] = (int)$pdo->lastInsertId();
            }
            $oldAssignments = rows('SELECT * FROM courses WHERE class_id IN (SELECT id FROM classes WHERE school_year_id=?)', [$sourceYear]);
            foreach ($oldAssignments as $old) {
                $newGroup = $old['class_group_id'] ? ($groupMap[(int)$old['class_group_id']] ?? null) : null;
                $pdo->prepare('INSERT INTO courses(class_id,subject_id,group_name,class_group_id,assignment_coefficient,display_order) VALUES(?,?,NULL,?,?,?)')->execute([$map[(int)$old['class_id']],$old['subject_id'],$newGroup,$old['assignment_coefficient'],$old['display_order']]);
                $newAssignment = (int)$pdo->lastInsertId();
                $staff = rows('SELECT * FROM course_teachers WHERE course_id=?', [$old['id']]);
                foreach ($staff as $member) {
                    $pdo->prepare('INSERT INTO course_teachers(course_id,user_id,assignment_role,can_edit_shared) VALUES(?,?,?,?)')->execute([$newAssignment,$member['user_id'],$member['assignment_role'],$member['can_edit_shared']]);
                }
            }
            $pdo->commit();
            flash('Nouvelle année préparée, élèves promus et affectations recopiées.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        redirect('index.php?page=structure&section=years');
    }

    if ($action === 'update_user') {
        $id = (int)$_POST['user_id'];
        $before = one('SELECT id,name,email,role,can_teach,can_be_principal,active FROM users WHERE id=?', [$id]);
        $accountType = $_POST['account_type'] ?? 'staff';
        $isManagement = in_array($accountType, ['admin','direction'], true);
        $canTeach = $isManagement ? 0 : (isset($_POST['can_teach']) ? 1 : 0);
        $canPrincipal = $isManagement ? 0 : (isset($_POST['can_be_principal']) ? 1 : 0);
        $role = $accountType === 'admin' ? 'admin' : ($accountType === 'direction' ? 'direction' : ($canPrincipal ? 'principal' : 'teacher'));
        $firstName = trim($_POST['first_name']);
        $lastName = strtoupper(trim($_POST['last_name']));
        $displayName = trim($firstName.' '.$lastName);
        $identifier = strtolower(trim($_POST['login_identifier'] ?? $_POST['email'] ?? ''));
        $email = strtolower(trim($_POST['email'] ?? '')) ?: null;
        $stmt = $pdo->prepare('UPDATE users SET first_name=?,last_name=?,name=?,login_identifier=?,email=?,role=?,can_teach=?,can_be_principal=?,active=? WHERE id=?');
        $stmt->execute([$firstName, $lastName, $displayName, $identifier, $email, $role, $canTeach, $canPrincipal, isset($_POST['active']) ? 1 : 0, $id]);
        audit('update', 'user', $id, $before, ['role' => $role,'can_teach' => $canTeach,'can_be_principal' => $canPrincipal]);
        flash('Compte et qualifications mis à jour.');
        redirect('index.php?page=admin-management&tab=accounts');
    }

    if ($action === 'update_subject') {
        $id = (int)$_POST['subject_id'];
        $before = one('SELECT * FROM subjects WHERE id=?', [$id]);
        $pdo->prepare('UPDATE subjects SET name=?,coefficient=? WHERE id=?')->execute([trim($_POST['name']), max(.01, (float)$_POST['coefficient']), $id]);
        audit('update', 'subject', $id, $before, ['name' => trim($_POST['name']),'coefficient' => (float)$_POST['coefficient']]);
        flash('Matière et coefficient mis à jour.');
        redirect('index.php?page=structure&section=subjects');
    }

    if ($action === 'delete_subject') {
        $id = (int)$_POST['subject_id'];
        $before = one('SELECT * FROM subjects WHERE id=?', [$id]);
        if ((int)(one('SELECT COUNT(*) total FROM courses WHERE subject_id=?', [$id])['total'] ?? 0) > 0) {
            flash('Suppression impossible : cette matière est utilisée dans des affectations.', 'error');
            redirect('index.php?page=structure&section=subjects');
        }
        if ($before) {
            $pdo->prepare('DELETE FROM subjects WHERE id=?')->execute([$id]);
            audit('delete', 'subject', $id, $before);
            flash('Matière supprimée.');
        }
        redirect('index.php?page=structure&section=subjects');
    }

    if ($action === 'add_group') {
        $stmt = $pdo->prepare('INSERT INTO class_groups(class_id,name) VALUES(?,?)');
        $stmt->execute([(int)$_POST['class_id'], trim($_POST['name'])]);
        $groupId = (int)$pdo->lastInsertId();
        audit('create', 'class_group', $groupId);
        flash('Groupe créé.');
        redirect('index.php?page=structure&section=groups&group_id='.$groupId);
    }

    if ($action === 'update_group') {
        $id = (int)$_POST['group_id'];
        $before = one('SELECT * FROM class_groups WHERE id=?', [$id]);
        $pdo->prepare('UPDATE class_groups SET name=? WHERE id=?')->execute([trim($_POST['name']),$id]);
        audit('update', 'class_group', $id, $before, ['name' => trim($_POST['name'])]);
        flash('Groupe modifié.');
        redirect('index.php?page=structure&section=groups&group_id='.$id);
    }

    if ($action === 'delete_group') {
        $id = (int)$_POST['group_id'];
        $before = one('SELECT * FROM class_groups WHERE id=?', [$id]);
        if ((int)(one('SELECT COUNT(*) total FROM courses WHERE class_group_id=?', [$id])['total'] ?? 0) > 0) {
            flash('Suppression impossible : ce groupe est utilisé dans une affectation.', 'error');
            redirect('index.php?page=structure&section=groups&group_id='.$id);
        }
        if ($before) {
            $pdo->prepare('DELETE FROM class_groups WHERE id=?')->execute([$id]);
            audit('delete', 'class_group', $id, $before);
            flash('Groupe supprimé.');
        }
        redirect('index.php?page=structure&section=groups');
    }

    if ($action === 'save_group_students') {
        $groupId = (int)$_POST['group_id'];
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM group_students WHERE group_id=?')->execute([$groupId]);
            $stmt = $pdo->prepare('INSERT INTO group_students(group_id,student_id) VALUES(?,?)');
            foreach ($_POST['student_ids'] ?? [] as $studentId) {
                $stmt->execute([$groupId, (int)$studentId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        audit('update', 'group_students', $groupId);
        flash('Composition du groupe enregistrée.');
        redirect('index.php?page=structure&section=groups&group_id='.$groupId);
    }

    if ($action === 'update_course_group') {
        $courseId = (int)$_POST['course_id'];
        $groupId = $_POST['class_group_id'] !== '' ? (int)$_POST['class_group_id'] : null;
        $pdo->prepare('UPDATE courses SET class_group_id=? WHERE id=?')->execute([$groupId, $courseId]);
        audit('update', 'course', $courseId, null, ['class_group_id' => $groupId]);
        flash('Groupe du cours mis à jour.');
        redirect('index.php?page=admin-management&tab=groups');
    }

    if ($action === 'save_assignment') {
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        $classId = (int)$_POST['class_id'];
        $groupId = ($_POST['class_group_id'] ?? '') !== '' ? (int)$_POST['class_group_id'] : null;
        $subjectId = (int)$_POST['subject_id'];
        $leadTeacherId = (int)$_POST['lead_teacher_id'];
        $coTeacherId = ($_POST['co_teacher_id'] ?? '') !== '' ? (int)$_POST['co_teacher_id'] : null;
        $coefficient = max(0.01, (float)$_POST['assignment_coefficient']);
        $displayOrder = max(1, (int)$_POST['display_order']);
        if ($coTeacherId !== null && $coTeacherId === $leadTeacherId) {
            flash('Le professeur et le co-professeur doivent être différents.', 'error');
            redirect('index.php?page=structure&section=assignments&class_id='.$classId);
        }
        if ($groupId !== null && !one('SELECT id FROM class_groups WHERE id=? AND class_id=?', [$groupId,$classId])) {
            flash('Le groupe sélectionné ne correspond pas à la classe.', 'error');
            redirect('index.php?page=structure&section=assignments&class_id='.$classId);
        }
        $pdo->beginTransaction();
        try {
            if ($assignmentId > 0) {
                $pdo->prepare('UPDATE courses SET class_id=?,subject_id=?,group_name=NULL,class_group_id=?,assignment_coefficient=?,display_order=? WHERE id=?')->execute([$classId,$subjectId,$groupId,$coefficient,$displayOrder,$assignmentId]);
            } else {
                $pdo->prepare('INSERT INTO courses(class_id,subject_id,group_name,class_group_id,assignment_coefficient,display_order) VALUES(?,?,NULL,?,?,?)')->execute([$classId,$subjectId,$groupId,$coefficient,$displayOrder]);
                $assignmentId = (int)$pdo->lastInsertId();
            }
            $pdo->prepare('DELETE FROM course_teachers WHERE course_id=?')->execute([$assignmentId]);
            $teacherStatement = $pdo->prepare('INSERT INTO course_teachers(course_id,user_id,assignment_role,can_edit_shared) VALUES(?,?,?,1)');
            $teacherStatement->execute([$assignmentId,$leadTeacherId,'lead']);
            if ($coTeacherId !== null) {
                $teacherStatement->execute([$assignmentId,$coTeacherId,'co_teacher']);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        audit('update', 'teaching_assignment', $assignmentId, null, ['class_id' => $classId,'group_id' => $groupId,'subject_id' => $subjectId,'coefficient' => $coefficient,'display_order' => $displayOrder]);
        flash('Affectation pédagogique enregistrée.');
        redirect('index.php?page=structure&section=assignments&class_id='.$classId);
    }

    if ($action === 'bulk_save_assignments') {
        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        $classIds = array_values(array_unique(array_filter(array_map('intval', $_POST['class_ids'] ?? []))));
        $subjectIds = array_values(array_unique(array_filter(array_map('intval', $_POST['subject_ids'] ?? []))));
        if (!$teacherId || !$classIds || !$subjectIds) {
            flash('Choisissez un professeur, au moins un niveau et au moins une matière.', 'error');
            redirect('index.php?page=structure&section=assignments&class_id=0');
        }
        if (!one('SELECT id FROM users WHERE id=? AND can_teach=1 AND active=1', [$teacherId])) {
            flash('Le professeur sélectionné est invalide.', 'error');
            redirect('index.php?page=structure&section=assignments&class_id=0');
        }
        $created = 0;
        $skipped = 0;
        $pdo->beginTransaction();
        try {
            $find = $pdo->prepare('SELECT id FROM courses WHERE class_id=? AND subject_id=? AND class_group_id IS NULL LIMIT 1');
            $subject = $pdo->prepare('SELECT coefficient FROM subjects WHERE id=?');
            $insert = $pdo->prepare('INSERT INTO courses(class_id,subject_id,group_name,class_group_id,assignment_coefficient,display_order) VALUES(?,?,NULL,NULL,?,100)');
            $assign = $pdo->prepare("INSERT INTO course_teachers(course_id,user_id,assignment_role,can_edit_shared) VALUES(?,?,'lead',1)");
            foreach ($classIds as $classId) {
                if (!one('SELECT id FROM classes WHERE id=?', [$classId])) {
                    continue;
                }
                foreach ($subjectIds as $subjectId) {
                    $find->execute([$classId,$subjectId]);
                    if ($find->fetchColumn()) {
                        $skipped++;
                        continue;
                    }
                    $subject->execute([$subjectId]);
                    $coefficient = $subject->fetchColumn();
                    if ($coefficient === false) {
                        continue;
                    }
                    $insert->execute([$classId,$subjectId,$coefficient]);
                    $courseId = (int)$pdo->lastInsertId();
                    $assign->execute([$courseId,$teacherId]);
                    audit('create', 'teaching_assignment', $courseId, null, ['class_id' => $classId,'subject_id' => $subjectId,'teacher_id' => $teacherId,'bulk' => true]);
                    $created++;
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $message = $created.' affectation'.($created > 1 ? 's' : '').' créée'.($created > 1 ? 's' : '').'.';
        if ($skipped) {
            $message .= ' '.$skipped.' combinaison'.($skipped > 1 ? 's' : '').' déjà existante'.($skipped > 1 ? 's' : '').' ignorée'.($skipped > 1 ? 's' : '').'.';
        }
        flash($message);
        redirect('index.php?page=structure&section=assignments&class_id=0');
    }

    if ($action === 'delete_assignment') {
        $id = (int)$_POST['assignment_id'];
        $before = one('SELECT * FROM courses WHERE id=?', [$id]);
        $classId = (int)($before['class_id'] ?? 0);
        if ($before) {
            $pdo->prepare('DELETE FROM courses WHERE id=?')->execute([$id]);
            audit('delete', 'teaching_assignment', $id, $before);
            flash('Affectation et évaluations associées supprimées.');
        }
        redirect('index.php?page=structure&section=assignments&class_id='.$classId);
    }
}

function render_admin_management_page(): never
{
    require_role(['admin']);
    $tab = $_GET['tab'] ?? 'accounts';
    $users = rows('SELECT * FROM users ORDER BY name');
    $subjects = rows('SELECT * FROM subjects ORDER BY name');
    $classes = rows('SELECT c.*,y.name year_name FROM classes c JOIN school_years y ON y.id=c.school_year_id ORDER BY y.starts_on DESC,c.name');
    $groups = rows('SELECT cg.*,c.name class_name,COUNT(gs.student_id) student_count FROM class_groups cg JOIN classes c ON c.id=cg.class_id LEFT JOIN group_students gs ON gs.group_id=cg.id GROUP BY cg.id ORDER BY c.name,cg.name');
    $courses = rows('SELECT co.id,co.class_id,co.class_group_id,c.name class_name,s.name subject FROM courses co JOIN classes c ON c.id=co.class_id JOIN subjects s ON s.id=co.subject_id ORDER BY c.name,s.name');
    $selectedGroupId = (int)($_GET['group_id'] ?? 0);
    $selectedGroup = $selectedGroupId ? one('SELECT * FROM class_groups WHERE id=?', [$selectedGroupId]) : null;
    $groupStudents = $selectedGroup ? rows('SELECT s.id,s.last_name,s.first_name,IF(gs.student_id IS NULL,0,1) selected FROM enrollments e JOIN students s ON s.id=e.student_id LEFT JOIN group_students gs ON gs.student_id=s.id AND gs.group_id=? WHERE e.class_id=? AND s.active=1 ORDER BY s.last_name,s.first_name', [$selectedGroupId,$selectedGroup['class_id']]) : [];
    $title = match($tab) {
        'subjects' => 'Modifier les matières','groups' => 'Gérer les groupes',default => 'Modifier les comptes'
    };
    header_html($title);
    ?><link rel="stylesheet" href="assets/families.css"><link rel="stylesheet" href="assets/admin.css">
    <div class="tabs admin-tabs"><a class="button secondary" href="?page=<?=$tab === 'accounts' ? 'users' : 'structure'?>">← Retour à <?=$tab === 'accounts' ? 'Comptes' : 'Organisation'?></a></div>
    <?php if ($tab === 'accounts'): ?>
      <section class="panel"><p class="eyebrow">Accès et qualifications</p><h2>Modifier les comptes</h2><div class="admin-card-grid">
      <?php foreach ($users as $account): ?><form class="edit-card" method="post"><input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="update_user"><input type="hidden" name="user_id" value="<?=$account['id']?>"><div class="form-row"><label>Nom<input name="last_name" required value="<?=e($account['last_name'] ?: $account['name'])?>"></label><label>Prénom<input name="first_name" required value="<?=e($account['first_name'])?>"></label></div><label>E-mail<input name="email" type="email" required value="<?=e($account['email'])?>"></label><label>Statut / profil du compte<select name="account_type"><option value="staff" <?=!in_array($account['role'], ['admin','direction'], true) ? 'selected' : ''?>>Personnel pédagogique</option><option value="direction" <?=$account['role'] === 'direction' ? 'selected' : ''?>>Direction</option><option value="admin" <?=$account['role'] === 'admin' ? 'selected' : ''?>>Administrateur</option></select></label><?php if (!in_array($account['role'], ['admin','direction'], true)):?><div class="inline-checks"><label class="check"><input type="checkbox" name="can_teach" value="1" <?=$account['can_teach'] ? 'checked' : ''?>> Enseignant</label><label class="check"><input type="checkbox" name="can_be_principal" value="1" <?=$account['can_be_principal'] ? 'checked' : ''?>> Professeur principal / instituteur</label></div><?php endif?><label class="check"><input type="checkbox" name="active" value="1" <?=$account['active'] ? 'checked' : ''?>> Compte actif</label><button>Enregistrer</button></form><?php endforeach?></div></section>
    <?php elseif ($tab === 'subjects'): ?>
      <section class="panel"><p class="eyebrow">Référentiel</p><h2>Matières et coefficients</h2><div class="admin-card-grid"><?php foreach ($subjects as $subject):?><form class="edit-card compact" method="post"><input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="update_subject"><input type="hidden" name="subject_id" value="<?=$subject['id']?>"><label>Matière<input required name="name" value="<?=e($subject['name'])?>"></label><label>Coefficient<input required type="number" min="0.01" step="0.01" name="coefficient" value="<?=e($subject['coefficient'])?>"></label><button>Mettre à jour</button></form><?php endforeach?></div></section>
    <?php else: ?>
      <div class="two-col"><section><form class="panel" method="post"><h2>Créer un groupe</h2><input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="add_group"><label>Classe et niveau<select name="class_id"><?php foreach ($classes as $class):?><option value="<?=$class['id']?>"><?=e($class['name'].' · '.$class['level'].' · '.$class['year_name'])?></option><?php endforeach?></select></label><label>Nom du groupe<input required name="name" placeholder="Groupe A"></label><button>Créer</button></form><section class="panel"><h2>Groupes existants</h2><?php foreach ($groups as $group):?><a class="family-card <?=$selectedGroupId === $group['id'] ? 'selected' : ''?>" href="?page=admin-management&tab=groups&group_id=<?=$group['id']?>"><span><strong><?=e($group['name'])?></strong><small><?=e($group['class_name'])?></small></span><span><?=e($group['student_count'])?> élève(s)</span></a><?php endforeach?></section></section><section><?php if ($selectedGroup):?><form class="panel" method="post"><h2>Élèves — <?=e($selectedGroup['name'])?></h2><input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="save_group_students"><input type="hidden" name="group_id" value="<?=$selectedGroupId?>"><div class="student-checklist"><?php foreach ($groupStudents as $student):?><label class="check"><input type="checkbox" name="student_ids[]" value="<?=$student['id']?>" <?=$student['selected'] ? 'checked' : ''?>> <?=e($student['last_name'].' '.$student['first_name'])?></label><?php endforeach?></div><button>Enregistrer la composition</button></form><?php endif?><section class="panel"><h2>Affectations pédagogiques</h2><p>Après avoir composé les groupes, attribuez-leur une matière et un ou deux enseignants depuis le module Affectations.</p><a class="button secondary" href="?page=structure&section=assignments">Ouvrir les affectations</a></section></section></div>
    <?php endif;
    footer_html();
    exit;
}
