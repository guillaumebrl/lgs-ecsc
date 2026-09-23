<?php
declare(strict_types=1);
function render_my_students_page(): never
{
    require_login();
    if (!has_capability('can_teach') && !has_capability('can_be_principal') && !direction_mode_active()) {
        http_response_code(403);
        exit('Accès interdit');
    }
    $uid = (int)user()['id'];
    $assignments = direction_mode_active() ? rows('SELECT MIN(co.id) id,c.id class_id,c.level,c.sort_order,cg.name group_name,co.class_group_id FROM courses co JOIN classes c ON c.id=co.class_id LEFT JOIN class_groups cg ON cg.id=co.class_group_id GROUP BY c.id,c.level,c.sort_order,cg.id,cg.name,co.class_group_id ORDER BY c.sort_order,cg.name') : rows('SELECT MIN(co.id) id,c.id class_id,c.level,c.sort_order,cg.name group_name,co.class_group_id FROM courses co JOIN classes c ON c.id=co.class_id LEFT JOIN class_groups cg ON cg.id=co.class_group_id LEFT JOIN course_teachers ct ON ct.course_id=co.id WHERE ct.user_id=? OR (EXISTS(SELECT 1 FROM class_principals cp WHERE cp.class_id=c.id AND cp.user_id=?) AND ?=1) GROUP BY c.id,c.level,c.sort_order,cg.id,cg.name,co.class_group_id ORDER BY c.sort_order,cg.name', [$uid,$uid,principal_mode_active() ? 1 : 0]);
    $selectedId = (int)($_GET['assignment_id'] ?? 0);
    $selected = null;
    foreach ($assignments as $item) {
        if ((int)$item['id'] === $selectedId) {
            $selected = $item;
            break;
        }
    }
    if ($selected) {
        $students = rows('SELECT DISTINCT s.id,s.last_name,s.first_name,s.registration_number,s.birth_date FROM enrollments e JOIN students s ON s.id=e.student_id WHERE e.class_id=? AND s.active=1 AND (? IS NULL OR EXISTS(SELECT 1 FROM group_students gs WHERE gs.student_id=s.id AND gs.group_id=?)) ORDER BY s.last_name,s.first_name', [$selected['class_id'],$selected['class_group_id'],$selected['class_group_id']]);
    } elseif (direction_mode_active()) {
        $students = rows('SELECT DISTINCT s.id,s.last_name,s.first_name,s.registration_number,s.birth_date FROM students s WHERE s.active=1 ORDER BY s.last_name,s.first_name');
    } else {
        $students = rows('SELECT DISTINCT s.id,s.last_name,s.first_name,s.registration_number,s.birth_date FROM students s JOIN enrollments e ON e.student_id=s.id JOIN classes c ON c.id=e.class_id LEFT JOIN courses co ON co.class_id=c.id LEFT JOIN course_teachers ct ON ct.course_id=co.id WHERE s.active=1 AND ((EXISTS(SELECT 1 FROM class_principals cp WHERE cp.class_id=c.id AND cp.user_id=?) AND ?=1) OR (ct.user_id=? AND (co.class_group_id IS NULL OR EXISTS(SELECT 1 FROM group_students gs WHERE gs.student_id=s.id AND gs.group_id=co.class_group_id)))) ORDER BY s.last_name,s.first_name', [$uid,principal_mode_active() ? 1 : 0,$uid]);
    }
    header_html('Mes élèves');?>
    <section class="panel"><div class="panel-head"><div><p class="eyebrow">Filtres</p><h2>Mes niveaux et groupes</h2></div><a class="button <?=$selected ? 'secondary' : ''?>" href="?page=my-students">Tous mes élèves</a></div><div class="module-tabs"><?php foreach ($assignments as $item):?><a class="button <?=$selectedId === (int)$item['id'] ? '' : 'secondary'?>" href="?page=my-students&amp;assignment_id=<?=$item['id']?>"><?=e($item['level'].' · '.($item['group_name'] ?: 'niveau entier'))?></a><?php endforeach?></div></section>
    <section class="panel"><div class="panel-head"><div><p class="eyebrow">Liste des élèves</p><h2><?=e($selected ? ($selected['level'].' · '.($selected['group_name'] ?: 'niveau entier')) : 'Tous mes élèves')?></h2></div><input class="search" type="search" placeholder="Rechercher…" data-filter-table></div><div class="table-wrap"><table data-table><thead><tr><th>Élève</th><th>Date de naissance</th><th>N° INE</th></tr></thead><tbody><?php foreach ($students as $student):?><tr><td><strong><?=e($student['last_name'].' '.$student['first_name'])?></strong></td><td><?=!empty($student['birth_date']) ? date('d/m/Y', strtotime($student['birth_date'])) : 'Non renseignée'?></td><td><?=e($student['registration_number'] ?: 'En attente')?></td></tr><?php endforeach?><?php if (!$students):?><tr><td colspan="3" class="empty">Aucun élève dans cette sélection.</td></tr><?php endif?></tbody></table></div></section>
    <?php footer_html();
    exit;
}
