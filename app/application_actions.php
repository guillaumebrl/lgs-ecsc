<?php

declare(strict_types=1);

function handle_work_mode_action(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'set_work_modes') {
        return;
    }

    $allowed = [];
    if (has_capability('can_teach')) {
        $allowed[] = 'teacher';
    }
    if (has_capability('can_be_principal')) {
        $allowed[] = 'principal';
    }
    if (has_capability('can_direction')) {
        $allowed[] = 'direction';
    }

    $mode = $_POST['work_mode'] ?? 'teacher';
    if (!in_array($mode, $allowed, true)) {
        http_response_code(403);
        exit('Mode interdit');
    }

    $_SESSION['work_mode'] = $mode;
    unset($_SESSION['work_mode_direction'], $_SESSION['work_mode_principal']);
    flash('Mode de travail mis à jour.');
    redirect('index.php?page=dashboard');
}

function handle_application_actions(array $settings): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $action = $_POST['action'] ?? '';
    $pdo = db();

    if ($action === 'save_settings') {
        require_role(['admin']);
        $pdo->prepare('UPDATE settings SET school_name=?,school_address=?,grading_mode=?,decimals=?,comment_max_length=? WHERE id=1')->execute([
            trim($_POST['school_name']), trim($_POST['school_address']), $_POST['grading_mode'],
            max(0, min(3, (int) $_POST['decimals'])), max(50, min(2000, (int) ($_POST['comment_max_length'] ?? 500))),
        ]);
        audit('update', 'settings', 1, null, $_POST);
        flash('Paramètres enregistrés.');
        redirect('index.php?page=settings');
    }

    if ($action === 'add_period') {
        require_management_mode();
        $status = in_array($_POST['status'] ?? '', ['open', 'closed'], true) ? $_POST['status'] : 'closed';
        $statement = $pdo->prepare('INSERT INTO periods(school_year_id,name,starts_on,ends_on,entry_opens_on,entry_closes_on,status,sort_order) VALUES(?,?,?,?,?,?,?,?)');
        $statement->execute([(int) $_POST['school_year_id'], trim($_POST['name']), $_POST['starts_on'], $_POST['ends_on'], $_POST['entry_opens_on'] ?: null, $_POST['entry_closes_on'] ?: null, $status, (int) $_POST['sort_order']]);
        audit('create', 'period', (int) $pdo->lastInsertId());
        flash('Période créée.');
        redirect('index.php?page=structure&section=years');
    }

    if ($action === 'add_class') {
        require_management_mode();
        $level = trim($_POST['level']);
        $stage = in_array($_POST['education_stage'] ?? '', ['preschool', 'primary', 'middle'], true) ? $_POST['education_stage'] : 'primary';
        $principalIds = array_values(array_unique(array_filter(array_map('intval', $_POST['principal_user_ids'] ?? []))));
        $statement = $pdo->prepare('INSERT INTO classes(school_year_id,name,level,education_stage,sort_order,principal_user_id) VALUES(?,?,?,?,?,?)');
        $statement->execute([(int) $_POST['school_year_id'], $level, $level, $stage, max(1, min(12, (int) ($_POST['sort_order'] ?? 1))), $principalIds[0] ?? null]);
        $classId = (int) $pdo->lastInsertId();
        $principalStatement = $pdo->prepare('INSERT IGNORE INTO class_principals(class_id,user_id) VALUES(?,?)');
        foreach ($principalIds as $principalId) {
            $principalStatement->execute([$classId, $principalId]);
        }
        audit('create', 'class', $classId);
        flash('Niveau créé.');
        redirect('index.php?page=structure&section=classes');
    }

    if ($action === 'add_subject') {
        require_management_mode();
        $statement = $pdo->prepare('INSERT INTO subjects(name,coefficient) VALUES(?,?)');
        $statement->execute([trim($_POST['name']), (float) $_POST['coefficient']]);
        audit('create', 'subject', (int) $pdo->lastInsertId());
        flash('Matière créée.');
        redirect('index.php?page=structure&section=subjects');
    }

    if ($action === 'add_student') {
        require_management_mode();
        $pdo->beginTransaction();
        try {
            $ine = trim($_POST['registration_number'] ?? '');
            $statement = $pdo->prepare('INSERT INTO students(registration_number,last_name,first_name,birth_date) VALUES(?,?,?,?)');
            $statement->execute([$ine !== '' ? $ine : null, strtoupper(trim($_POST['last_name'])), trim($_POST['first_name']), ($_POST['birth_date'] ?? '') ?: null]);
            $studentId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO enrollments(student_id,class_id) VALUES(?,?)')->execute([$studentId, (int) $_POST['class_id']]);
            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
        audit('create', 'student', $studentId);
        flash('Élève inscrit.');
        redirect('index.php?page=students');
    }

    if ($action === 'add_assessment') {
        $course = one('SELECT co.*,p.status period_status FROM courses co JOIN periods p ON p.id=? WHERE co.id=?', [(int) $_POST['period_id'], (int) $_POST['course_id']]);
        if (!$course || !can_create_assessment_for_course((int) $course['id'])) {
            http_response_code(403);
            exit('Accès interdit');
        }
        if ($course['period_status'] !== 'open') {
            flash('Les évaluations ne peuvent être créées que dans un trimestre ouvert.', 'error');
            redirect('index.php?page=grades');
        }
        $scale = $settings['grading_mode'] === 'forced_20' ? 20 : (float) $_POST['scale'];
        $normalize = $settings['grading_mode'] === 'forced_20' ? '20' : $_POST['normalize_to'];
        $statement = $pdo->prepare('INSERT INTO assessments(course_id,period_id,author_id,title,assessment_date,scale,normalize_to,coefficient) VALUES(?,?,?,?,?,?,?,?)');
        $statement->execute([(int) $_POST['course_id'], (int) $_POST['period_id'], user()['id'], trim($_POST['title']), $_POST['assessment_date'], $scale, $normalize, (float) $_POST['coefficient']]);
        $assessmentId = (int) $pdo->lastInsertId();
        audit('create', 'assessment', $assessmentId);
        flash('Évaluation créée. Saisissez maintenant les notes.');
        redirect('index.php?page=gradebook&id=' . $assessmentId);
    }

    if ($action === 'save_grades') {
        $assessment = one('SELECT a.*,p.status period_status FROM assessments a JOIN periods p ON p.id=a.period_id WHERE a.id=?', [(int) $_POST['assessment_id']]);
        if (!$assessment || !can_manage_course((int) $assessment['course_id'])) {
            http_response_code(403);
            exit('Accès interdit');
        }
        if ($assessment['period_status'] !== 'open') {
            flash('Période clôturée.', 'error');
            $returnTo = (string)($_POST['return_to'] ?? '');
            redirect('index.php?page=gradebook&id='.$assessment['id'].($returnTo !== '' ? '&return_to='.rawurlencode($returnTo) : ''));
        }
        $statement = $pdo->prepare('INSERT INTO grades(assessment_id,student_id,score,special_status,updated_by) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE score=VALUES(score),special_status=VALUES(special_status),updated_by=VALUES(updated_by)');
        foreach ($_POST['students'] ?? [] as $studentId => $entry) {
            $status = $entry['status'] ?: null;
            $score = $status ? null : ($entry['score'] === '' ? null : (float) $entry['score']);
            if ($score !== null && ($score < 0 || $score > (float) $assessment['scale'])) {
                continue;
            }
            $statement->execute([$assessment['id'], (int) $studentId, $score, $status, user()['id']]);
        }
        audit('bulk_update', 'grades', (int) $assessment['id']);
        flash('Notes enregistrées.');
        $returnTo = (string)($_POST['return_to'] ?? '');
        redirect('index.php?page=gradebook&id='.$assessment['id'].($returnTo !== '' ? '&return_to='.rawurlencode($returnTo) : ''));
    }

    if (in_array($action, ['save_council', 'validate_council', 'unlock_council'], true)) {
        handle_council_action($action);
    }
}

