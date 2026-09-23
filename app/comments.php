<?php
declare(strict_types=1);
function handle_comment_actions(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'save_subject_comments') {
        return;
    }
    require_login();
    $courseId = (int)$_POST['course_id'];
    $periodId = (int)$_POST['period_id'];
    if (!can_manage_course($courseId)) {
        http_response_code(403);
        exit('Accès interdit');
    }
    $limit = (int)(db()->query('SELECT comment_max_length FROM settings WHERE id=1')->fetchColumn() ?: 500);
    $stmt = db()->prepare('INSERT INTO subject_comments(student_id,course_id,period_id,author_id,comment) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE author_id=VALUES(author_id),comment=VALUES(comment),updated_at=CURRENT_TIMESTAMP');
    foreach ($_POST['comments'] ?? [] as $studentId => $comment) {
        $text = trim($comment);
        if (mb_strlen($text) > $limit) {
            flash('Une appréciation dépasse la limite de '.$limit.' caractères.', 'error');
            redirect('index.php?page=comments&course_id='.$courseId.'&period_id='.$periodId);
        }if ($text === '') {
            db()->prepare('DELETE FROM subject_comments WHERE student_id=? AND course_id=? AND period_id=?')->execute([(int)$studentId,$courseId,$periodId]);
        } else {
            $stmt->execute([(int)$studentId,$courseId,$periodId,user()['id'],$text]);
        }
    }
    flash('Appréciations enregistrées.');
    redirect('index.php?page=comments&course_id='.$courseId.'&period_id='.$periodId);
}
function render_comments_page(): never
{
    require_login();
    if (!has_capability('can_teach') && !has_capability('can_be_principal') && !has_capability('can_direction')) {
        http_response_code(403);
        exit('Accès interdit');
    }
    $all = rows('SELECT co.id,c.level,s.name subject,cg.name group_name FROM courses co JOIN classes c ON c.id=co.class_id JOIN subjects s ON s.id=co.subject_id LEFT JOIN class_groups cg ON cg.id=co.class_group_id ORDER BY c.sort_order,co.display_order,s.name');
    $courses = array_values(array_filter($all, fn ($item) => can_manage_course((int)$item['id'])));
    $courseId = (int)($_GET['course_id'] ?? ($courses[0]['id'] ?? 0));
    $periods = rows("SELECT p.id,CONCAT(y.name,' · ',p.name) label FROM periods p JOIN school_years y ON y.id=p.school_year_id ORDER BY y.starts_on DESC,p.sort_order");
    $periodId = (int)($_GET['period_id'] ?? ($periods[0]['id'] ?? 0));
    $course = $courseId ? one('SELECT co.*,c.level,s.name subject,cg.name group_name FROM courses co JOIN classes c ON c.id=co.class_id JOIN subjects s ON s.id=co.subject_id LEFT JOIN class_groups cg ON cg.id=co.class_group_id WHERE co.id=?', [$courseId]) : null;
    if ($course && !can_manage_course($courseId)) {
        http_response_code(403);
        exit('Accès interdit');
    }
    $students = $course ? rows('SELECT DISTINCT s.id,s.last_name,s.first_name,sc.comment,(SELECT ROUND(SUM((g.score/a.scale*20)*a.coefficient)/NULLIF(SUM(a.coefficient),0),2) FROM assessments a JOIN grades g ON g.assessment_id=a.id WHERE a.course_id=? AND a.period_id=? AND g.student_id=s.id AND g.score IS NOT NULL AND g.special_status IS NULL) student_average FROM enrollments e JOIN students s ON s.id=e.student_id LEFT JOIN subject_comments sc ON sc.student_id=s.id AND sc.course_id=? AND sc.period_id=? WHERE e.class_id=? AND s.active=1 AND (? IS NULL OR EXISTS(SELECT 1 FROM group_students gs WHERE gs.group_id=? AND gs.student_id=s.id)) ORDER BY s.last_name,s.first_name', [$courseId,$periodId,$courseId,$periodId,$course['class_id'],$course['class_group_id'],$course['class_group_id']]) : [];
    header_html('Appréciations');?>
    <nav class="module-tabs"><a class="button secondary" href="?page=grades">Évaluations et notes</a><a class="button secondary" href="?page=grade-notebook">Carnet de notes</a><a class="button" href="?page=comments">Appréciations</a></nav>
    <section class="panel"><div class="panel-head"><div><p class="eyebrow">Préparation des bulletins</p><h2>Appréciations par matière</h2></div></div><form class="comment-filters" method="get"><input type="hidden" name="page" value="comments"><label>Enseignement<select name="course_id" onchange="this.form.submit()"><?php foreach ($courses as $item):?><option value="<?=$item['id']?>" <?=$courseId === (int)$item['id'] ? 'selected' : ''?>><?=e($item['subject'].' — '.$item['level'].' ('.($item['group_name'] ?: 'niveau entier').')')?></option><?php endforeach?></select></label><label>Période<select name="period_id" onchange="this.form.submit()"><?php foreach ($periods as $period):?><option value="<?=$period['id']?>" <?=$periodId === (int)$period['id'] ? 'selected' : ''?>><?=e($period['label'])?></option><?php endforeach?></select></label></form></section>
    <?php if ($course):?><form class="panel" method="post"><input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="save_subject_comments"><input type="hidden" name="course_id" value="<?=$courseId?>"><input type="hidden" name="period_id" value="<?=$periodId?>"><div class="table-wrap"><table><thead><tr><th>Élève</th><th>Moyenne</th><th>Appréciation destinée au bulletin</th></tr></thead><tbody><?php foreach ($students as $student):?><tr><td><strong><?=e($student['last_name'].' '.$student['first_name'])?></strong></td><td class="score"><?=$student['student_average'] !== null ? e(display_decimal($student['student_average'])).' / 20' : '—'?></td><td><textarea name="comments[<?=$student['id']?>]" rows="3" maxlength="2000"><?=e($student['comment'])?></textarea></td></tr><?php endforeach?></tbody></table></div><div class="actions"><button>Enregistrer les appréciations</button></div></form><?php endif?>
    <?php footer_html();
    exit;
}
