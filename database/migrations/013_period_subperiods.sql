-- Simplifie l'état des trimestres et ajoute leurs sous-périodes.
UPDATE periods SET status = 'closed' WHERE status IN ('draft', 'validated');

ALTER TABLE periods
  MODIFY status ENUM('open','closed') NOT NULL DEFAULT 'closed';

CREATE TABLE IF NOT EXISTS period_subperiods (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  period_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  starts_on DATE NOT NULL,
  ends_on DATE NOT NULL,
  sort_order TINYINT UNSIGNED NOT NULL DEFAULT 1,
  CONSTRAINT fk_subperiod_period FOREIGN KEY(period_id) REFERENCES periods(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
