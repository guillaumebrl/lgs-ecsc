<?php
declare(strict_types=1);

function document_no_cache_headers(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

function document_student(int $studentId, int $classId): ?array
{
    return one('SELECT s.*,c.level class_name,y.name year_name,f.family_label,f.address_line1,f.address_line2,f.postal_code,f.city FROM students s JOIN enrollments e ON e.student_id=s.id JOIN classes c ON c.id=e.class_id JOIN school_years y ON y.id=c.school_year_id LEFT JOIN families f ON f.id=s.family_id WHERE s.id=? AND c.id=?', [$studentId,$classId]);
}

function document_address(array $student): string
{
    return implode(' · ', array_values(array_filter([
        trim((string)($student['address_line1'] ?? '')),
        trim((string)($student['address_line2'] ?? '')),
        trim((string)($student['postal_code'] ?? '').' '.(string)($student['city'] ?? '')),
    ])));
}

function render_document_header(string $title, string $subtitle, array $student): void
{
    global $settings;
    $recipient = !empty($student['family_id']) ? family_addressee((int)$student['family_id']) : '';
    $birthDate = !empty($student['birth_date']) ? date('d/m/Y', strtotime($student['birth_date'])) : 'Non renseignée';
    ?><header class="document-header"><div class="document-school-brand"><img class="document-logo" src="assets/logo-sainte-charlotte.png" alt="Logo Sainte Charlotte"><div><p class="eyebrow"><?=e($settings['school_name'])?></p><p><?=nl2br(e($settings['school_address']))?></p></div></div><div class="doc-title"><h1><?=e($title)?></h1><p><?=e($subtitle)?></p></div></header><section class="document-identity"><div class="student-line"><strong><?=e($student['first_name'].' '.$student['last_name'])?></strong><span>Date de naissance : <?=e($birthDate)?></span><span>Classe : <?=e($student['class_name'])?></span><span>N° INE : <?=e($student['registration_number'] ?: 'Non renseigné')?></span></div><div class="recipient-line"><strong>Resp. légal :</strong><div class="recipient-details"><span><?=e($recipient ?: ($student['family_label'] ?? 'Non renseigné'))?></span><?php if (document_address($student) !== ''):?><span class="recipient-address"><?=e(document_address($student))?></span><?php endif?></div></div></section><?php
}

function bulletin_data(int $studentId, int $classId, int $periodId): array
{
    $summary = rows("SELECT co.id course_id,s.name subject,co.assignment_coefficient,co.display_order,GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') teachers,ROUND(SUM((g.score/a.scale*20)*a.coefficient)/NULLIF(SUM(a.coefficient),0),2) average,MAX(sc.comment) comment FROM courses co JOIN subjects s ON s.id=co.subject_id LEFT JOIN course_teachers ct ON ct.course_id=co.id LEFT JOIN users u ON u.id=ct.user_id LEFT JOIN assessments a ON a.course_id=co.id AND a.period_id=? LEFT JOIN grades g ON g.assessment_id=a.id AND g.student_id=? AND g.special_status IS NULL LEFT JOIN subject_comments sc ON sc.course_id=co.id AND sc.period_id=? AND sc.student_id=? WHERE co.class_id=? AND (co.class_group_id IS NULL OR EXISTS(SELECT 1 FROM group_students gs WHERE gs.group_id=co.class_group_id AND gs.student_id=?)) GROUP BY co.id,s.name,co.assignment_coefficient,co.display_order ORDER BY co.display_order,s.name", [$periodId,$studentId,$periodId,$studentId,$classId,$studentId]);
    $classAverages = rows('SELECT co.id course_id,co.assignment_coefficient,g.student_id,ROUND(SUM((g.score/a.scale*20)*a.coefficient)/NULLIF(SUM(a.coefficient),0),2) average FROM courses co JOIN assessments a ON a.course_id=co.id AND a.period_id=? JOIN grades g ON g.assessment_id=a.id AND g.special_status IS NULL JOIN enrollments e ON e.student_id=g.student_id AND e.class_id=co.class_id WHERE co.class_id=? AND (co.class_group_id IS NULL OR EXISTS(SELECT 1 FROM group_students gs WHERE gs.group_id=co.class_group_id AND gs.student_id=g.student_id)) GROUP BY co.id,co.assignment_coefficient,g.student_id', [$periodId,$classId]);
    $byCourse = [];
    $byStudent = [];
    foreach ($classAverages as $row) {
        $courseId = (int)$row['course_id'];
        $sid = (int)$row['student_id'];
        $average = (float)$row['average'];
        $byCourse[$courseId][] = $average;
        $coef = max(0, (float)$row['assignment_coefficient']);
        $byStudent[$sid]['total'] = ($byStudent[$sid]['total'] ?? 0) + $average * $coef;
        $byStudent[$sid]['coefficients'] = ($byStudent[$sid]['coefficients'] ?? 0) + $coef;
    }
    foreach ($summary as &$subject) {
        $values = $byCourse[(int)$subject['course_id']] ?? [];
        $subject['class_min'] = $values ? min($values) : null;
        $subject['class_average'] = $values ? array_sum($values) / count($values) : null;
        $subject['class_max'] = $values ? max($values) : null;
    }unset($subject);
    $general = [];
    foreach ($byStudent as $values) {
        if ($values['coefficients'] > 0) {
            $general[] = $values['total'] / $values['coefficients'];
        }
    }
    return ['summary' => $summary,'student_average' => weighted_subject_average($summary),'class_min' => $general ? min($general) : null,'class_average' => $general ? array_sum($general) / count($general) : null,'class_max' => $general ? max($general) : null];
}

function document_number(?float $value): string
{
    global $settings;
    return $value === null ? '—' : number_format($value, (int)$settings['decimals'], ',', ' ');
}

function document_grade_number(float|string|null $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    return rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
}

function render_bulletin_article(array $student, array $period, int $classId, bool $batch = false): void
{
    $data = bulletin_data((int)$student['id'], $classId, (int)$period['id']);
    $council = one('SELECT * FROM councils WHERE student_id=? AND class_id=? AND period_id=?', [$student['id'],$classId,$period['id']]);
    $commentLength = mb_strlen((string)($council['general_comment'] ?? ''));
    foreach ($data['summary'] as $subject) {
        $commentLength += mb_strlen((string)($subject['comment'] ?? ''));
    }
    $subjectCount = count($data['summary']);
    $density = ($subjectCount > 15 && $commentLength > 1800) || $commentLength > 3200 ? 'extreme-density-document' : ($subjectCount > 18 || $commentLength > 2200 ? 'maximum-density-document' : ($subjectCount > 15 || $commentLength > 1200 ? 'very-dense-document' : ($subjectCount > 11 || $commentLength > 500 ? 'dense-document' : '')));
    ?><article class="school-document bulletin-document <?=$density?> <?=$batch ? 'batch-document' : ''?>"><?php render_document_header('Bulletin scolaire', $period['name'].' · '.$student['year_name'], $student)?><table class="bulletin-table"><thead><tr><th>Matière / enseignant</th><th>Coef.</th><th>Moy.<br>élève</th><th>Min.<br>classe</th><th>Moy.<br>classe</th><th>Max.<br>classe</th><th>Appréciation</th></tr></thead><tbody><?php foreach ($data['summary'] as $subject):?><tr><td><strong><?=e($subject['subject'])?></strong><small><?=e($subject['teachers'] ?: 'Enseignant non renseigné')?></small></td><td class="number-cell"><?=number_format((float)$subject['assignment_coefficient'], 0, ',', ' ')?></td><td class="number-cell student-average"><?=document_number($subject['average'] !== null ? (float)$subject['average'] : null)?></td><td class="number-cell"><?=document_number($subject['class_min'])?></td><td class="number-cell"><?=document_number($subject['class_average'])?></td><td class="number-cell"><?=document_number($subject['class_max'])?></td><td class="comment-cell"><?=e($subject['comment'] ?: '')?></td></tr><?php endforeach?></tbody><tfoot><tr><th colspan="2">Moyenne générale</th><td class="number-cell student-average"><?=document_number($data['student_average'])?></td><td class="number-cell"><?=document_number($data['class_min'])?></td><td class="number-cell"><?=document_number($data['class_average'])?></td><td class="number-cell"><?=document_number($data['class_max'])?></td><td></td></tr></tfoot></table><section class="council-summary <?=empty($council['mention']) && empty($council['decision_text']) ? 'without-council-elements' : ''?>"><div><small>Appréciation générale</small><p><?=e($council['general_comment'] ?? 'Non renseignée')?></p></div><?php if (!empty($council['mention']) || !empty($council['decision_text'])):?><div class="council-elements"><?php if (!empty($council['mention'])):?><span><small>Mention</small><strong><?=e($council['mention'])?></strong></span><?php endif?><?php if (!empty($council['decision_text'])):?><span><small>Décision du conseil</small><strong><?=e($council['decision_text'])?></strong></span><?php endif?></div><?php endif?></section><footer>Document édité le <?=date('d/m/Y à H:i')?> · lgs-ecsc</footer></article><?php
}

function render_bulletin_page(): never
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
        exit('Bulletin introuvable');
    }
    ?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Bulletin — <?=e($student['last_name'])?></title><link rel="stylesheet" href="assets/app.css?v=71"><link rel="stylesheet" href="assets/branding.css?v=84"></head><body class="document-body"><div class="print-actions"><a class="button secondary" href="javascript:history.back()">Retour</a><a class="button" href="?page=bulletin-pdf&amp;student_id=<?=$studentId?>&amp;class_id=<?=$classId?>&amp;period_id=<?=$periodId?>">Télécharger le PDF</a></div><?php render_bulletin_article($student, $period, $classId)?></body></html><?php exit;
}

