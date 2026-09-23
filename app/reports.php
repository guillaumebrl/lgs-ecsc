<?php
declare(strict_types=1);

function report_dates(array $period): array
{
    $from = $_GET['from'] ?? $period['starts_on'];
    $to = $_GET['to'] ?? $period['ends_on'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$from)) {
        $from = $period['starts_on'];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$to)) {
        $to = $period['ends_on'];
    }
    if ($from > $to) {
        [$from,$to] = [$to,$from];
    }
    return [$from,$to];
}

function render_report_page(): never
{
    document_no_cache_headers();
    $studentId = (int)($_GET['student_id'] ?? 0);
    $classId = (int)($_GET['class_id'] ?? 0);
    $periodId = (int)($_GET['period_id'] ?? 0);
    if (!can_manage_council_scope($classId, $studentId, $periodId)) {
        http_response_code(403);
        exit('Accès interdit');
    }
    $student = document_student($studentId, $classId);
    $period = one('SELECT * FROM periods WHERE id=?', [$periodId]);
    if (!$student || !$period) {
        http_response_code(404);
        exit('Relevé introuvable');
    }
    [$from,$to] = report_dates($period);
    ?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Relevé — <?=e($student['last_name'])?></title><link rel="stylesheet" href="assets/app.css?v=71"><link rel="stylesheet" href="assets/branding.css?v=84"></head><body class="document-body"><div class="print-actions"><a class="button secondary" href="javascript:history.back()">Retour</a><form method="get"><input type="hidden" name="page" value="report"><input type="hidden" name="student_id" value="<?=$studentId?>"><input type="hidden" name="class_id" value="<?=$classId?>"><input type="hidden" name="period_id" value="<?=$periodId?>"><input type="date" name="from" value="<?=e($from)?>"><input type="date" name="to" value="<?=e($to)?>"><button>Actualiser</button></form><a class="button" href="?page=report-pdf&amp;student_id=<?=$studentId?>&amp;class_id=<?=$classId?>&amp;period_id=<?=$periodId?>&amp;from=<?=e($from)?>&amp;to=<?=e($to)?>">Télécharger le PDF</a></div><?php render_report_article($student, $period, $classId, $from, $to)?></body></html><?php exit;
}

function render_batch_reports(): never
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
        exit('Relevés introuvables');
    }
    [$from,$to] = report_dates($period);
    $students = rows('SELECT s.id FROM enrollments e JOIN students s ON s.id=e.student_id WHERE e.class_id=? AND s.active=1 ORDER BY s.last_name,s.first_name', [$classId]);
    ?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Relevés — <?=e($class['level'])?></title><link rel="stylesheet" href="assets/app.css?v=70"><link rel="stylesheet" href="assets/branding.css?v=84"><link rel="stylesheet" href="assets/direction.css?v=70"></head><body class="document-body"><div class="print-actions"><a class="button secondary" href="?page=documents&amp;section=reports&amp;class_id=<?=$classId?>&amp;period_id=<?=$periodId?>&amp;from=<?=e($from)?>&amp;to=<?=e($to)?>">Retour</a><button onclick="window.print()">Enregistrer le PDF du niveau / Imprimer</button></div><?php foreach ($students as $item):$student = document_student((int)$item['id'], $classId);
        if ($student) {
            render_report_article($student, $period, $classId, $from, $to, true);
        }endforeach?></body></html><?php exit;
}
