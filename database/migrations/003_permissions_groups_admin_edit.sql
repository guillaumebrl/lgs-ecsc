ALTER TABLE users ADD COLUMN can_teach BOOLEAN NOT NULL DEFAULT FALSE AFTER role;
ALTER TABLE users ADD COLUMN can_be_principal BOOLEAN NOT NULL DEFAULT FALSE AFTER can_teach;

UPDATE users SET can_teach=1 WHERE role IN ('teacher','principal');
UPDATE users SET can_be_principal=1 WHERE role='principal';

CREATE TABLE IF NOT EXISTS class_groups (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  class_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  UNIQUE KEY uq_class_group(class_id,name),
  FOREIGN KEY(class_id) REFERENCES classes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS group_students (
  group_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY(group_id,student_id),
  FOREIGN KEY(group_id) REFERENCES class_groups(id) ON DELETE CASCADE,
  FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE courses ADD COLUMN class_group_id BIGINT UNSIGNED NULL AFTER group_name;
ALTER TABLE courses ADD CONSTRAINT fk_course_group FOREIGN KEY(class_group_id) REFERENCES class_groups(id) ON DELETE SET NULL;

