<?php
declare(strict_types=1);
function render_dashboard_page(): never
{
    require_login();
    $uid = (int)user()['id'];
    $stats = [];
    if (is_admin() || direction_mode_active() || has_capability('can_school_life')) {
        $stats['Élèves de l’école'] = (int)db()->query('SELECT COUNT(*) FROM students WHERE active=1')->fetchColumn();
        $stats['Niveaux de l’école'] = (int)db()->query('SELECT COUNT(*) FROM classes c JOIN school_years y ON y.id=c.school_year_id WHERE y.active=1')->fetchColumn();
    }
    if (principal_mode_active() && !is_admin()) {
        $stats['Élèves de ma classe'] = (int)(one('SELECT COUNT(DISTINCT e.student_id) total FROM class_principals cp JOIN classes c ON c.id=cp.class_id LEFT JOIN enrollments e ON e.class_id=c.id LEFT JOIN students s ON s.id=e.student_id WHERE cp.user_id=? AND s.active=1', [$uid])['total'] ?? 0);
    }
    if (has_capability('can_teach') && !is_admin()) {
        $stats['Élèves auxquels j’enseigne'] = (int)(one('SELECT COUNT(DISTINCT e.student_id) total FROM course_teachers ct JOIN courses co ON co.id=ct.course_id JOIN enrollments e ON e.class_id=co.class_id JOIN students s ON s.id=e.student_id WHERE ct.user_id=? AND s.active=1 AND (co.class_group_id IS NULL OR EXISTS(SELECT 1 FROM group_students gs WHERE gs.group_id=co.class_group_id AND gs.student_id=s.id))', [$uid])['total'] ?? 0);
        $stats['Niveaux où j’interviens'] = (int)(one('SELECT COUNT(DISTINCT co.class_id) total FROM course_teachers ct JOIN courses co ON co.id=ct.course_id WHERE ct.user_id=?', [$uid])['total'] ?? 0);
    }
    if (direction_mode_active() || principal_mode_active()) {
        $stats['Bulletins validés'] = (int)db()->query('SELECT COUNT(*) FROM councils WHERE validated_at IS NOT NULL')->fetchColumn();
    }
    $base = 'SELECT a.id,a.title,a.assessment_date,s.name subject,c.level class_name,u.name author FROM assessments a JOIN courses co ON co.id=a.course_id JOIN subjects s ON s.id=co.subject_id JOIN classes c ON c.id=co.class_id JOIN users u ON u.id=a.author_id';
    if (direction_mode_active()) {
        $recent = rows($base.' ORDER BY a.id DESC LIMIT 8');
    } elseif (principal_mode_active()) {
        $recent = rows($base.' WHERE EXISTS(SELECT 1 FROM class_principals cp WHERE cp.class_id=c.id AND cp.user_id=?) ORDER BY a.id DESC LIMIT 8', [$uid]);
    } elseif (has_capability('can_teach')) {
        $recent = rows($base.' WHERE a.author_id=? ORDER BY a.id DESC LIMIT 8', [$uid]);
    } else {
        $recent = [];
    }
    $year = db()->query('SELECT name FROM school_years WHERE active=1 ORDER BY starts_on DESC LIMIT 1')->fetchColumn() ?: 'Non définie';
    $responsibilities = has_capability('can_be_principal') ? rows('SELECT c.level,c.education_stage FROM class_principals cp JOIN classes c ON c.id=cp.class_id JOIN school_years y ON y.id=c.school_year_id WHERE y.active=1 AND cp.user_id=? ORDER BY c.sort_order', [$uid]) : [];
    $tasks = [];
    if (has_capability('can_teach') && !direction_mode_active()) {
        $incompleteGrades = (int) (one(
            "SELECT COUNT(DISTINCT a.id) total FROM assessments a
             JOIN courses co ON co.id=a.course_id JOIN course_teachers ct ON ct.course_id=co.id AND ct.user_id=?
             JOIN periods p ON p.id=a.period_id
             WHERE p.status='open' AND (SELECT COUNT(*) FROM grades g WHERE g.assessment_id=a.id) <
             (SELECT COUNT(*) FROM enrollments e WHERE e.class_id=co.class_id AND (co.class_group_id IS NULL OR EXISTS(SELECT 1 FROM group_students gs WHERE gs.group_id=co.class_group_id AND gs.student_id=e.student_id)))",
            [$uid]
        )['total'] ?? 0);
        $tasks[] = ['count' => $incompleteGrades, 'label' => 'évaluation' . ($incompleteGrades > 1 ? 's' : '') . ' à compléter', 'href' => '?page=grades&status=incomplete', 'action' => 'Saisir les notes'];
        $tasks[] = ['count' => null, 'label' => 'Créer un devoir et saisir les résultats', 'href' => '?page=grades#new-assessment', 'action' => 'Nouvelle évaluation'];
        $tasks[] = ['count' => null, 'label' => 'Préparer les bulletins de la période', 'href' => '?page=comments', 'action' => 'Rédiger les appréciations'];
    }
    if (principal_mode_active() || direction_mode_active()) {
        $pendingBulletins = (int) db()->query('SELECT COUNT(*) FROM councils WHERE validated_at IS NULL')->fetchColumn();
        $tasks[] = ['count' => $pendingBulletins, 'label' => 'bulletin' . ($pendingBulletins > 1 ? 's' : '') . ' à valider', 'href' => '?page=documents&status=pending', 'action' => 'Ouvrir les documents'];
        $tasks[] = ['count' => null, 'label' => 'Examiner les élèves et décisions', 'href' => '?page=council', 'action' => 'Ouvrir le conseil'];
    }
    if (management_mode_active()) {
        $missingFamilies = (int) db()->query('SELECT COUNT(*) FROM students WHERE active=1 AND family_id IS NULL')->fetchColumn();
        $tasks[] = ['count' => $missingFamilies, 'label' => 'élève' . ($missingFamilies > 1 ? 's' : '') . ' sans famille rattachée', 'href' => '?page=students&status=missing-family', 'action' => 'Compléter les dossiers'];
    }
    header_html('Tableau de bord');?>
    <section class="hero"><div><span class="badge">Année en cours · <?=e($year)?></span><h2>Bonjour, <?=e(user()['first_name'] ?: explode(' ', user()['name'])[0])?>.</h2><p>Retrouvez les informations correspondant à vos fonctions dans l’établissement.</p></div><?php if (has_capability('can_teach') || has_capability('can_be_principal')):?><a class="button light" href="?page=grades">Évaluations et notes →</a><?php endif?></section>
    <?php if ($tasks): ?><section class="panel task-center"><div class="panel-head"><div><p class="eyebrow">À faire maintenant</p><h2>Actions prioritaires</h2></div></div><div class="task-grid"><?php foreach ($tasks as $task): ?><a class="task-card" href="<?= e($task['href']) ?>"><?php if ($task['count'] !== null): ?><strong><?= $task['count'] ?></strong><?php endif; ?><span><?= e($task['label']) ?></span><b><?= e($task['action']) ?> →</b></a><?php endforeach; ?></div></section><?php endif; ?>
    <?php if ($responsibilities):?><section class="panel responsibility-panel"><p class="eyebrow">Responsabilité pédagogique</p><h2>Mes qualités et niveaux de responsabilité</h2><div class="responsibility-list"><?php foreach ($responsibilities as $responsibility):$quality = $responsibility['education_stage'] === 'middle' ? 'Professeur principal' : 'Instituteur';?><span class="tag"><strong><?=e($quality)?></strong> · <?=e($responsibility['level'])?></span><?php endforeach?></div></section><?php endif?>
    <section class="stats"><?php foreach ($stats as $label => $value):?><article><span><?=e($label)?></span><strong><?=e($value)?></strong></article><?php endforeach?></section>
    <section class="panel"><div class="panel-head"><div><p class="eyebrow">Activité pédagogique</p><h2><?=direction_mode_active() || principal_mode_active() ? 'Dernières évaluations accessibles' : 'Mes dernières évaluations'?></h2></div></div><div class="table-wrap"><table><thead><tr><th>Évaluation</th><th>Niveau</th><th>Matière</th><th>Date</th><th>Auteur</th></tr></thead><tbody><?php foreach ($recent as $item):?><tr><td><a href="?page=gradebook&amp;id=<?=$item['id']?>"><?=e($item['title'])?></a></td><td><?=e($item['class_name'])?></td><td><?=e($item['subject'])?></td><td><?=date('d/m/Y', strtotime($item['assessment_date']))?></td><td><?=e($item['author'])?></td></tr><?php endforeach?><?php if (!$recent):?><tr><td colspan="5" class="empty">Aucune évaluation accessible.</td></tr><?php endif?></tbody></table></div></section>
    <?php footer_html();
    exit;
}