function report_details(int $studentId, int $classId, string $from, string $to): array
{
    $rows = rows("SELECT a.id assessment_id,a.title,a.assessment_date,s.name subject,a.scale,g.score,g.special_status,co.id course_id,co.display_order,GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') teachers FROM courses co JOIN subjects s ON s.id=co.subject_id LEFT JOIN course_teachers ct ON ct.course_id=co.id LEFT JOIN users u ON u.id=ct.user_id LEFT JOIN assessments a ON a.course_id=co.id AND a.assessment_date BETWEEN ? AND ? LEFT JOIN grades g ON g.assessment_id=a.id AND g.student_id=? WHERE co.class_id=? AND (co.class_group_id IS NULL OR EXISTS(SELECT 1 FROM group_students gs WHERE gs.group_id=co.class_group_id AND gs.student_id=?)) GROUP BY co.id,co.display_order,s.name,a.id,a.title,a.assessment_date,a.scale,g.score,g.special_status ORDER BY co.display_order,s.name,a.assessment_date", [$from,$to,$studentId,$classId,$studentId]);
    $grouped = [];
    foreach ($rows as $row) {
        $key = (string)$row['course_id'];
        if (!isset($grouped[$key])) {
            $grouped[$key] = ['subject' => $row['subject'],'teachers' => $row['teachers'],'notes' => []];
        }if ($row['assessment_id'] !== null) {
            $grouped[$key]['notes'][] = $row;
        }
    }
    return array_values($grouped);
}

