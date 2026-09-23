UPDATE classes c
JOIN (
  SELECT id, ROW_NUMBER() OVER (
    PARTITION BY school_year_id
    ORDER BY sort_order, id
  ) AS new_order
  FROM classes
) ranked ON ranked.id=c.id
SET c.sort_order=LEAST(ranked.new_order,12);

ALTER TABLE classes
  MODIFY sort_order TINYINT UNSIGNED NOT NULL DEFAULT 1;