function handle_council_action(string $action): never
{
    $classId = (int) $_POST['class_id'];
    $studentId = (int) $_POST['student_id'];
    $periodId = (int) $_POST['period_id'];
    if (!can_manage_council_scope($classId, $studentId, $periodId)) {
        http_response_code(403);
        exit('Accès interdit');
    }
    $url = 'index.php?page=council&class_id=' . $classId . '&period_id=' . $periodId . '&student_id=' . $studentId;
    if ($action === 'save_council') {
        $existing = one('SELECT * FROM councils WHERE student_id=? AND class_id=? AND period_id=?', [$studentId, $classId, $periodId]);
        if ($existing && $existing['validated_at'] && !is_admin()) {
            flash('Ce bulletin est verrouillé.', 'error');
            redirect($url);
        }
        db()->prepare('INSERT INTO councils(student_id,class_id,period_id,general_comment,mention,decision_text) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE general_comment=VALUES(general_comment),mention=VALUES(mention),decision_text=VALUES(decision_text)')->execute([$studentId, $classId, $periodId, trim($_POST['general_comment']), trim($_POST['mention']), trim($_POST['decision_text'])]);
        audit('update', 'council', $studentId, $existing, $_POST);
        flash('Décision enregistrée.');
    } elseif ($action === 'validate_council') {
        db()->prepare('INSERT INTO councils(validated_by,student_id,class_id,period_id,validated_at) VALUES(?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE validated_at=NOW(),validated_by=VALUES(validated_by)')->execute([user()['id'], $studentId, $classId, $periodId]);
        audit('validate', 'council', $studentId);
        flash('Bulletin validé. Il peut être déverrouillé si une correction est nécessaire.');
    } else {
        db()->prepare('UPDATE councils SET validated_at=NULL,validated_by=NULL WHERE student_id=? AND class_id=? AND period_id=?')->execute([$studentId, $classId, $periodId]);
        audit('unlock', 'council', $studentId, null, ['class_id' => $classId, 'period_id' => $periodId]);
        flash('Bulletin déverrouillé pour correction.');
    }
    redirect($url);
}