function render_report_article(array $student, array $period, int $classId, string $from, string $to, bool $batch = false): void
{
    $subjects = report_details((int)$student['id'], $classId, $from, $to);
    $noteCount = array_sum(array_map(fn (array $subject): int => count($subject['notes']), $subjects));
    $density = count($subjects) > 15 || $noteCount > 70 ? 'very-dense-document' : (count($subjects) > 11 || $noteCount > 45 ? 'dense-document' : '');
    ?><article class="school-document report-document <?=$density?> <?=$batch ? 'batch-document' : ''?>"><?php render_document_header('Relevé de notes', 'Du '.date('d/m/Y', strtotime($from)).' au '.date('d/m/Y', strtotime($to)), $student)?><table class="report-table"><thead><tr><th>Matière / enseignant</th><th>Notes de la période</th></tr></thead><tbody><?php foreach ($subjects as $subject):?><tr><td><strong><?=e($subject['subject'])?></strong><small><?=e($subject['teachers'] ?: 'Enseignant non renseigné')?></small></td><td><div class="report-notes"><?php if (!$subject['notes']):?><span class="no-grade">Aucune note sur la période</span><?php endif?><?php foreach ($subject['notes'] as $note):?><span class="report-note"><strong><?=$note['special_status'] ? e($note['special_status']) : ($note['score'] !== null ? e(document_grade_number($note['score']).' / '.document_grade_number($note['scale'])) : '—')?></strong><small><?=e($note['title'])?></small></span><?php endforeach?></div></td></tr><?php endforeach?></tbody></table><footer>Document édité le <?=date('d/m/Y à H:i')?> · lgs-ecsc</footer></article><?php
}
