<?php
declare(strict_types=1);

function grade_notebook_average(array $notes): ?float
{
    $total = 0.0;
    $coefficients = 0.0;
    foreach ($notes as $note) {
        if ($note['score'] === null || !empty($note['special_status']) || (float)$note['scale'] <= 0) {
            continue;
        }
        $coefficient = max(0.0, (float)$note['coefficient']);
        $total += ((float)$note['score'] / (float)$note['scale'] * 20) * $coefficient;
        $coefficients += $coefficient;
    }
    return $coefficients > 0 ? $total / $coefficients : null;
}

function render_grade_notebook_page(): never
{
    require_login();
    global $settings;
    if (!has_capability('can_teach') && !has_capability('can_be_principal') && !direction_mode_active()) {
        http_response_code(403);
        exit('Accès interdit');
    }
    $uid = (int)user()['id'];
    $view = ($_GET['view'] ?? 'detailed') === 'compact' ? 'compact' : 'detailed';
    $notebookMode = is_admin() && ($_GET['notebook_mode'] ?? 'principal') === 'teacher' ? 'teacher' : 'principal';
    $allCourses = rows('SELECT co.id,co.class_id,co.subject_id,co.class_group_id,c.level,c.sort_order,s.name subject,cg.name group_name,y.name year_name,y.active year_active FROM courses co JOIN classes c ON c.id=co.class_id JOIN school_years y ON y.id=c.school_year_id JOIN subjects s ON s.id=co.subject_id LEFT JOIN class_groups cg ON cg.id=co.class_group_id WHERE y.active=1 ORDER BY c.sort_order,s.name,cg.name,co.id');
    $accessibleCourses = array_values(array_filter($allCourses, fn ($course) => can_manage_course((int)$course['id'])));
    $courses = [];
    foreach ($accessibleCourses as $item) {
        $key = $item['class_id'].'|'.$item['subject_id'].'|'.($item['class_group_id'] ?? 'class');
        if (!isset($courses[$key])) {
            $item['course_ids'] = [];
            $courses[$key] = $item;
        }
        $courses[$key]['course_ids'][] = (int)$item['id'];
    }
    $courses = array_values($courses);
    $teachings = [];
    foreach ($courses as $item) {
        $key = $item['class_id'].'|'.$item['subject_id'];
        $teachings[$key] ??= ['key' => $key, 'class_id' => (int)$item['class_id'], 'subject_id' => (int)$item['subject_id'], 'label' => $item['level'].' · '.$item['subject']];
    }
    $requestedCourseId = (int)($_GET['course_id'] ?? 0);
    $requestedCourse = current(array_filter($courses, fn (array $item): bool => in_array($requestedCourseId, $item['course_ids'], true))) ?: null;
    $defaultTeachingKey = $requestedCourse ? $requestedCourse['class_id'].'|'.$requestedCourse['subject_id'] : (array_key_first($teachings) ?? '');
    $teachingKey = (string)($_GET['teaching'] ?? $defaultTeachingKey);
    if (!isset($teachings[$teachingKey])) {
        $teachingKey = (string)(array_key_first($teachings) ?? '');
    }
    $publicCourses = array_values(array_filter($courses, fn (array $item): bool => $item['class_id'].'|'.$item['subject_id'] === $teachingKey));
    $course = $publicCourses[0] ?? null;
    $courseId = (int)($course['id'] ?? 0);
    if ($course) {
        $course['course_ids'] = [];
        $course['class_group_ids'] = [];
        foreach ($publicCourses as $publicCourse) {
            $course['course_ids'] = [...$course['course_ids'], ...$publicCourse['course_ids']];
            if ($publicCourse['class_group_id'] !== null) {
                $course['class_group_ids'][] = (int)$publicCourse['class_group_id'];
            }
        }
        $course['course_ids'] = array_values(array_unique($course['course_ids']));
        $course['class_group_ids'] = array_values(array_unique($course['class_group_ids']));
        $course['whole_class'] = (bool)array_filter($publicCourses, fn (array $item): bool => $item['class_group_id'] === null);
    }
    $classId = (int)($course['class_id'] ?? 0);
    $requestedClassId = (int)($_GET['class_id'] ?? 0);
    if ($requestedClassId && can_manage_class($requestedClassId)) {
        $classId = $requestedClassId;
    }
    $periods = $classId ? rows('SELECT p.id,p.name,p.starts_on,p.ends_on FROM periods p JOIN classes c ON c.school_year_id=p.school_year_id WHERE c.id=? ORDER BY p.sort_order', [$classId]) : [];
    $periodChoice = (string)($_GET['period'] ?? $_GET['period_id'] ?? ($periods[0]['id'] ?? ''));
    $customPeriod = $periodChoice === 'other';
    $periodId = $customPeriod ? 0 : (int)$periodChoice;
    if (!$customPeriod && !array_filter($periods, fn ($period) => (int)$period['id'] === $periodId)) {
        $periodId = (int)($periods[0]['id'] ?? 0);
        $periodChoice = (string)$periodId;
    }
    $selectedPeriod = current(array_filter($periods, fn ($period) => (int)$period['id'] === $periodId)) ?: null;
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : ($selectedPeriod['starts_on'] ?? date('Y-m-01'));
    $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : ($selectedPeriod['ends_on'] ?? date('Y-m-d'));
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    $responsible = $classId && can_manage_class($classId) && (!is_admin() || $notebookMode === 'principal');
    if ($responsible) {
        render_responsible_grade_notebook($classId, $periods, $periodChoice, $periodId, $from, $to, $view, $notebookMode);
    }
    $groupIds = $course['class_group_ids'] ?? [];
    $groupPlaceholders = implode(',', array_fill(0, count($groupIds), '?'));
    $students = $course ? rows(
        'SELECT DISTINCT s.id,s.last_name,s.first_name,s.registration_number FROM enrollments e JOIN students s ON s.id=e.student_id WHERE e.class_id=? AND s.active=1'.
        (!$course['whole_class'] && $groupIds ? ' AND EXISTS(SELECT 1 FROM group_students gs WHERE gs.student_id=s.id AND gs.group_id IN ('.$groupPlaceholders.'))' : '').
        ' ORDER BY s.last_name,s.first_name',
        [$classId, ...(!$course['whole_class'] ? $groupIds : [])]
    ) : [];
    $courseIds = $course['course_ids'] ?? [];
    $coursePlaceholders = implode(',', array_fill(0, count($courseIds), '?'));
    $notes = $course ? rows('SELECT g.student_id,a.id assessment_id,a.title,a.assessment_date,a.scale,a.normalize_to,a.coefficient,g.score,g.special_status,a.author_id,sub.name subject,u.name author FROM assessments a JOIN courses co ON co.id=a.course_id JOIN subjects sub ON sub.id=co.subject_id JOIN users u ON u.id=a.author_id JOIN grades g ON g.assessment_id=a.id WHERE a.course_id IN ('.$coursePlaceholders.') AND '.($customPeriod ? 'a.assessment_date BETWEEN ? AND ?' : 'a.period_id=?').' ORDER BY a.assessment_date,a.id', [...$courseIds,...($customPeriod ? [$from,$to] : [$periodId])]) : [];
    $byStudent = [];
    foreach ($notes as $note) {
        $byStudent[(int)$note['student_id']][] = $note;
    }
    $comments = $course && !$customPeriod && $periodId ? rows('SELECT student_id,comment FROM subject_comments WHERE course_id IN ('.$coursePlaceholders.') AND period_id=?', [...$courseIds,$periodId]) : [];
    $commentsByStudent = [];
    foreach ($comments as $comment) {
        if (trim((string)$comment['comment']) !== '') {
            $commentsByStudent[(int)$comment['student_id']] = $comment['comment'];
        }
    }
    header_html('Carnet de notes');?>
    <nav class="module-tabs"><a class="button secondary" href="?page=grades">Évaluations et notes</a><a class="button" href="?page=grade-notebook">Carnet de notes</a><a class="button secondary" href="?page=comments">Appréciations</a></nav>
    <?php render_notebook_mode_switch($notebookMode); ?>
    <?php render_notebook_period_data($periods); ?>
    <section class="panel"><div class="panel-head"><div><p class="eyebrow">Consultation par élève</p><h2>Notes de l’enseignement sélectionné</h2></div><div class="module-tabs"><a class="button <?=$view === 'detailed' ? '' : 'secondary'?>" href="?page=grade-notebook&amp;teaching=<?=e(rawurlencode($teachingKey))?>&amp;period=<?=e($periodChoice)?>&amp;view=detailed">Vue détaillée</a><a class="button <?=$view === 'compact' ? '' : 'secondary'?>" href="?page=grade-notebook&amp;teaching=<?=e(rawurlencode($teachingKey))?>&amp;period=<?=e($periodChoice)?>&amp;view=compact">Vue compacte</a></div></div><form method="get" class="notebook-filters"><input type="hidden" name="page" value="grade-notebook"><input type="hidden" name="view" value="<?=e($view)?>"><label>Niveau · matière<select name="teaching" <?=$teachings ? '' : 'disabled'?>><?php foreach ($teachings as $item):?><option value="<?=e($item['key'])?>" <?=$teachingKey === $item['key'] ? 'selected' : ''?>><?=e($item['label'])?></option><?php endforeach?></select></label><label>Période<select name="period"><?php foreach ($periods as $period):?><option value="<?=$period['id']?>" <?=$periodChoice === (string)$period['id'] ? 'selected' : ''?>><?=e($period['name'])?></option><?php endforeach?><option value="other" <?=$customPeriod ? 'selected' : ''?>>Autre</option></select></label><label>Date de début<input type="date" name="from" value="<?=e($from)?>"></label><label>Date de fin<input type="date" name="to" value="<?=e($to)?>"></label><button>Afficher</button></form><?php if (!$courses):?><p class="empty">Aucun enseignement accessible. Vérifiez les affectations de ce professeur ou co-enseignant.</p><?php endif?></section>
    <section class="panel"><div class="table-wrap"><table class="notebook-table <?=$view === 'compact' ? 'notebook-table-compact' : ''?>"><thead><tr><th>Élève</th><th>Notes enregistrées</th><th>Moyenne</th><th>Appréciation</th><?php if ($responsible):?><th>Bulletin complet</th><?php endif?></tr></thead><tbody><?php foreach ($students as $student):$studentNotes = $byStudent[(int)$student['id']] ?? [];
        $average = grade_notebook_average($studentNotes);?><tr><td><strong><?=e($student['last_name'].' '.$student['first_name'])?></strong><small>N° INE <?=e($student['registration_number'] ?: 'en attente')?></small></td><td><div class="notebook-grades"><?php foreach ($studentNotes as $note):?><div class="notebook-grade"><span><strong><?=e($note['subject'])?></strong> — <a href="?page=gradebook&amp;id=<?=$note['assessment_id']?>"><?=e($note['title'])?></a><small><?=date('d/m/Y', strtotime($note['assessment_date']))?> · <?=e($note['author'])?></small></span><span class="score"><?=$note['special_status'] ? e($note['special_status']) : ($note['score'] !== null ? e(display_decimal($note['score']).' / '.display_decimal($note['scale'])) : '—')?></span></div><?php endforeach?><?php if (!$studentNotes):?><span class="muted">Aucune note saisie.</span><?php endif?></div></td><td class="score"><?=$average !== null ? e(display_decimal($average, (int)$settings['decimals'])).' / 20' : '—'?></td><td class="notebook-comment"><?=e($commentsByStudent[(int)$student['id']] ?? '—')?></td><?php if ($responsible):?><td><a class="button secondary compact" href="?page=bulletin&amp;class_id=<?=$classId?>&amp;period_id=<?=$periodId?>&amp;student_id=<?=$student['id']?>">Voir le bulletin</a></td><?php endif?></tr><?php endforeach?><?php if (!$students):?><tr><td colspan="<?=$responsible ? 5 : 4?>" class="empty">Aucun élève pour cette sélection.</td></tr><?php endif?></tbody></table></div></section>
    <?php footer_html();
    exit;
}

