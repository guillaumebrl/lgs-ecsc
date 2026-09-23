<?php

declare(strict_types=1);

function render_documents_page(): never
{
    require_direction();
    $section = in_array($_GET['section'] ?? '', ['bulletins', 'reports'], true) ? $_GET['section'] : 'bulletins';
    $classes = array_values(array_filter(
        rows('SELECT c.id,c.level,y.name year_name FROM classes c JOIN school_years y ON y.id=c.school_year_id ORDER BY y.starts_on DESC,c.sort_order'),
        fn (array $class): bool => can_manage_class((int) $class['id'])
    ));
    $periods = rows('SELECT p.id,p.name,p.starts_on,p.ends_on,y.name year_name FROM periods p JOIN school_years y ON y.id=p.school_year_id ORDER BY y.starts_on DESC,p.sort_order');
    $classId = (int) ($_GET['class_id'] ?? ($classes[0]['id'] ?? 0));
    if ($classId && !can_manage_class($classId)) {
        http_response_code(403);
        exit('Accès interdit');
    }
    $periodId = (int) ($_GET['period_id'] ?? ($periods[0]['id'] ?? 0));
    $period = current(array_filter($periods, fn (array $item): bool => (int) $item['id'] === $periodId)) ?: null;
    $subperiods = $periodId ? rows('SELECT id,name,starts_on,ends_on FROM period_subperiods WHERE period_id=? ORDER BY sort_order,starts_on', [$periodId]) : [];
    $subperiodId = (int) ($_GET['subperiod_id'] ?? 0);
    $subperiod = current(array_filter($subperiods, fn (array $item): bool => (int) $item['id'] === $subperiodId)) ?: null;
    $from = $subperiod['starts_on'] ?? ($_GET['from'] ?? ($period['starts_on'] ?? date('Y-m-d')));
    $to = $subperiod['ends_on'] ?? ($_GET['to'] ?? ($period['ends_on'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $from = $period['starts_on'] ?? date('Y-m-d');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $to = $period['ends_on'] ?? date('Y-m-d');
    }
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    $students = $classId ? rows(
        'SELECT s.id,s.last_name,s.first_name,c.validated_at,c.general_comment,c.mention,c.decision_text
         FROM enrollments e JOIN students s ON s.id=e.student_id
         LEFT JOIN councils c ON c.student_id=s.id AND c.class_id=e.class_id AND c.period_id=?
         WHERE e.class_id=? AND s.active=1 ORDER BY s.last_name,s.first_name',
        [$periodId, $classId]
    ) : [];
    $validated = count(array_filter($students, fn (array $student): bool => !empty($student['validated_at'])));
    $query = '&amp;class_id='.$classId.'&amp;period_id='.$periodId.'&amp;subperiod_id='.$subperiodId.'&amp;from='.e($from).'&amp;to='.e($to);

    header_html('Documents');
    ?>
    <link rel="stylesheet" href="assets/student-management.css?v=29">
    <link rel="stylesheet" href="assets/direction.css?v=29">
    <nav class="student-section-nav">
        <a class="button <?= $section === 'bulletins' ? '' : 'secondary' ?>" href="?page=documents&amp;section=bulletins">Bulletins</a>
        <a class="button <?= $section === 'reports' ? '' : 'secondary' ?>" href="?page=documents&amp;section=reports">Relevés de notes</a>
    </nav>
    <section class="panel direction-filters">
        <form method="get">
            <input type="hidden" name="page" value="documents"><input type="hidden" name="section" value="<?= e($section) ?>">
            <label>Niveau<select name="class_id" onchange="this.form.submit()"><?php foreach ($classes as $class): ?><option value="<?= $class['id'] ?>" <?= $classId === (int) $class['id'] ? 'selected' : '' ?>><?= e($class['level'].' · '.$class['year_name']) ?></option><?php endforeach; ?></select></label>
            <label>Période<select name="period_id" onchange="this.form.submit()"><?php foreach ($periods as $item): ?><option value="<?= $item['id'] ?>" <?= $periodId === (int) $item['id'] ? 'selected' : '' ?>><?= e($item['year_name'].' · '.$item['name']) ?></option><?php endforeach; ?></select></label>
            <?php if ($section === 'reports'): ?><label>Sous-période<select name="subperiod_id" onchange="this.form.submit()"><option value="0">Dates personnalisées</option><?php foreach ($subperiods as $item): ?><option value="<?= $item['id'] ?>" <?= $subperiodId === (int) $item['id'] ? 'selected' : '' ?>><?= e($item['name']) ?></option><?php endforeach; ?></select></label><label>Date de début<input type="date" name="from" value="<?= e($from) ?>"></label><label>Date de fin<input type="date" name="to" value="<?= e($to) ?>"></label><button>Appliquer</button><?php endif; ?>
        </form>
        <?php if ($section === 'bulletins'): ?><div class="validation-count"><strong><?= $validated ?> / <?= count($students) ?></strong><span>bulletins validés</span></div><?php endif; ?>
    </section>
    <section class="panel">
        <div class="panel-head"><div><p class="eyebrow"><?= $section === 'bulletins' ? 'Validation et publication' : 'Consultation et export' ?></p><h2><?= $section === 'bulletins' ? 'Bulletins du niveau' : 'Relevés de notes du niveau' ?></h2></div><div class="direction-actions">
        <?php if ($section === 'bulletins'): ?>
            <a class="button secondary" href="?page=bulletin-batch-pdf&amp;class_id=<?= $classId ?>&amp;period_id=<?= $periodId ?>">Télécharger le PDF du niveau</a>
            <?= document_validation_form('direction_validate_class', $classId, $periodId, null, 'Tout valider') ?>
            <?php if ($validated): ?><?= document_validation_form('direction_unvalidate_class', $classId, $periodId, null, 'Tout dévalider', true) ?><?php endif; ?>
        <?php else: ?><a class="button secondary" href="?page=report-batch-pdf<?= $query ?>">Télécharger le PDF du niveau</a><?php endif; ?>
        </div></div>
        <div class="table-wrap"><table><thead><tr><th>Élève</th><?php if ($section === 'bulletins'): ?><th>Appréciation du conseil</th><th>Mention / décision</th><th>État</th><?php endif; ?><th>Document</th><?php if ($section === 'bulletins'): ?><th>Validation</th><?php endif; ?></tr></thead><tbody>
        <?php foreach ($students as $student): ?><tr><td><strong><?= e($student['last_name'].' '.$student['first_name']) ?></strong></td>
            <?php if ($section === 'bulletins'): ?><td class="document-council-comment"><?= e($student['general_comment'] ?: '—') ?></td><td><?= e($student['mention'] ?: '—') ?><small><?= e($student['decision_text'] ?: '') ?></small></td><td><span class="tag"><?= $student['validated_at'] ? 'Validé' : 'À valider' ?></span></td><td><a href="?page=bulletin&amp;class_id=<?= $classId ?>&amp;period_id=<?= $periodId ?>&amp;student_id=<?= $student['id'] ?>">Ouvrir le bulletin</a></td><td><?= document_validation_form($student['validated_at'] ? 'direction_unvalidate_student' : 'direction_validate_student', $classId, $periodId, (int) $student['id'], $student['validated_at'] ? 'Dévalider' : 'Valider', (bool) $student['validated_at']) ?></td>
            <?php else: ?><td><a href="?page=report<?= $query ?>&amp;student_id=<?= $student['id'] ?>">Ouvrir le relevé</a></td><?php endif; ?>
        </tr><?php endforeach; ?></tbody></table></div>
    </section>
    <?php
    footer_html();
    exit;
}

function document_validation_form(string $action, int $classId, int $periodId, ?int $studentId, string $label, bool $secondary = false): string
{
    ob_start(); ?>
    <form method="post"><input type="hidden" name="_token" value="<?= csrf() ?>"><input type="hidden" name="action" value="<?= e($action) ?>"><input type="hidden" name="class_id" value="<?= $classId ?>"><input type="hidden" name="period_id" value="<?= $periodId ?>"><?php if ($studentId): ?><input type="hidden" name="student_id" value="<?= $studentId ?>"><?php endif; ?><button class="<?= $secondary ? 'secondary' : '' ?>"><?= e($label) ?></button></form>
    <?php return (string) ob_get_clean();
}
