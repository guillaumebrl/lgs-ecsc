<?php

declare(strict_types=1);

$appDirectory = dirname(__DIR__) . '/app';

require $appDirectory . '/bootstrap.php';
require $appDirectory . '/query.php';
require $appDirectory . '/authorization.php';
require $appDirectory . '/layout.php';
require $appDirectory . '/authentication.php';
require $appDirectory . '/application_actions.php';

$page = $_GET['page'] ?? 'dashboard';

if ($page === 'login') {
    handle_login_page();
}
if ($page === 'logout') {
    handle_logout();
}

require_login();
verify_csrf();

if (!empty(user()['must_change_password']) && $page !== 'change-password') {
    redirect('index.php?page=change-password');
}

$pdo = db();
$settings = $pdo->query('SELECT * FROM settings WHERE id = 1')->fetch();

handle_work_mode_action();

$modules = [
    'families', 'document_print', 'pdf_export', 'admin_management',
    'student_management', 'direction', 'structure', 'accounts',
    'my_students', 'school_life', 'reports', 'grades', 'grade_notebook',
    'dashboard', 'comments', 'documents', 'council', 'settings',
    'assessment_gradebook',
];
foreach ($modules as $module) {
    require $appDirectory . '/' . $module . '.php';
}

handle_family_actions();
handle_admin_management_actions();
handle_student_management_actions();
handle_direction_actions();
handle_account_actions();
handle_school_life_actions();
handle_grade_actions();
handle_comment_actions();
handle_application_actions($settings);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$routes = [
    'families' => 'render_families_page',
    'admin-management' => 'render_admin_management_page',
    'student-management' => 'render_student_management_page',
    'student-detail' => 'render_student_detail_page',
    'students' => 'render_students_page_with_birthdate',
    'bulletin-batch' => 'render_batch_bulletins',
    'bulletin' => 'render_bulletin_page',
    'report-batch' => 'render_batch_reports',
    'bulletin-pdf' => 'render_bulletin_pdf',
    'report-pdf' => 'render_report_pdf',
    'bulletin-batch-pdf' => 'render_bulletin_batch_pdf',
    'report-batch-pdf' => 'render_report_batch_pdf',
    'structure' => 'render_structure_page',
    'users' => 'render_accounts_page',
    'my-students' => 'render_my_students_page',
    'school-life' => 'render_school_life_page',
    'change-password' => 'render_change_password_page',
    'report' => 'render_report_page',
    'grades' => 'render_grades_page',
    'grade-notebook' => 'render_grade_notebook_page',
    'comments' => 'render_comments_page',
    'dashboard' => 'render_dashboard_page',
    'documents' => 'render_documents_page',
    'council' => 'render_council_page',
    'settings' => 'render_settings_page',
    'gradebook' => 'render_assessment_gradebook_page',
];

if ($page === 'direction') {
    redirect('index.php?page=documents');
}

$renderer = $routes[$page] ?? null;
if ($renderer === null || !is_callable($renderer)) {
    http_response_code(404);
    exit('Page introuvable');
}

$renderer();
