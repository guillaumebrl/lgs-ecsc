<?php

declare(strict_types=1);

function render_assessment_gradebook_page(): never
{
    $assessment = one(
        'SELECT a.*, s.name subject, c.name class_name,
                co.class_id, co.class_group_id,
                p.name period_name, p.status period_status,
                u.name author
         FROM assessments a
         JOIN courses co ON co.id = a.course_id
         JOIN subjects s ON s.id = co.subject_id
         JOIN classes c ON c.id = co.class_id
         JOIN periods p ON p.id = a.period_id
         JOIN users u ON u.id = a.author_id
         WHERE a.id = ?',
        [(int) ($_GET['id'] ?? 0)]
    );

    if (!$assessment || !can_manage_course((int) $assessment['course_id'])) {
        http_response_code(404);
        exit('Évaluation introuvable');
    }

    $fallbackReturn = '?page=grade-notebook&course_id='.(int)$assessment['course_id'].'&period='.(int)$assessment['period_id'];
    $requestedReturn = (string)($_GET['return_to'] ?? '');
    $returnTo = str_starts_with($requestedReturn, '?page=grade-notebook') ? $requestedReturn : $fallbackReturn;

    $students = rows(
        'SELECT s.id, s.last_name, s.first_name, g.score, g.special_status
         FROM enrollments e
         JOIN students s ON s.id = e.student_id
         LEFT JOIN grades g
            ON g.student_id = s.id AND g.assessment_id = ?
         WHERE e.class_id = ?
           AND s.active = 1
           AND (
               ? IS NULL
               OR EXISTS (
                   SELECT 1
                   FROM group_students gs
                   WHERE gs.student_id = s.id AND gs.group_id = ?
               )
           )
         ORDER BY s.last_name, s.first_name',
        [
            $assessment['id'],
            $assessment['class_id'],
            $assessment['class_group_id'],
            $assessment['class_group_id'],
        ]
    );

    header_html($assessment['title']);
    ?>
    <section class="panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow">
                    <?= e($assessment['class_name']) ?> ·
                    <?= e($assessment['subject']) ?> ·
                    <?= e($assessment['period_name']) ?>
                </p>
                <h2>
                    Saisie sur <?= e(display_decimal($assessment['scale'])) ?> ·
                    coefficient <?= e(display_decimal($assessment['coefficient'])) ?>
                </h2>
            </div>
            <span class="tag"><?= e(period_status_label($assessment['period_status'])) ?></span>
        </div>
        <form method="post">
            <input type="hidden" name="_token" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="save_grades">
            <input type="hidden" name="assessment_id" value="<?= $assessment['id'] ?>">
            <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
            <table class="grade-table">
                <thead>
                <tr>
                    <th>Élève</th>
                    <th>Note / <?= e(display_decimal($assessment['scale'])) ?></th>
                    <th>Statut particulier</th>
                    <th>Équivalent</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($students as $student): ?>
                    <tr>
                        <td><strong><?= e($student['last_name'] . ' ' . $student['first_name']) ?></strong></td>
                        <td>
                            <input
                                aria-label="Note de <?= e($student['first_name']) ?>"
                                type="number"
                                min="0"
                                max="<?= e($assessment['scale']) ?>"
                                step="0.01"
                                name="students[<?= $student['id'] ?>][score]"
                                value="<?= $student['score'] !== null ? e(input_decimal($student['score'])) : '' ?>"
                            >
                        </td>
                        <td>
                            <select name="students[<?= $student['id'] ?>][status]">
                                <option value="">Noté</option>
                                <?php foreach (grade_status_labels() as $value => $label): ?>
                                    <option
                                        value="<?= e($value) ?>"
                                        <?= $student['special_status'] === $value ? 'selected' : '' ?>
                                    ><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td
                            class="normalized"
                            data-scale="<?= e($assessment['scale']) ?>"
                            data-target="<?= e($assessment['normalize_to']) ?>"
                        >—</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="actions">
                <a class="button secondary" href="?page=grades">Retour</a>
                <a class="button secondary" href="<?= e($returnTo) ?>">Retour au carnet de notes</a>
                <button
                    <?= $assessment['period_status'] !== 'open' ? 'disabled' : '' ?>
                >Enregistrer les notes</button>
            </div>
        </form>
    </section>
    <?php
    footer_html();
    exit;
}

function grade_status_labels(): array
{
    return [
        'absent' => 'Absent',
        'excused' => 'Dispensé',
        'missing' => 'Non rendu',
        'not_graded' => 'Non noté',
    ];
}
