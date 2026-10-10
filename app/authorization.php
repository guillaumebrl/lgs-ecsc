<?php

declare(strict_types=1);

function can_manage_class(int $classId): bool
{
    if (direction_mode_active()) {
        return true;
    }

    if (!principal_mode_active()) {
        return false;
    }

    $statement = db()->prepare(
        'SELECT COUNT(*) FROM class_principals WHERE class_id = ? AND user_id = ?'
    );
    $statement->execute([$classId, user()['id']]);

    return (int) $statement->fetchColumn() > 0;
}

function can_manage_council_scope(int $classId, int $studentId, int $periodId): bool
{
    if (!can_manage_class($classId)) {
        return false;
    }

    $statement = db()->prepare(
        'SELECT COUNT(*)
         FROM enrollments e
         JOIN classes c ON c.id = e.class_id
         JOIN periods p ON p.school_year_id = c.school_year_id
         WHERE e.class_id = ? AND e.student_id = ? AND p.id = ?'
    );
    $statement->execute([$classId, $studentId, $periodId]);

    return (int) $statement->fetchColumn() > 0;
}

function can_view_student(int $studentId): bool
{
    if (management_mode_active()) {
        return true;
    }
    if (!has_capability('can_teach') && !has_capability('can_be_principal')) {
        return false;
    }
    $statement = db()->prepare(
        'SELECT COUNT(DISTINCT e.student_id)
         FROM enrollments e JOIN classes c ON c.id=e.class_id
         LEFT JOIN class_principals cp ON cp.class_id=c.id AND cp.user_id=?
         LEFT JOIN courses co ON co.class_id=c.id
         LEFT JOIN course_teachers ct ON ct.course_id=co.id AND ct.user_id=?
         WHERE e.student_id=? AND (cp.user_id IS NOT NULL OR (ct.user_id IS NOT NULL AND (co.class_group_id IS NULL OR EXISTS(SELECT 1 FROM group_students gs WHERE gs.group_id=co.class_group_id AND gs.student_id=e.student_id))))'
    );
    $statement->execute([user()['id'], user()['id'], $studentId]);

    return (int) $statement->fetchColumn() > 0;
}

function can_manage_course(int $courseId): bool
{
    if (direction_mode_active()) {
        return true;
    }

    $statement = db()->prepare(
        'SELECT COUNT(*)
         FROM courses co
         LEFT JOIN course_teachers ct
            ON ct.course_id = co.id AND ct.user_id = ?
         JOIN classes c ON c.id = co.class_id
         WHERE co.id = ?
           AND (ct.user_id IS NOT NULL OR (EXISTS(SELECT 1 FROM class_principals cp WHERE cp.class_id=c.id AND cp.user_id=?) AND ? = 1))'
    );
    $statement->execute([
        user()['id'],
        $courseId,
        user()['id'],
        principal_mode_active() ? 1 : 0,
    ]);

    return (int) $statement->fetchColumn() > 0;
}

function can_create_assessment_for_course(int $courseId): bool
{
    if (is_admin()) {
        return true;
    }

    $statement = db()->prepare(
        'SELECT COUNT(*) FROM course_teachers WHERE course_id = ? AND user_id = ?'
    );
    $statement->execute([$courseId, user()['id']]);

    return (int) $statement->fetchColumn() > 0;
}

function normalized(array $grade): ?float
{
    if ($grade['score'] === null || $grade['special_status']) {
        return null;
    }

    $target = $grade['normalize_to'] === '10' ? 10 : 20;

    return ((float) $grade['score'] / (float) $grade['scale']) * $target;
}
