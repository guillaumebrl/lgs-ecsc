ALTER TABLE courses
  ADD COLUMN assignment_coefficient DECIMAL(6,2) NOT NULL DEFAULT 1 AFTER class_group_id,
  ADD COLUMN display_order SMALLINT UNSIGNED NOT NULL DEFAULT 100 AFTER assignment_coefficient;

UPDATE courses co
JOIN subjects s ON s.id=co.subject_id
SET co.assignment_coefficient=s.coefficient;
