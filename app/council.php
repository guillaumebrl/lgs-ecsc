<?php
declare(strict_types=1);

function render_council_page(): never
{
    global $settings;
    if (!principal_mode_active() && !direction_mode_active()) {
        http_response_code(403);
        exit('Accès interdit');
    }

    $classes = array_values(array_filter(
        rows('SELECT c.id,c.level,c.school_year_id,c.sort_order FROM classes c JOIN school_years y ON y.id=c.school_year_id WHERE y.active=1 ORDER BY c.sort_order,c.level'),
        fn (array $class): bool => can_manage_class((int)$class['id'])
    ));
    $classId = (int)($_GET['class_id'] ?? ($classes[0]['id'] ?? 0));
    if ($classId && !can_manage_class($classId)) {
        http_response_code(403);
        exit('Accès interdit');
    }

    $class = $classId ? one('SELECT id,school_year_id FROM classes WHERE id=?', [$classId]) : null;
    $periods = $class ? rows('SELECT p.id,CONCAT(y.name," · ",p.name) label FROM periods p JOIN school_years y ON y.id=p.school_year_id WHERE p.school_year_id=? ORDER BY p.sort_order', [(int)$class['school_year_id']]) : [];
    $periodId = (int)($_GET['period_id'] ?? ($periods[0]['id'] ?? 0));
    if ($periodId && !in_array($periodId, array_map('intval', array_column($periods, 'id')), true)) {
        $periodId = (int)($periods[0]['id'] ?? 0);
    }

    $students = $classId ? rows('SELECT s.id,s.last_name,s.first_name FROM enrollments e JOIN students s ON s.id=e.student_id WHERE e.class_id=? AND s.active=1 ORDER BY s.last_name,s.first_name', [$classId]) : [];
    $studentId = (int)($_GET['student_id'] ?? ($students[0]['id'] ?? 0));
    if ($studentId && !in_array($studentId, array_map('intval', array_column($students, 'id')), true)) {
        $studentId = (int)($students[0]['id'] ?? 0);
    }
    $student = $studentId && can_manage_council_scope($classId, $studentId, $periodId) ? one('SELECT s.* FROM students s JOIN enrollments e ON e.student_id=s.id WHERE s.id=? AND e.class_id=? AND s.active=1', [$studentId,$classId]) : null;

    $summary = $student ? rows("SELECT s.name subject,co.assignment_coefficient,co.display_order,GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') teachers,ROUND(SUM((g.score/a.scale*20)*a.coefficient)/NULLIF(SUM(a.coefficient),0),2) average,MAX(sc.comment) comment FROM courses co JOIN subjects s ON s.id=co.subject_id LEFT JOIN course_teachers ct ON ct.course_id=co.id LEFT JOIN users u ON u.id=ct.user_id LEFT JOIN assessments a ON a.course_id=co.id AND a.period_id=? LEFT JOIN grades g ON g.assessment_id=a.id AND g.student_id=? AND g.special_status IS NULL LEFT JOIN subject_comments sc ON sc.course_id=co.id AND sc.period_id=? AND sc.student_id=? WHERE co.class_id=? AND (co.class_group_id IS NULL OR EXISTS(SELECT 1 FROM group_students gs WHERE gs.group_id=co.class_group_id AND gs.student_id=?)) GROUP BY co.id,s.name,co.assignment_coefficient,co.display_order ORDER BY co.display_order,s.name", [$periodId,$studentId,$periodId,$studentId,$classId,$studentId]) : [];
    $council = $student ? one('SELECT * FROM councils WHERE student_id=? AND class_id=? AND period_id=?', [$studentId,$classId,$periodId]) : null;
    $overall = weighted_subject_average($summary);

    header_html('Conseil de classe'); ?>
    <section class="council-toolbar"><form method="get" data-council-filters><input type="hidden" name="page" value="council"><input type="hidden" name="projector" value="1" data-projector-state disabled><select name="class_id" data-council-class><?php foreach ($classes as $item):?><option value="<?=$item['id']?>" <?=$classId === (int)$item['id'] ? 'selected' : ''?>><?=e($item['level'])?></option><?php endforeach?></select><select name="period_id" data-council-period><?php foreach ($periods as $period):?><option value="<?=$period['id']?>" <?=$periodId === (int)$period['id'] ? 'selected' : ''?>><?=e($period['label'])?></option><?php endforeach?></select><select name="student_id" data-council-student><?php foreach ($students as $item):?><option value="<?=$item['id']?>" <?=$studentId === (int)$item['id'] ? 'selected' : ''?>><?=e($item['last_name'].' '.$item['first_name'])?></option><?php endforeach?></select></form><button type="button" class="projector-toggle" data-projector aria-pressed="false"><span class="projector-switch" aria-hidden="true"><span></span></span><span class="projector-toggle-text"><strong>Vidéoprojecteur</strong><small data-projector-label>Désactivé</small></span></button><button class="ghost" data-privacy>Masquer les données</button></section>
    <?php if ($student):?><section class="student-spotlight"><div><p class="eyebrow">Élève examiné</p><h2><?=e($student['first_name'].' '.$student['last_name'])?></h2><span><?=e($student['registration_number'])?></span></div><div class="overall"><small>Moyenne générale</small><strong><?=$overall !== null ? number_format($overall, (int)$settings['decimals'], ',', ' ') : '—'?></strong><span>/ 20</span></div></section><div class="council-grid"><section class="panel"><table><thead><tr><th>Matière</th><th>Enseignant(s)</th><th>Moyenne</th><th>Appréciation</th></tr></thead><tbody><?php foreach ($summary as $subject):?><tr><td><strong><?=e($subject['subject'])?></strong></td><td><?=e($subject['teachers'] ?: '—')?></td><td class="score"><?=$subject['average'] !== null ? number_format((float)$subject['average'], (int)$settings['decimals'], ',', ' ') : '—'?></td><td class="projector-comment"><?=e($subject['comment'] ?: 'Non renseignée')?></td></tr><?php endforeach?></tbody></table></section><form class="panel decision" method="post" data-council-decision><h2>Synthèse du conseil</h2><input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="save_council"><input type="hidden" name="student_id" value="<?=$studentId?>"><input type="hidden" name="class_id" value="<?=$classId?>"><input type="hidden" name="period_id" value="<?=$periodId?>"><label>Appréciation générale<textarea name="general_comment" rows="6"><?=e($council['general_comment'] ?? '')?></textarea></label><label>Mention<select name="mention"><option value="">Aucune</option><?php foreach (['Encouragements','Compliments','Félicitations','Avertissement travail','Avertissement comportement'] as $mention):?><option <?=$mention === ($council['mention'] ?? '') ? 'selected' : ''?>><?=e($mention)?></option><?php endforeach?></select></label><label>Décision<input name="decision_text" value="<?=e($council['decision_text'] ?? '')?>" placeholder="Passage, orientation…"></label><button <?=!empty($council['validated_at']) && !is_admin() ? 'disabled' : ''?>>Enregistrer en séance</button><?php if (empty($council['validated_at'])):?><button class="success-button" name="action" value="validate_council">Valider le bulletin</button><?php else:?><div class="alert success">Validé le <?=date('d/m/Y à H:i', strtotime($council['validated_at']))?></div><button class="secondary" name="action" value="unlock_council" formnovalidate>Déverrouiller pour corriger</button><a class="button secondary" href="?page=bulletin&amp;class_id=<?=$classId?>&amp;period_id=<?=$periodId?>&amp;student_id=<?=$studentId?>">Voir le bulletin</a><?php endif?></form></div><?php else:?><div class="empty-state"><h2>Aucun élève accessible</h2><p>Vérifiez l’année active, la classe attribuée et ses inscriptions.</p></div><?php endif?>
    <?php footer_html();
    exit;
}
