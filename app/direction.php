<?php
declare(strict_types=1);

function require_direction(): void
{
    require_login();
    if (!direction_mode_active() && !principal_mode_active()) {
        flash('Activez le mode Direction ou Professeur principal depuis le tableau de bord.', 'error');
        redirect('index.php?page=dashboard');
    }
}

function handle_direction_actions(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    $action = $_POST['action'] ?? '';
    if (!in_array($action, ['direction_validate_student','direction_validate_class','direction_unvalidate_student','direction_unvalidate_class'], true)) {
        return;
    }
    require_direction();
    $classId = (int)$_POST['class_id'];
    $periodId = (int)$_POST['period_id'];
    if (!can_manage_class($classId)) {
        http_response_code(403);
        exit('Accès interdit');
    }
    if ($action === 'direction_unvalidate_student') {
        $studentId = (int)$_POST['student_id'];
        db()->prepare('UPDATE councils SET validated_at=NULL,validated_by=NULL WHERE student_id=? AND class_id=? AND period_id=?')->execute([$studentId,$classId,$periodId]);
        audit('unlock', 'council', $studentId, null, ['class_id' => $classId,'period_id' => $periodId]);
        flash('Bulletin dévalidé et rouvert aux corrections.');
    } elseif ($action === 'direction_unvalidate_class') {
        db()->prepare('UPDATE councils SET validated_at=NULL,validated_by=NULL WHERE class_id=? AND period_id=?')->execute([$classId,$periodId]);
        audit('unlock_class', 'council', $classId, null, ['period_id' => $periodId]);
        flash('Tous les bulletins du niveau ont été dévalidés.');
    } elseif ($action === 'direction_validate_student') {
        $studentId = (int)$_POST['student_id'];
        db()->prepare('INSERT INTO councils(student_id,class_id,period_id,validated_at,validated_by) VALUES(?,?,?,NOW(),?) ON DUPLICATE KEY UPDATE validated_at=NOW(),validated_by=VALUES(validated_by)')->execute([$studentId,$classId,$periodId,user()['id']]);
        audit('validate', 'council', $studentId, null, ['class_id' => $classId,'period_id' => $periodId]);
        flash('Bulletin validé.');
    } else {
        $students = rows('SELECT s.id FROM enrollments e JOIN students s ON s.id=e.student_id WHERE e.class_id=? AND s.active=1', [$classId]);
        $stmt = db()->prepare('INSERT INTO councils(student_id,class_id,period_id,validated_at,validated_by) VALUES(?,?,?,NOW(),?) ON DUPLICATE KEY UPDATE validated_at=NOW(),validated_by=VALUES(validated_by)');
        foreach ($students as $student) {
            $stmt->execute([$student['id'],$classId,$periodId,user()['id']]);
            audit('validate', 'council', (int)$student['id'], null, ['class_id' => $classId,'period_id' => $periodId]);
        }
        flash(count($students).' bulletin(s) validé(s).');
    }
    redirect('index.php?page=documents&section=bulletins&class_id='.$classId.'&period_id='.$periodId);
}

function direction_subject_summary(int $studentId, int $classId, int $periodId): array
{
    return rows("SELECT s.name subject,co.assignment_coefficient,co.display_order,GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') teachers,ROUND(SUM((g.score/a.scale*20)*a.coefficient)/NULLIF(SUM(a.coefficient),0),2) average,MAX(sc.comment) comment FROM courses co JOIN subjects s ON s.id=co.subject_id LEFT JOIN course_teachers ct ON ct.course_id=co.id LEFT JOIN users u ON u.id=ct.user_id LEFT JOIN assessments a ON a.course_id=co.id AND a.period_id=? LEFT JOIN grades g ON g.assessment_id=a.id AND g.student_id=? AND g.special_status IS NULL LEFT JOIN subject_comments sc ON sc.course_id=co.id AND sc.period_id=? AND sc.student_id=? WHERE co.class_id=? AND (co.class_group_id IS NULL OR EXISTS(SELECT 1 FROM group_students gs WHERE gs.group_id=co.class_group_id AND gs.student_id=?)) GROUP BY co.id,s.name,co.assignment_coefficient,co.display_order ORDER BY co.display_order,s.name", [$periodId,$studentId,$periodId,$studentId,$classId,$studentId]);
}

