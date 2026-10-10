<?php
declare(strict_types=1);

function handle_grade_actions(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($_POST['action'] ?? '', ['update_assessment','delete_assessment'], true)) {
        return;
    }
    require_login();
    if (!has_capability('can_teach') && !has_capability('can_be_principal') && !direction_mode_active()) {
        http_response_code(403);
        exit('Accès interdit');
    }
    $assessmentId = (int)($_POST['assessment_id'] ?? 0);
    $assessment = one('SELECT id,title,course_id,coefficient FROM assessments WHERE id=?', [$assessmentId]);
    if (!$assessment || !can_manage_course((int)$assessment['course_id'])) {
        http_response_code(403);
        exit('Accès interdit');
    }
    if (($_POST['action'] ?? '') === 'update_assessment') {
        $title = trim($_POST['title'] ?? '');
        $coefficient = max(.1, (float)($_POST['coefficient'] ?? 1));
        if ($title === '') {
            flash('Le titre de l’évaluation est obligatoire.', 'error');
            redirect('index.php?page=grades&edit_assessment='.$assessmentId);
        }
        db()->prepare('UPDATE assessments SET title=?,coefficient=? WHERE id=?')->execute([$title,$coefficient,$assessmentId]);
        audit('update', 'assessment', $assessmentId, $assessment, ['title' => $title,'coefficient' => $coefficient]);
        flash('Évaluation mise à jour.');
        redirect('index.php?page=grades');
    }
    db()->prepare('DELETE FROM assessments WHERE id=?')->execute([$assessmentId]);
    audit('delete', 'assessment', $assessmentId, $assessment);
    flash('Évaluation et notes associées supprimées.');
    redirect('index.php?page=grades');
}

