<?php
declare(strict_types=1);
function handle_school_life_actions(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }$action = $_POST['action'] ?? '';
    if (!in_array($action, ['add_attendance','update_attendance','delete_attendance'], true)) {
        return;
    }
    require_school_life_mode();
    if ($action === 'delete_attendance') {
        $id = (int)$_POST['attendance_id'];
        $before = one('SELECT * FROM attendance WHERE id=?', [$id]);
        if ($before) {
            db()->prepare('DELETE FROM attendance WHERE id=?')->execute([$id]);
            audit('delete', 'attendance', $id, $before);
            flash('Événement supprimé.');
        }
        $focusStudentId = (int)($before['student_id'] ?? 0);
        redirect('index.php?page=school-life'.($focusStudentId ? '&student_id='.$focusStudentId.'#student-'.$focusStudentId : ''));
    }
    $studentId = (int)$_POST['student_id'];
    $periodId = (int)$_POST['period_id'];
    if (!$studentId || !one('SELECT id FROM students WHERE id=? AND active=1', [$studentId]) || !$periodId || !one('SELECT id FROM periods WHERE id=?', [$periodId])) {
        flash('Élève ou période invalide.', 'error');
        redirect('index.php?page=school-life');
    }
    $kind = in_array($_POST['kind'] ?? '', ['absence','late'], true) ? $_POST['kind'] : 'absence';
    $duration = $kind === 'late' ? max(1, (int)($_POST['duration_minutes'] ?? 1)) : null;
    $values = [$studentId,$periodId,$kind,$_POST['occurred_at'],$duration,isset($_POST['justified']) ? 1 : 0,trim($_POST['reason'] ?? '') ?: null];
    if ($action === 'update_attendance') {
        $id = (int)$_POST['attendance_id'];
        $before = one('SELECT * FROM attendance WHERE id=?', [$id]);
        db()->prepare('UPDATE attendance SET student_id=?,period_id=?,kind=?,occurred_at=?,duration_minutes=?,justified=?,reason=? WHERE id=?')->execute([...$values,$id]);
        audit('update', 'attendance', $id, $before, $values);
        flash('Événement modifié.');
    } else {
        db()->prepare('INSERT INTO attendance(student_id,period_id,kind,occurred_at,duration_minutes,justified,reason,author_id) VALUES(?,?,?,?,?,?,?,?)')->execute([...$values,user()['id']]);
        audit('create', 'attendance', (int)db()->lastInsertId());
        flash('Événement enregistré.');
    }
    redirect('index.php?page=school-life&student_id='.$studentId.'#student-'.$studentId);
}
function render_school_life_page(): never
{
    require_school_life_mode();
    $yearId = (int)(db()->query('SELECT COALESCE((SELECT id FROM school_years WHERE active=1 ORDER BY starts_on DESC LIMIT 1),(SELECT id FROM school_years ORDER BY starts_on DESC LIMIT 1),0)')->fetchColumn() ?: 0);
    $students = $yearId ? rows('SELECT DISTINCT s.id,s.last_name,s.first_name,c.level,c.sort_order FROM students s JOIN enrollments e ON e.student_id=s.id JOIN classes c ON c.id=e.class_id WHERE s.active=1 AND c.school_year_id=? ORDER BY c.sort_order,s.last_name,s.first_name', [$yearId]) : [];
    $periods = $yearId ? rows('SELECT id,name label FROM periods WHERE school_year_id=? ORDER BY sort_order', [$yearId]) : [];
    $events = rows('SELECT a.*,s.last_name,s.first_name,c.level FROM attendance a JOIN students s ON s.id=a.student_id JOIN periods p ON p.id=a.period_id LEFT JOIN enrollments e ON e.student_id=s.id LEFT JOIN classes c ON c.id=e.class_id AND c.school_year_id=p.school_year_id ORDER BY a.occurred_at DESC LIMIT 100');
    $studentId = (int)($_GET['student_id'] ?? 0);
    $kind = in_array($_GET['kind'] ?? '', ['absence','late'], true) ? $_GET['kind'] : '';
    $editId = (int)($_GET['edit_event'] ?? 0);
    $edit = $editId ? one('SELECT * FROM attendance WHERE id=?', [$editId]) : null;
    header_html('Vie scolaire');?><link rel="stylesheet" href="assets/school-life.css?v=99">
    <section class="panel school-life-students"><div class="panel-head"><div><p class="eyebrow">Saisie rapide</p><h2>Choisir un élève</h2></div><input class="search" type="search" placeholder="Rechercher par nom ou niveau…" data-filter-table></div><div class="table-wrap"><table data-table><thead><tr><th>Élève</th><th>Niveau</th><th>Nouvel événement</th></tr></thead><tbody><?php foreach ($students as $s):$open = $studentId === (int)$s['id'] && $kind;
        $focused = $studentId === (int)$s['id'];?><tr id="student-<?=$s['id']?>" class="<?=$focused ? 'attendance-student-focus' : ''?>"><td><strong><?=e($s['last_name'].' '.$s['first_name'])?></strong></td><td><?=e($s['level'])?></td><td><div class="quick-actions"><a class="button secondary" href="?page=school-life&amp;student_id=<?=$s['id']?>&amp;kind=absence#student-<?=$s['id']?>">Absence</a><a class="button secondary" href="?page=school-life&amp;student_id=<?=$s['id']?>&amp;kind=late#student-<?=$s['id']?>">Retard</a></div></td></tr><?php if ($open):?><tr class="attendance-entry-row"><td colspan="3"><?php render_attendance_form(null, $s, $kind, $periods)?></td></tr><?php endif?><?php endforeach?></tbody></table></div></section>
    <section class="panel"><p class="eyebrow">Suivi récent</p><h2>Absences et retards</h2><div class="table-wrap"><table><thead><tr><th>Date</th><th>Élève</th><th>Événement</th><th>État</th><th>Actions</th></tr></thead><tbody><?php foreach ($events as $event):?><tr><td><?=date('d/m/Y H:i', strtotime($event['occurred_at']))?></td><td><strong><?=e($event['last_name'].' '.$event['first_name'])?></strong><small><?=e($event['level'])?></small></td><td><?=e($event['kind'] === 'late' ? 'Retard de '.$event['duration_minutes'].' min' : 'Absence')?><small><?=e($event['reason'])?></small></td><td><?=$event['justified'] ? 'Justifié' : 'À justifier'?></td><td><span class="table-actions"><a class="icon-button" title="Modifier" aria-label="Modifier" href="?page=school-life&amp;edit_event=<?=$event['id']?>"><i class="bi bi-pencil-square"></i></a><form method="post" onsubmit="return confirm('Supprimer cet événement ?')"><input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="delete_attendance"><input type="hidden" name="attendance_id" value="<?=$event['id']?>"><button class="icon-button danger" title="Supprimer" aria-label="Supprimer"><i class="bi bi-trash"></i></button></form></span></td></tr><?php if ($editId === (int)$event['id']):?><tr><td colspan="5"><?php render_attendance_form($edit, ['id' => $event['student_id'],'first_name' => $event['first_name'],'last_name' => $event['last_name']], $event['kind'], $periods)?></td></tr><?php endif?><?php endforeach?><?php if (!$events):?><tr><td colspan="5" class="empty">Aucun événement.</td></tr><?php endif?></tbody></table></div></section><?php footer_html();
    exit;
}
function render_attendance_form(?array $event, array $student, string $kind, array $periods): void
{?>
<form class="attendance-inline-form" method="post"><input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="<?=$event ? 'update_attendance' : 'add_attendance'?>"><?php if ($event):?><input type="hidden" name="attendance_id" value="<?=$event['id']?>"><?php endif?><input type="hidden" name="student_id" value="<?=$student['id']?>"><label>Événement<select name="kind"><option value="absence" <?=$kind === 'absence' ? 'selected' : ''?>>Absence</option><option value="late" <?=$kind === 'late' ? 'selected' : ''?>>Retard</option></select></label><label>Période<select required name="period_id"><?php foreach ($periods as $p):?><option value="<?=$p['id']?>" <?=($event['period_id'] ?? 0) === $p['id'] ? 'selected' : ''?>><?=e($p['label'])?></option><?php endforeach?></select></label><label>Date et heure<input required type="datetime-local" name="occurred_at" value="<?=e($event ? date('Y-m-d\TH:i', strtotime($event['occurred_at'])) : date('Y-m-d\TH:i'))?>"></label><label>Durée si retard<input type="number" min="1" name="duration_minutes" value="<?=e($event['duration_minutes'] ?? 5)?>"></label><label>Motif<input name="reason" value="<?=e($event['reason'] ?? '')?>"></label><label class="check"><input type="checkbox" name="justified" value="1" <?=($event['justified'] ?? 0) ? 'checked' : ''?>> Justifié</label><div class="quick-actions"><button>Enregistrer</button><a class="button secondary" href="?page=school-life">Annuler</a></div></form><?php }
