CREATE TABLE IF NOT EXISTS families (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  family_label VARCHAR(150) NOT NULL,
  family_situation ENUM('married','civil_union','cohabiting','separated','divorced','single_parent','widowed','other') NOT NULL DEFAULT 'married',
  addressee_mode ENUM('shared_couple','individual_names','custom') NOT NULL DEFAULT 'individual_names',
  custom_addressee VARCHAR(255) NULL,
  address_line1 VARCHAR(255) NULL,
  address_line2 VARCHAR(255) NULL,
  postal_code VARCHAR(20) NULL,
  city VARCHAR(120) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guardians (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  family_id BIGINT UNSIGNED NOT NULL,
  title ENUM('M.','Mme','Mx','Autre') NOT NULL DEFAULT 'M.',
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  relationship VARCHAR(80) NOT NULL DEFAULT 'Parent',
  email VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  legal_guardian BOOLEAN NOT NULL DEFAULT TRUE,
  receives_bulletin BOOLEAN NOT NULL DEFAULT TRUE,
  display_order TINYINT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY(family_id) REFERENCES families(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE students ADD COLUMN family_id BIGINT UNSIGNED NULL AFTER birth_date;
ALTER TABLE students ADD CONSTRAINT fk_student_family FOREIGN KEY(family_id) REFERENCES families(id) ON DELETE SET NULL;

