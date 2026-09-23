-- Autorise plusieurs professeurs principaux ou instituteurs par niveau.
CREATE TABLE IF NOT EXISTS class_principals (
  class_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY(class_id,user_id),
  FOREIGN KEY(class_id) REFERENCES classes(id) ON DELETE CASCADE,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO class_principals(class_id,user_id)
SELECT id,principal_user_id FROM classes WHERE principal_user_id IS NOT NULL;