function render_responsible_grade_notebook(int $classId, array $periods, string $periodChoice, int $periodId, string $from, string $to, string $view, string $notebookMode): never
{
    global $settings;
    $classes = array_values(array_filter(
        rows('SELECT c.id,c.level FROM classes c JOIN school_years y ON y.id=c.school_year_id WHERE y.active=1 ORDER BY c.sort_order'),
        fn (array $class): bool => can_manage_class((int)$class['id'])
    ));
    $students = rows('SELECT s.id,s.last_name,s.first_name,s.registration_number FROM enrollments e JOIN students s ON s.id=e.student_id WHERE e.class_id=? AND s.active=1 ORDER BY s.last_name,s.first_name', [$classId]);
    $studentId = (int)($_GET['student_id'] ?? ($students[0]['id'] ?? 0));
    if ($studentId && !array_filter($students, fn (array $student): bool => (int)$student['id'] === $studentId)) {
        $studentId = (int)($students[0]['id'] ?? 0);
    }
    $customPeriod = $periodChoice === 'other';
    $periodCondition = $customPeriod ? 'a.assessment_date BETWEEN ? AND ?' : 'a.period_id=?';
    $periodParameters = $customPeriod ? [$from, $to] : [$periodId];
    $rows = $studentId ? rows(
        "SELECT co.id course_id,s.name subject,co.display_order,
                GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') teachers,
                a.id assessment_id,a.title,a.assessment_date,a.scale,a.coefficient,
                g.score,g.special_status
         FROM courses co JOIN subjects s ON s.id=co.subject_id
         LEFT JOIN course_teachers ct ON ct.course_id=co.id LEFT JOIN users u ON u.id=ct.user_id
         LEFT JOIN assessments a ON a.course_id=co.id AND $periodCondition
         LEFT JOIN grades g ON g.assessment_id=a.id AND g.student_id=?
         WHERE co.class_id=? AND (co.class_group_id IS NULL OR EXISTS(
             SELECT 1 FROM group_students gs WHERE gs.group_id=co.class_group_id AND gs.student_id=?
         ))
         GROUP BY co.id,s.name,co.display_order,a.id,a.title,a.assessment_date,a.scale,a.coefficient,g.score,g.special_status
         ORDER BY co.display_order,s.name,a.assessment_date,a.id",
        [...$periodParameters, $studentId, $classId, $studentId]
    ) : [];
    $subjects = [];
    foreach ($rows as $row) {
        $key = (int)$row['course_id'];
        $subjects[$key] ??= ['subject' => $row['subject'], 'teachers' => $row['teachers'], 'notes' => []];
        if ($row['assessment_id'] !== null) {
            $subjects[$key]['notes'][] = $row;
        }
    }
    $assessmentIds = [];
    foreach ($subjects as $subject) {
        foreach ($subject['notes'] as $note) {
            $assessmentIds[] = (int)$note['assessment_id'];
        }
    }
    header_html('Carnet de notes'); ?>
    <nav class="module-tabs"><a class="button secondary" href="?page=grades">Évaluations et notes</a><a class="button" href="?page=grade-notebook">Carnet de notes</a><a class="button secondary" href="?page=comments">Appréciations</a></nav>
    <?php render_notebook_mode_switch($notebookMode); ?>
    <?php render_notebook_period_data($periods); ?>
    <span class="responsible-assessment-links" data-assessment-ids="<?=e(json_encode($assessmentIds))?>" hidden></span>
    <section class="panel"><div class="panel-head"><div><p class="eyebrow">Vue professeur principal / instituteur</p><h2>Toutes les matières d’un élève</h2></div></div>
      <form method="get" class="notebook-filters"><input type="hidden" name="page" value="grade-notebook"><input type="hidden" name="view" value="<?=e($view)?>"><label>Niveau<select name="class_id"><?php foreach ($classes as $class):?><option value="<?=$class['id']?>" <?=$classId === (int)$class['id'] ? 'selected' : ''?>><?=e($class['level'])?></option><?php endforeach?></select></label><label>Élève<select name="student_id"><?php foreach ($students as $student):?><option value="<?=$student['id']?>" <?=$studentId === (int)$student['id'] ? 'selected' : ''?>><?=e($student['last_name'].' '.$student['first_name'])?></option><?php endforeach?></select></label><label>Période<select name="period"><?php foreach ($periods as $period):?><option value="<?=$period['id']?>" <?=$periodChoice === (string)$period['id'] ? 'selected' : ''?>><?=e($period['name'])?></option><?php endforeach?><option value="other" <?=$customPeriod ? 'selected' : ''?>>Autre</option></select></label><label>Date de début<input type="date" name="from" value="<?=e($from)?>"></label><label>Date de fin<input type="date" name="to" value="<?=e($to)?>"></label><button>Afficher</button></form>
    </section>
    <section class="panel"><div class="table-wrap"><table class="responsible-notebook-table"><thead><tr><th>Matière / enseignant</th><th>Notes de la période</th><th>Moyenne</th></tr></thead><tbody><?php foreach ($subjects as $subject):$average = grade_notebook_average($subject['notes']);?><tr><td><strong><?=e($subject['subject'])?></strong><small><?=e($subject['teachers'] ?: 'Enseignant non renseigné')?></small></td><td><div class="report-notes"><?php if (!$subject['notes']):?><span class="muted">Aucune note sur la période</span><?php endif?><?php foreach ($subject['notes'] as $note):?><span class="report-note"><strong><?=$note['special_status'] ? e($note['special_status']) : ($note['score'] !== null ? e(display_decimal($note['score']).' / '.display_decimal($note['scale'])) : '—')?></strong><small><?=e($note['title'])?></small></span><?php endforeach?></div></td><td class="score"><?=$average !== null ? e(display_decimal($average, (int)$settings['decimals'])).' / 20' : '—'?></td></tr><?php endforeach?></tbody></table></div></section>
    <?php footer_html();
    exit;
}

function render_notebook_mode_switch(string $mode): void
{
    if (!is_admin()) {
        return;
    }
    ?>
    <nav class="module-tabs notebook-mode-switch">
        <a class="button <?= $mode === 'teacher' ? '' : 'secondary' ?>" href="?page=grade-notebook&amp;notebook_mode=teacher">Vue enseignant</a>
        <a class="button <?= $mode === 'principal' ? '' : 'secondary' ?>" href="?page=grade-notebook&amp;notebook_mode=principal">Vue professeur principal / instituteur</a>
    </nav>
    <?php
}

function render_notebook_period_data(array $periods): void
{
    $dates = [];
    foreach ($periods as $period) {
        $dates[(string)$period['id']] = [
            'from' => $period['starts_on'],
            'to' => $period['ends_on'],
        ];
    }
    ?><span class="notebook-period-data" data-periods="<?=e(json_encode($dates, JSON_UNESCAPED_UNICODE))?>" hidden></span><?php
}