function render_grades_page(): never
{
    require_login();
    if (!has_capability('can_teach') && !has_capability('can_be_principal') && !direction_mode_active()) {
        http_response_code(403);
        exit('Accès interdit');
    }
    global $settings;
    $allCourses = rows("SELECT DISTINCT co.id,CONCAT(c.level,' · ',s.name,IF(COALESCE(cg.name,co.group_name) IS NULL,'',CONCAT(' · ',COALESCE(cg.name,co.group_name)))) label,c.sort_order,s.name subject_name FROM courses co JOIN classes c ON c.id=co.class_id JOIN subjects s ON s.id=co.subject_id LEFT JOIN class_groups cg ON cg.id=co.class_group_id ORDER BY c.sort_order,s.name,label");
    $courses = array_values(array_filter($allCourses, fn ($course) => can_create_assessment_for_course((int)$course['id'])));
    $periods = rows("SELECT p.id,CONCAT(y.name,' · ',p.name) label,p.status FROM periods p JOIN school_years y ON y.id=p.school_year_id WHERE p.status='open' ORDER BY y.starts_on DESC,p.sort_order");
    $assessments = rows('SELECT a.*,s.name subject,c.level class_name,
        (SELECT COUNT(*) FROM grades g WHERE g.assessment_id=a.id) grade_count,
        (SELECT COUNT(*) FROM enrollments e WHERE e.class_id=co.class_id AND (co.class_group_id IS NULL OR EXISTS(SELECT 1 FROM group_students gs WHERE gs.group_id=co.class_group_id AND gs.student_id=e.student_id))) student_count
        FROM assessments a JOIN courses co ON co.id=a.course_id JOIN subjects s ON s.id=co.subject_id JOIN classes c ON c.id=co.class_id ORDER BY a.assessment_date DESC,a.id DESC');
    $filterCourseId = (int) ($_GET['course_id'] ?? 0);
    $filterPeriodId = (int) ($_GET['period_id'] ?? 0);
    $filterStatus = in_array($_GET['status'] ?? '', ['all', 'empty', 'started', 'incomplete'], true) ? $_GET['status'] : 'all';
    $editId = (int)($_GET['edit_assessment'] ?? 0);
    $edit = null;
    foreach ($assessments as $item) {
        if ((int)$item['id'] === $editId && can_manage_course((int)$item['course_id'])) {
            $edit = $item;
        }
    }
    header_html('Évaluations');
    ?><nav class="module-tabs"><a class="button" href="?page=grades">Évaluations et notes</a><a class="button secondary" href="?page=grade-notebook">Carnet de notes</a><a class="button secondary" href="?page=comments">Appréciations</a></nav>
    <section class="panel compact-filters"><form method="get"><input type="hidden" name="page" value="grades"><label>Enseignement<select name="course_id"><option value="0">Tous mes enseignements</option><?php foreach ($courses as $course): ?><option value="<?= $course['id'] ?>" <?= $filterCourseId === (int) $course['id'] ? 'selected' : '' ?>><?= e($course['label']) ?></option><?php endforeach; ?></select></label><label>État<select name="status"><option value="all">Toutes</option><option value="empty" <?= $filterStatus === 'empty' ? 'selected' : '' ?>>Sans note</option><option value="started" <?= $filterStatus === 'started' ? 'selected' : '' ?>>Saisie commencée</option><option value="incomplete" <?= $filterStatus === 'incomplete' ? 'selected' : '' ?>>À compléter</option></select></label><button>Filtrer</button><a class="button secondary" href="?page=grades">Réinitialiser</a></form></section>
    <div class="two-col"><section class="panel"><h2>Évaluations</h2><div class="table-wrap"><table><thead><tr><th>Titre</th><th>Niveau</th><th>Barème</th><th>Saisies</th><th>Actions</th></tr></thead><tbody>
    <?php $visible = 0;
    foreach ($assessments as $assessment):if (!can_manage_course((int)$assessment['course_id']) || ($filterCourseId && $filterCourseId !== (int) $assessment['course_id']) || ($filterStatus === 'empty' && (int) $assessment['grade_count'] > 0) || ($filterStatus === 'started' && ((int) $assessment['grade_count'] === 0 || (int) $assessment['grade_count'] >= (int) $assessment['student_count'])) || ($filterStatus === 'incomplete' && (int) $assessment['grade_count'] >= (int) $assessment['student_count'])) {
        continue;
    }$visible++;?><tr><td><a href="?page=gradebook&amp;id=<?=$assessment['id']?>"><strong><?=e($assessment['title'])?></strong></a><small><?=e($assessment['subject'])?> · <?=date('d/m/Y', strtotime($assessment['assessment_date']))?> · coef. <?=e(display_decimal($assessment['coefficient']))?></small></td><td><?=e($assessment['class_name'])?></td><td>/ <?=e(display_decimal($assessment['scale']))?></td><td><?=e($assessment['grade_count'])?></td><td><span class="table-actions"><a class="icon-button" href="?page=grades&amp;edit_assessment=<?=$assessment['id']?>" title="Modifier" aria-label="Modifier"><i class="bi bi-pencil-square"></i></a><form method="post" onsubmit="return confirm('Supprimer cette évaluation et toutes les notes associées ?');"><input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="delete_assessment"><input type="hidden" name="assessment_id" value="<?=$assessment['id']?>"><button class="icon-button danger" title="Supprimer" aria-label="Supprimer"><i class="bi bi-trash"></i></button></form></span></td></tr><?php endforeach?>
    <?php if (!$visible):?><tr><td colspan="5" class="empty">Aucune évaluation.</td></tr><?php endif?></tbody></table></div></section>
    <?php if ($edit):?>
      <form class="panel sticky" method="post"><p class="eyebrow">Modification</p><h2>Modifier l’évaluation</h2><input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="update_assessment"><input type="hidden" name="assessment_id" value="<?=$edit['id']?>"><label>Titre du devoir<input required name="title" value="<?=e($edit['title'])?>"></label><label>Coefficient<input required type="number" min="0.1" step="0.1" name="coefficient" value="<?=e(input_decimal($edit['coefficient']))?>"></label><div class="actions"><a class="button secondary" href="?page=grades">Annuler</a><button>Enregistrer</button></div></form>
    <?php else:?>
      <form id="new-assessment" class="panel sticky assessment-create-form" method="post"><h2>Créer une évaluation</h2><input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="add_assessment"><label>Niveau · matière · groupe<select required name="course_id"><?php foreach ($courses as $course):?><option value="<?=$course['id']?>" <?= $filterCourseId === (int) $course['id'] ? 'selected' : '' ?>><?=e($course['label'])?></option><?php endforeach?></select></label><label>Période ouverte<select required name="period_id"><?php foreach ($periods as $period):?><option value="<?=$period['id']?>" <?= $filterPeriodId === (int) $period['id'] ? 'selected' : '' ?>><?=e($period['label'])?></option><?php endforeach?></select></label><?php if (!$periods):?><div class="alert info">Aucun trimestre n’est ouvert. L’administration doit en ouvrir un dans Organisation.</div><?php endif?><label>Titre du devoir<input required name="title" placeholder="Contrôle — fractions"></label><label>Date<input required type="date" name="assessment_date" value="<?=date('Y-m-d')?>"></label><?php if ($settings['grading_mode'] === 'flexible'):?><div class="form-row"><label>Noté sur<input required type="number" min="0.01" step="0.01" name="scale" value="20"></label><label>Normaliser<select name="normalize_to"><option value="20">sur 20</option><option value="10">sur 10</option><option value="none">affichage original</option></select></label></div><?php else:?><div class="alert info">La saisie sur 20 est imposée par l’administration.</div><input type="hidden" name="scale" value="20"><input type="hidden" name="normalize_to" value="20"><?php endif?><label>Coefficient<input required type="number" min="0.1" step="0.1" name="coefficient" value="1"></label><button <?=!$courses || !$periods ? 'disabled' : ''?>>Créer et saisir les notes</button></form>
    <?php endif?></div><?php footer_html();
    exit;
}
