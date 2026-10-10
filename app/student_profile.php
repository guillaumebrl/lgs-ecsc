<?php

declare(strict_types=1);

function render_student_profile_page(): never
{
    require_login();
    $studentId = (int) ($_GET['id'] ?? 0);
    if (!$studentId || !can_view_student($studentId)) {
        http_response_code(404);
        exit('Élève introuvable');
    }
    $student = one(
        'SELECT s.*,c.id class_id,c.level class_name,f.family_label
         FROM students s LEFT JOIN enrollments e ON e.student_id=s.id
         LEFT JOIN classes c ON c.id=e.class_id LEFT JOIN school_years y ON y.id=c.school_year_id AND y.active=1
         LEFT JOIN families f ON f.id=s.family_id WHERE s.id=? ORDER BY y.active DESC LIMIT 1',
        [$studentId]
    );
    if (!$student) {
        http_response_code(404);
        exit('Élève introuvable');
    }
    $averages = rows(
        'SELECT sub.name subject,ROUND(SUM((g.score/a.scale*20)*a.coefficient)/NULLIF(SUM(a.coefficient),0),2) average
         FROM courses co JOIN subjects sub ON sub.id=co.subject_id JOIN assessments a ON a.course_id=co.id
         JOIN grades g ON g.assessment_id=a.id AND g.student_id=?
         WHERE co.class_id=? AND g.score IS NOT NULL AND g.special_status IS NULL
         GROUP BY co.id,sub.name ORDER BY co.display_order,sub.name',
        [$studentId, (int) $student['class_id']]
    );
    $attendance = rows('SELECT * FROM attendance WHERE student_id=? ORDER BY occurred_at DESC LIMIT 8', [$studentId]);
    $guardians = $student['family_id'] ? rows('SELECT * FROM guardians WHERE family_id=? ORDER BY display_order,id', [(int) $student['family_id']]) : [];
    $siblings = $student['family_id'] ? rows('SELECT id,first_name,last_name FROM students WHERE family_id=? AND id<>? AND active=1 ORDER BY last_name,first_name', [(int) $student['family_id'], $studentId]) : [];

    header_html($student['first_name'] . ' ' . $student['last_name']); ?>
    <nav class="context-nav"><a class="button secondary" href="?page=my-students">← Mes élèves</a><?php if (management_mode_active()): ?><a class="button" href="?page=student-detail&amp;id=<?= $studentId ?>">Modifier le dossier</a><?php endif; ?></nav>
    <section class="student-summary panel"><div><p class="eyebrow">Fiche élève</p><h2><?= e($student['first_name'] . ' ' . $student['last_name']) ?></h2><p><?= e($student['class_name'] ?: 'Sans niveau') ?> · <?= e($student['registration_number'] ?: 'INE non renseigné') ?></p></div><div class="summary-badges"><span class="tag"><?= count($averages) ?> matière<?= count($averages) > 1 ? 's' : '' ?></span><span class="tag"><?= count($attendance) ?> événement<?= count($attendance) > 1 ? 's' : '' ?> récent<?= count($attendance) > 1 ? 's' : '' ?></span></div></section>
    <div class="profile-grid">
        <section class="panel"><div class="panel-head"><div><p class="eyebrow">Pédagogie</p><h2>Moyennes disponibles</h2></div><?php if (principal_mode_active() && $student['class_id']): ?><a class="button secondary compact" href="?page=grade-notebook&amp;class_id=<?= $student['class_id'] ?>&amp;student_id=<?= $studentId ?>&amp;notebook_mode=principal">Carnet complet</a><?php endif; ?></div><?php foreach ($averages as $average): ?><div class="metric-row"><span><?= e($average['subject']) ?></span><strong><?= e(display_decimal($average['average'])) ?> / 20</strong></div><?php endforeach; ?><?php if (!$averages): ?><p class="empty">Aucune moyenne disponible.</p><?php endif; ?></section>
        <section class="panel"><div class="panel-head"><div><p class="eyebrow">Vie scolaire</p><h2>Événements récents</h2></div><?php if (school_life_mode_active()): ?><a class="button secondary compact" href="?page=school-life&amp;student_id=<?= $studentId ?>">Saisir</a><?php endif; ?></div><?php foreach ($attendance as $event): ?><div class="metric-row"><span><?= e($event['kind'] === 'late' ? 'Retard de ' . $event['duration_minutes'] . ' min' : 'Absence') ?><small><?= date('d/m/Y H:i', strtotime($event['occurred_at'])) ?></small></span><span class="tag"><?= $event['justified'] ? 'Justifié' : 'À justifier' ?></span></div><?php endforeach; ?><?php if (!$attendance): ?><p class="empty">Aucun événement récent.</p><?php endif; ?></section>
        <section class="panel"><p class="eyebrow">Famille</p><h2><?= e($student['family_label'] ?: 'Aucune famille rattachée') ?></h2><?php foreach ($guardians as $guardian): ?><div class="contact-card"><strong><?= e($guardian['first_name'] . ' ' . $guardian['last_name']) ?></strong><span><?= e($guardian['relationship']) ?></span><small><?= e($guardian['email'] ?: 'E-mail non renseigné') ?> · <?= e($guardian['phone'] ?: 'Téléphone non renseigné') ?></small></div><?php endforeach; ?><?php if ($siblings): ?><h3>Fratrie</h3><?php foreach ($siblings as $sibling): ?><a class="result-row" href="?page=student-profile&amp;id=<?= $sibling['id'] ?>"><?= e($sibling['first_name'] . ' ' . $sibling['last_name']) ?><span>→</span></a><?php endforeach; ?><?php endif; ?></section>
    </div>
    <?php footer_html();
    exit;
}
