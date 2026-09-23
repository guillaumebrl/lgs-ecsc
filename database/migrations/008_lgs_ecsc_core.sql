ALTER TABLE users
  ADD COLUMN can_direction BOOLEAN NOT NULL DEFAULT FALSE AFTER can_be_principal,
  ADD COLUMN can_school_life BOOLEAN NOT NULL DEFAULT FALSE AFTER can_direction,
  ADD COLUMN must_change_password BOOLEAN NOT NULL DEFAULT TRUE AFTER can_school_life;

UPDATE users SET can_direction=1 WHERE role='direction';
UPDATE users SET must_change_password=0;

ALTER TABLE classes ADD COLUMN sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100 AFTER level;

UPDATE classes SET name=level WHERE level IS NOT NULL AND level<>'';
