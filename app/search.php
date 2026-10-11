<?php

declare(strict_types=1);

function render_search_page(): never
{
    require_login();
    $query = trim((string) ($_GET['q'] ?? ''));
    $students = [];
    $assessments = [];
    $families = [];

    if (mb_strlen($query) >= 2) {
        $like = '%' . $query . '%';
        $students = rows(
            'SELECT s.id,s.first_name,s.last_name,s.registration_number,c.level
             FROM students s
             LEFT JOIN enrollments e ON e.student_id=s.id
             LEFT JOIN classes c ON c.id=e.class_id
             WHERE s.active=1 AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.registration_number LIKE ?)
             ORDER BY s.last_name,s.first_name LIMIT 20',
            [$like, $like, $like]
        );
        $students = array_values(array_filter($students, fn (array $student): bool => can_view_student((int) $student['id'])));
        if (has_capability('can_teach') || has_capability('can_be_principal') || direction_mode_active()) {
            $allAssessments = rows(
                'SELECT a.id,a.title,a.assessment_date,co.id course_id,s.name subject,c.level
                 FROM assessments a JOIN courses co ON co.id=a.course_id
                 JOIN subjects s ON s.id=co.subject_id JOIN classes c ON c.id=co.class_id
                 WHERE a.title LIKE ? OR s.name LIKE ? OR c.level LIKE ?
                 ORDER BY a.assessment_date DESC LIMIT 30',
                [$like, $like, $like]
            );
            $assessments = array_values(array_filter($allAssessments, fn (array $item): bool => can_manage_course((int) $item['course_id'])));
        }
        if (management_mode_active()) {
            $families = rows(
                'SELECT id,family_label,city FROM families WHERE family_label LIKE ? OR city LIKE ? ORDER BY family_label LIMIT 20',
                [$like, $like]
            );
        }
    }

    header_html('Recherche'); ?>
    <section class="panel search-hero">
        <form method="get" role="search">
            <input type="hidden" name="page" value="search">
            <label for="global-search">Élève, évaluation, famille ou niveau</label>
            <div class="search-command"><input id="global-search" name="q" value="<?= e($query) ?>" minlength="2" autofocus placeholder="Saisissez au moins 2 caractères"><button>Rechercher</button></div>
        </form>
    </section>
    <?php if ($query !== '' && mb_strlen($query) < 2): ?><div class="alert info">Saisissez au moins deux caractères.</div><?php endif; ?>
    <?php if (mb_strlen($query) >= 2): ?>
        <div class="search-results">
            <section class="panel"><div class="panel-head"><h2>Élèves</h2><span class="badge"><?= count($students) ?></span></div>
                <?php foreach ($students as $student): ?><a class="result-row" href="?page=student-profile&amp;id=<?= $student['id'] ?>"><span><strong><?= e($student['last_name'] . ' ' . $student['first_name']) ?></strong><small><?= e($student['level'] ?: 'Sans niveau') ?> · <?= e($student['registration_number'] ?: 'INE non renseigné') ?></small></span><span aria-hidden="true">→</span></a><?php endforeach; ?>
                <?php if (!$students): ?><p class="empty">Aucun élève trouvé.</p><?php endif; ?>
            </section>
            <?php if ($assessments): ?><section class="panel"><div class="panel-head"><h2>Évaluations</h2><span class="badge"><?= count($assessments) ?></span></div>
                <?php foreach ($assessments as $assessment): ?><a class="result-row" href="?page=gradebook&amp;id=<?= $assessment['id'] ?>"><span><strong><?= e($assessment['title']) ?></strong><small><?= e($assessment['level'] . ' · ' . $assessment['subject']) ?> · <?= date('d/m/Y', strtotime($assessment['assessment_date'])) ?></small></span><span aria-hidden="true">→</span></a><?php endforeach; ?>
            </section><?php endif; ?>
            <?php if ($families): ?><section class="panel"><div class="panel-head"><h2>Familles</h2><span class="badge"><?= count($families) ?></span></div>
                <?php foreach ($families as $family): ?><a class="result-row" href="?page=families&amp;family_id=<?= $family['id'] ?>"><span><strong><?= e($family['family_label']) ?></strong><small><?= e($family['city'] ?: 'Ville non renseignée') ?></small></span><span aria-hidden="true">→</span></a><?php endforeach; ?>
            </section><?php endif; ?>
        </div>
    <?php endif; ?>
    <?php footer_html();
    exit;
}
