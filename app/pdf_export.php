<?php

declare(strict_types=1);

use Dompdf\Dompdf;
use Dompdf\Options;

function pdf_styles(): string
{
    return '@page{size:A4 portrait;margin:0}*{box-sizing:border-box}html,body{margin:0;padding:0;color:#1d1e20;background:#fff;font-family:DejaVu Sans,sans-serif}.school-document{position:relative;width:186mm;height:273mm;margin:12mm;padding:0;overflow:hidden;page-break-inside:avoid}.document-header{position:relative;height:22mm;border-bottom:2px solid #2a5f91}.document-school-brand{position:absolute;left:0;top:0;width:54%}.document-logo{float:left;width:18mm;height:18mm;object-fit:contain;margin-right:3mm}.document-school-brand p{margin:1mm 0;font-size:8pt;line-height:1.15}.document-school-brand .eyebrow{padding-top:1.5mm;color:#2a5f91;font-weight:bold;text-transform:uppercase;letter-spacing:.5pt;font-size:9pt}.doc-title{position:absolute;right:0;top:2mm;width:44%;text-align:right}.doc-title h1{margin:0 0 2mm;font-size:18pt;line-height:1}.doc-title p{margin:0;font-size:9pt}.document-identity{height:24mm;margin:3mm 0 3mm;padding:3mm;border-left:1.2mm solid #2a5f91;background:#f2f5f8;font-size:9pt}.student-line,.recipient-line{width:100%;white-space:nowrap}.student-line{height:8mm;font-size:10pt}.student-line strong,.student-line span,.recipient-line strong,.recipient-details{display:inline-block;vertical-align:top;padding-right:2mm}.student-line strong{width:24%;font-weight:bold}.student-line span:nth-of-type(1){width:31%}.student-line span:nth-of-type(2){width:15%}.student-line span:nth-of-type(3){width:28%}.recipient-line{padding-top:1.5mm;font-size:8.5pt;line-height:1.25}.recipient-line strong{width:14%}.recipient-details{width:85%}.recipient-details span{display:block}.recipient-address{margin-top:1mm;color:#5f6873}table{width:100%;border-collapse:collapse;table-layout:fixed;font-size:7.5pt;line-height:1.08}th{padding:2mm 1.2mm;border-bottom:.4mm solid #d5e4f1;color:#5f6873;text-align:left;text-transform:uppercase;font-size:6.5pt}td{padding:1.6mm 1.2mm;border-bottom:.25mm solid #d5e4f1;vertical-align:top}td small{display:block;margin-top:.5mm;color:#5f6873;font-size:6pt}.bulletin-table th:nth-child(1){width:18%}.bulletin-table th:nth-child(2){width:5%}.bulletin-table th:nth-child(3),.bulletin-table th:nth-child(4),.bulletin-table th:nth-child(5),.bulletin-table th:nth-child(6){width:6%}.bulletin-table th:nth-child(7){width:53%}.number-cell{text-align:center;white-space:nowrap}.student-average{color:#2a5f91;font-weight:bold}.bulletin-table tfoot th,.bulletin-table tfoot td{border-top:.7mm solid #2a5f91;background:#edf4fa;font-size:8.5pt;font-weight:bold}.dense-document table{font-size:7pt}.dense-document th,.dense-document td{padding:1.2mm 1mm}.very-dense-document table{font-size:6.3pt}.very-dense-document th,.very-dense-document td{padding:.9mm}.very-dense-document .bulletin-table tbody td:first-child strong,.very-dense-document .bulletin-table tbody .number-cell{font-size:7.5pt}.maximum-density-document table{font-size:5.8pt}.maximum-density-document th,.maximum-density-document td{padding:.65mm}.maximum-density-document table small{font-size:5.3pt}.maximum-density-document .bulletin-table tbody td:first-child strong,.maximum-density-document .bulletin-table tbody .number-cell{font-size:7.2pt}.maximum-density-document .council-summary{margin-top:1.5mm;padding:2mm;font-size:7.5pt}.extreme-density-document table{font-size:4.6pt;line-height:1}.extreme-density-document th,.extreme-density-document td{padding:.3mm}.extreme-density-document th{font-size:4.8pt}.extreme-density-document table small{font-size:4.2pt}.extreme-density-document .bulletin-table tbody td:first-child strong,.extreme-density-document .bulletin-table tbody .number-cell{font-size:5.8pt}.extreme-density-document .council-summary{margin-top:1mm;padding:1.5mm;font-size:6.5pt}.extreme-density-document .council-summary p{margin-top:1mm}.council-summary{margin-top:3mm;padding:3mm;border:.7mm solid #2a5f91;background:#f4f8fc;font-size:8pt}.council-summary small{color:#2a5f91;font-weight:bold;text-transform:uppercase}.council-summary p{margin:2mm 0 0}.council-elements{margin-top:2mm;padding-top:2mm;border-top:.3mm solid #9cb9d3}.council-elements span{display:inline-block;width:48%}.council-elements strong{display:block;margin-top:1mm}.report-table th:first-child{width:24%}.report-table tbody td:first-child strong{font-size:8.5pt;line-height:1.1}.report-notes{line-height:1.1}.report-note{display:inline-block;min-width:13mm;margin:0 2mm 2mm 0;text-align:center;vertical-align:top}.report-note strong{display:block;color:#2a5f91;font-size:8pt}.report-note small{display:block;max-width:20mm;font-size:5.8pt}.no-grade{color:#5f6873;font-style:italic}.school-document>footer{position:absolute;left:0;right:0;bottom:0;color:#5f6873;font-size:6pt}.batch-document{page-break-after:always}.batch-document:last-child{page-break-after:auto}.student-line{font-size:9pt}.student-line strong{width:20%}.student-line span:nth-of-type(1){width:29%}.student-line span:nth-of-type(2){width:12%}.student-line span:nth-of-type(3){width:35%}.recipient-line strong{width:12%}.recipient-details{width:85%}.student-line strong,.student-line span{padding-right:1.5mm}.report-document .doc-title h1{font-size:16pt}.bulletin-table tbody td:nth-child(n+2){vertical-align:middle}.bulletin-table tbody td:first-child strong{font-size:8.5pt;line-height:1.1}.bulletin-table tbody .number-cell{font-size:8.5pt}.comment-cell,.council-summary p{white-space:pre-wrap;word-wrap:break-word;overflow-wrap:anywhere}.student-line{display:table;table-layout:fixed;width:100%;height:8mm;font-size:8.5pt}.student-line strong,.student-line span{display:table-cell;box-sizing:border-box;vertical-align:top;padding-right:1.5mm;overflow:hidden}.student-line strong{width:23%;white-space:normal;line-height:1.1}.student-line span:nth-of-type(1){width:32%}.student-line span:nth-of-type(2){width:15%}.student-line span:nth-of-type(3){width:30%;padding-right:0}';
}

