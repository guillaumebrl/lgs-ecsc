ALTER TABLE classes
  ADD COLUMN education_stage ENUM('preschool','primary','middle') NOT NULL DEFAULT 'primary' AFTER level;

UPDATE classes SET education_stage=CASE
  WHEN UPPER(level) IN ('PS','MS','GS') THEN 'preschool'
  WHEN UPPER(level) IN ('6E','5E','4E','3E') THEN 'middle'
  ELSE 'primary'
END;
