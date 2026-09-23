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