function pdf_logo_data_uri(): string
{
    $path = dirname(__DIR__).'/public/assets/logo-sainte-charlotte.png';
    return 'data:image/png;base64,'.base64_encode((string)file_get_contents($path));
}

function capture_document_article(callable $renderer): string
{
    ob_start();
    $renderer();
    $html = (string)ob_get_clean();
    return str_replace('src="assets/logo-sainte-charlotte.png"', 'src="'.pdf_logo_data_uri().'"', $html);
}

function stream_school_pdf(string $title, string $articles, string $filename): never
{
    $autoload = dirname(__DIR__).'/vendor/autoload.php';
    if (!is_file($autoload)) {
        http_response_code(500);
        exit('Le moteur PDF n’est pas installé. Téléversez le dossier vendor.');
    }
    require_once $autoload;
    $options = new Options();
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->setChroot(dirname(__DIR__).'/public');
    $dompdf = new Dompdf($options);
    $dompdf->setPaper('A4', 'portrait');
    $html = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>'.e($title).'</title><style>'.pdf_styles().'</style></head><body class="document-body">'.$articles.'</body></html>';
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->render();
    $dompdf->stream($filename, ['Attachment' => true]);
    exit;
}

function render_bulletin_pdf(): never
{
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
    $article = capture_document_article(fn () => render_bulletin_article($student, $period, $classId));
    stream_school_pdf('Bulletin '.$student['first_name'].' '.$student['last_name'], $article, 'bulletin_'.preg_replace('/[^A-Za-z0-9_-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $student['last_name'].'_'.$student['first_name'])).'.pdf');
}

function render_report_pdf(): never
{
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
    }[$from,$to] = report_dates($period);
    $article = capture_document_article(fn () => render_report_article($student, $period, $classId, $from, $to));
    stream_school_pdf('Relevé '.$student['first_name'].' '.$student['last_name'], $article, 'releve_'.preg_replace('/[^A-Za-z0-9_-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $student['last_name'].'_'.$student['first_name'])).'.pdf');
}

function render_bulletin_batch_pdf(): never
{
    require_direction();
    $classId = (int)($_GET['class_id'] ?? 0);
    $periodId = (int)($_GET['period_id'] ?? 0);
    if (!can_manage_class($classId)) {
        http_response_code(403);
        exit('Accès interdit');
    }
    $class = one('SELECT c.*,y.name year_name FROM classes c JOIN school_years y ON y.id=c.school_year_id WHERE c.id=?', [$classId]);
    $period = one('SELECT * FROM periods WHERE id=? AND school_year_id=?', [$periodId,$class['school_year_id'] ?? 0]);
    if (!$class || !$period) {
        http_response_code(404);
        exit('Bulletins introuvables');
    }
    $students = rows('SELECT s.id FROM enrollments e JOIN students s ON s.id=e.student_id WHERE e.class_id=? AND s.active=1 ORDER BY s.last_name,s.first_name', [$classId]);
    $articles = '';
    foreach ($students as $item) {
        $student = document_student((int)$item['id'], $classId);
        if ($student) {
            $articles .= capture_document_article(fn () => render_bulletin_article($student, $period, $classId, true));
        }
    }
    stream_school_pdf('Bulletins '.$class['level'], $articles, 'bulletins_'.preg_replace('/[^A-Za-z0-9_-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $class['level'])).'.pdf');
}

function render_report_batch_pdf(): never
{
    require_direction();
    $classId = (int)($_GET['class_id'] ?? 0);
    $periodId = (int)($_GET['period_id'] ?? 0);
    if (!can_manage_class($classId)) {
        http_response_code(403);
        exit('Accès interdit');
    }
    $class = one('SELECT c.*,y.name year_name FROM classes c JOIN school_years y ON y.id=c.school_year_id WHERE c.id=?', [$classId]);
    $period = one('SELECT * FROM periods WHERE id=? AND school_year_id=?', [$periodId,$class['school_year_id'] ?? 0]);
    if (!$class || !$period) {
        http_response_code(404);
        exit('Relevés introuvables');
    }[$from,$to] = report_dates($period);
    $students = rows('SELECT s.id FROM enrollments e JOIN students s ON s.id=e.student_id WHERE e.class_id=? AND s.active=1 ORDER BY s.last_name,s.first_name', [$classId]);
    $articles = '';
    foreach ($students as $item) {
        $student = document_student((int)$item['id'], $classId);
        if ($student) {
            $articles .= capture_document_article(fn () => render_report_article($student, $period, $classId, $from, $to, true));
        }
    }
    stream_school_pdf('Relevés '.$class['level'], $articles, 'releves_'.preg_replace('/[^A-Za-z0-9_-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $class['level'])).'.pdf');
}