function render_direction_page(): never
{
    require_direction();
    $classes = array_values(array_filter(rows('SELECT c.id,c.name,c.level,y.name year_name FROM classes c JOIN school_years y ON y.id=c.school_year_id ORDER BY y.starts_on DESC,c.level,c.name'), fn ($class) => can_manage_class((int)$class['id'])));
    $periods = rows('SELECT p.id,p.name,y.name year_name FROM periods p JOIN school_years y ON y.id=p.school_year_id ORDER BY y.starts_on DESC,p.sort_order');
    $classId = (int)($_GET['class_id'] ?? ($classes[0]['id'] ?? 0));
    $periodId = (int)($_GET['period_id'] ?? ($periods[0]['id'] ?? 0));
    $students = $classId && $periodId ? rows('SELECT s.id,s.last_name,s.first_name,c.validated_at,c.mention,c.decision_text FROM enrollments e JOIN students s ON s.id=e.student_id LEFT JOIN councils c ON c.student_id=s.id AND c.class_id=e.class_id AND c.period_id=? WHERE e.class_id=? AND s.active=1 ORDER BY s.last_name,s.first_name', [$periodId,$classId]) : [];
    $validated = count(array_filter($students, fn ($s) => !empty($s['validated_at'])));
    header_html('Direction — Bulletins');
    ?><link rel="stylesheet" href="assets/direction.css">
    <section class="panel direction-filters"><form method="get"><input type="hidden" name="page" value="direction"><label>Niveau<select name="class_id" onchange="this.form.submit()"><?php foreach ($classes as $class):?><option value="<?=$class['id']?>" <?=$classId === $class['id'] ? 'selected' : ''?>><?=e($class['level'].' · '.$class['year_name'])?></option><?php endforeach?></select></label><label>Période<select name="period_id" onchange="this.form.submit()"><?php foreach ($periods as $period):?><option value="<?=$period['id']?>" <?=$periodId === $period['id'] ? 'selected' : ''?>><?=e($period['year_name'].' · '.$period['name'])?></option><?php endforeach?></select></label></form><div class="validation-count"><strong><?=$validated?> / <?=count($students)?></strong><span>bulletins validés</span></div></section>
    <section class="panel"><div class="panel-head"><div><p class="eyebrow">Contrôle et publication</p><h2>Bulletins de la classe</h2></div><div class="direction-actions"><a class="button secondary" href="?page=bulletin-batch&class_id=<?=$classId?>&period_id=<?=$periodId?>">Exporter / imprimer la classe</a><form method="post" onsubmit="return confirm('Valider tous les bulletins de cette classe ?');"><input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="direction_validate_class"><input type="hidden" name="class_id" value="<?=$classId?>"><input type="hidden" name="period_id" value="<?=$periodId?>"><button>Tout valider</button></form></div></div><div class="table-wrap"><table><thead><tr><th>Élève</th><th>Mention / décision</th><th>État</th><th>Documents</th><th>Validation</th></tr></thead><tbody><?php foreach ($students as $student):?><tr><td><strong><?=e($student['last_name'].' '.$student['first_name'])?></strong></td><td><?=e($student['mention'] ?: '—')?><small><?=e($student['decision_text'] ?: '')?></small></td><td><span class="tag <?=$student['validated_at'] ? 'validated' : 'pending'?>"><?=$student['validated_at'] ? 'Validé' : 'À valider'?></span></td><td><div class="document-links"><a href="?page=bulletin&class_id=<?=$classId?>&period_id=<?=$periodId?>&student_id=<?=$student['id']?>">Bulletin</a><a href="?page=report&class_id=<?=$classId?>&period_id=<?=$periodId?>&student_id=<?=$student['id']?>">Relevé</a></div></td><td><?php if (!$student['validated_at']):?><form method="post"><input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="direction_validate_student"><input type="hidden" name="student_id" value="<?=$student['id']?>"><input type="hidden" name="class_id" value="<?=$classId?>"><input type="hidden" name="period_id" value="<?=$periodId?>"><button>Valider</button></form><?php else:?><small><?=date('d/m/Y H:i', strtotime($student['validated_at']))?></small><?php endif?></td></tr><?php endforeach?></tbody></table></div></section>
    <?php footer_html();
    exit;
}

function render_batch_bulletins(): never
{
    document_no_cache_headers();
    require_direction();
    $classId = (int)($_GET['class_id'] ?? 0);
    $periodId = (int)($_GET['period_id'] ?? 0);
    if (!can_manage_class($classId)) {
        http_response_code(403);
        exit('Accès interdit');
    }
    $class = one('SELECT c.*,y.name year_name FROM classes c JOIN school_years y ON y.id=c.school_year_id WHERE c.id=?', [$classId]);
    $period = one('SELECT * FROM periods WHERE id=?', [$periodId]);
    if (!$class || !$period) {
        http_response_code(404);
        exit('Bulletins introuvables');
    }
    $students = rows('SELECT s.id FROM enrollments e JOIN students s ON s.id=e.student_id WHERE e.class_id=? AND s.active=1 ORDER BY s.last_name,s.first_name', [$classId]);
    ?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Bulletins — <?=e($class['level'])?></title><link rel="stylesheet" href="assets/app.css?v=70"><link rel="stylesheet" href="assets/branding.css?v=84"><link rel="stylesheet" href="assets/direction.css?v=70"></head><body class="document-body"><div class="print-actions"><a class="button secondary" href="?page=documents&amp;section=bulletins&amp;class_id=<?=$classId?>&amp;period_id=<?=$periodId?>">Retour</a><button onclick="window.print()">Enregistrer le PDF du niveau / Imprimer</button></div><?php foreach ($students as $item):$student = document_student((int)$item['id'], $classId);
        if ($student) {
            render_bulletin_article($student, $period, $classId, true);
        }endforeach?></body></html><?php exit;
}
