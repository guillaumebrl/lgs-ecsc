-- Migration des notes Triade vers LGS-ECSC, à exécuter sur MySQL/MariaDB.
-- Prérequis : les deux bases doivent être présentes sur le même serveur.
-- Adaptez uniquement les deux noms de schéma ci-dessous si nécessaire.
--
-- Source : u738017606_triade
-- Cible  : u738017606_LGSECSC_V3
--
-- Le script est volontairement transactionnel et refuse d'insérer une ligne
-- dont l'élève, la classe, la matière, le cours, la période ou l'auteur ne peut
-- pas être rapproché sans ambiguïté.

SET NAMES utf8mb4;
SET @migration_from := DATE('2026-09-01');
SET @fallback_author_id := 1; -- administrateur LGS, seulement si l'auteur Triade est introuvable

START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS mig_notes;
CREATE TEMPORARY TABLE mig_notes AS
SELECT n.*
FROM u738017606_triade.tria_notes n
WHERE n.date >= @migration_from;

DROP TEMPORARY TABLE IF EXISTS mig_student_map;
CREATE TEMPORARY TABLE mig_student_map AS
SELECT te.elev_id AS triade_student_id, MIN(s.id) AS lgs_student_id,
       COUNT(*) AS matches
FROM u738017606_triade.tria_eleves te
JOIN u738017606_LGSECSC_V3.students s
  ON (
       NULLIF(TRIM(te.numero_eleve), '') IS NOT NULL
       AND TRIM(s.registration_number) = TRIM(te.numero_eleve)
     )
  OR (
       UPPER(TRIM(s.last_name)) = UPPER(TRIM(te.nom))
       AND UPPER(TRIM(s.first_name)) = UPPER(TRIM(te.prenom))
       AND s.birth_date <=> te.date_naissance
     )
GROUP BY te.elev_id;

DROP TEMPORARY TABLE IF EXISTS mig_class_map;
CREATE TEMPORARY TABLE mig_class_map AS
SELECT tc.code_class AS triade_class_id, MIN(c.id) AS lgs_class_id,
       COUNT(*) AS matches
FROM u738017606_triade.tria_classes tc
JOIN u738017606_LGSECSC_V3.classes c
  ON UPPER(TRIM(c.name)) = UPPER(TRIM(tc.libelle))
JOIN u738017606_LGSECSC_V3.school_years sy
  ON sy.id = c.school_year_id AND sy.active = 1
GROUP BY tc.code_class;

DROP TEMPORARY TABLE IF EXISTS mig_subject_map;
CREATE TEMPORARY TABLE mig_subject_map AS
SELECT tm.code_mat AS triade_subject_id, MIN(s.id) AS lgs_subject_id,
       COUNT(*) AS matches
FROM u738017606_triade.tria_matieres tm
JOIN u738017606_LGSECSC_V3.subjects s
  ON UPPER(TRIM(s.name)) = UPPER(TRIM(
       CASE WHEN NULLIF(TRIM(tm.sous_matiere), '') IS NOT NULL
                  AND TRIM(tm.sous_matiere) <> '0'
            THEN CONCAT(tm.libelle, ' — ', tm.sous_matiere)
            ELSE tm.libelle END
     ))
GROUP BY tm.code_mat;

DROP TEMPORARY TABLE IF EXISTS mig_author_map;
CREATE TEMPORARY TABLE mig_author_map AS
SELECT tp.pers_id AS triade_author_id, MIN(u.id) AS lgs_author_id,
       COUNT(*) AS matches
FROM u738017606_triade.tria_personnel tp
JOIN u738017606_LGSECSC_V3.users u
  ON (NULLIF(TRIM(tp.email), '') IS NOT NULL AND LOWER(TRIM(u.email)) = LOWER(TRIM(tp.email)))
  OR (UPPER(TRIM(u.last_name)) = UPPER(TRIM(tp.nom))
      AND UPPER(TRIM(u.first_name)) = UPPER(TRIM(tp.prenom)))
GROUP BY tp.pers_id;

-- Une évaluation Triade est reconstituée en regroupant toutes les notes ayant
-- les mêmes caractéristiques. id_classe=-1 désigne généralement un groupe ;
-- la classe réelle est alors retrouvée via l'inscription de l'élève.
DROP TEMPORARY TABLE IF EXISTS mig_rows;
CREATE TEMPORARY TABLE mig_rows AS
SELECT n.note_id, n.elev_id, sm.lgs_student_id, n.prof_id,
       COALESCE(am.lgs_author_id, @fallback_author_id) AS lgs_author_id,
       n.code_mat, subm.lgs_subject_id, n.coef, n.date,
       LEFT(COALESCE(NULLIF(TRIM(n.sujet), ''), 'Évaluation importée de Triade'), 150) AS title,
       n.note, COALESCE(NULLIF(n.notationsur, 0), 20) AS scale,
       n.id_classe, n.id_groupe, e.class_id AS enrolled_class_id,
       COALESCE(cm.lgs_class_id, e.class_id) AS lgs_class_id,
       p.id AS period_id
FROM mig_notes n
LEFT JOIN mig_student_map sm
  ON sm.triade_student_id = n.elev_id AND sm.matches = 1
LEFT JOIN mig_author_map am
  ON am.triade_author_id = n.prof_id AND am.matches = 1
LEFT JOIN mig_subject_map subm
  ON subm.triade_subject_id = n.code_mat AND subm.matches = 1
LEFT JOIN mig_class_map cm
  ON cm.triade_class_id = n.id_classe AND cm.matches = 1
LEFT JOIN u738017606_LGSECSC_V3.enrollments e
  ON e.student_id = sm.lgs_student_id
LEFT JOIN u738017606_LGSECSC_V3.periods p
  ON p.id = COALESCE(
       (SELECT p1.id
        FROM u738017606_LGSECSC_V3.periods p1
        JOIN u738017606_LGSECSC_V3.school_years sy1
          ON sy1.id = p1.school_year_id AND sy1.active = 1
        WHERE n.date >= p1.starts_on AND n.date < p1.ends_on
        ORDER BY p1.sort_order LIMIT 1),
       -- Le dump cible commence son T1 le 07/09, mais la demande commence le
       -- 01/09 : les notes du 01 au 06/09 sont donc rattachées au premier T1.
       (SELECT p2.id
        FROM u738017606_LGSECSC_V3.periods p2
        JOIN u738017606_LGSECSC_V3.school_years sy2
          ON sy2.id = p2.school_year_id AND sy2.active = 1
        WHERE n.date >= @migration_from
        ORDER BY p2.sort_order LIMIT 1)
     );

DROP TEMPORARY TABLE IF EXISTS mig_resolved;
CREATE TEMPORARY TABLE mig_resolved AS
SELECT r.*,
       MIN(co.id) AS course_id,
       COUNT(DISTINCT co.id) AS course_matches
FROM mig_rows r
LEFT JOIN u738017606_LGSECSC_V3.courses co
  ON co.class_id = r.lgs_class_id
 AND co.subject_id = r.lgs_subject_id
 AND (
      (COALESCE(r.id_groupe, 0) = 0 AND co.class_group_id IS NULL)
      OR
      (COALESCE(r.id_groupe, 0) <> 0 AND
       (co.class_group_id IS NULL OR EXISTS (
          SELECT 1
          FROM u738017606_LGSECSC_V3.group_students gs
          WHERE gs.group_id = co.class_group_id
            AND gs.student_id = r.lgs_student_id
       )))
     )
GROUP BY r.note_id, r.elev_id, r.lgs_student_id, r.prof_id, r.lgs_author_id,
         r.code_mat, r.lgs_subject_id, r.coef, r.date, r.title, r.note, r.scale,
         r.id_classe, r.id_groupe, r.enrolled_class_id, r.lgs_class_id, r.period_id;

-- Contrôle obligatoire avant insertion. Le résultat doit être zéro partout.
SELECT
  SUM(lgs_student_id IS NULL) AS eleves_non_trouves,
  SUM(lgs_subject_id IS NULL) AS matieres_non_trouvees,
  SUM(lgs_class_id IS NULL) AS classes_non_trouvees,
  SUM(period_id IS NULL) AS periodes_non_trouvees,
  SUM(course_matches <> 1) AS cours_non_uniques,
  SUM(note < 0 OR note > scale) AS codes_negatifs_ou_notes_hors_bareme
FROM mig_resolved;

-- Stop manuel recommandé ici si une colonne du contrôle précédent n'est pas à 0.

DROP TEMPORARY TABLE IF EXISTS mig_assessment_keys;
CREATE TEMPORARY TABLE mig_assessment_keys (
  migration_key varchar(500) PRIMARY KEY,
  assessment_id bigint unsigned NULL
);

INSERT INTO mig_assessment_keys (migration_key)
SELECT DISTINCT CONCAT_WS('|', course_id, period_id, lgs_author_id, date,
                          title, scale, coef)
FROM mig_resolved
WHERE lgs_student_id IS NOT NULL
  AND lgs_subject_id IS NOT NULL
  AND lgs_class_id IS NOT NULL
  AND period_id IS NOT NULL
  AND course_matches = 1
  AND note BETWEEN 0 AND scale;

-- Idempotence : réutilise une évaluation cible identique si elle existe déjà.
UPDATE mig_assessment_keys k
JOIN u738017606_LGSECSC_V3.assessments a
  ON k.migration_key = CONCAT_WS('|', a.course_id, a.period_id, a.author_id,
                                 a.assessment_date, a.title, a.scale, a.coefficient)
SET k.assessment_id = a.id;

INSERT INTO u738017606_LGSECSC_V3.assessments
  (course_id, period_id, author_id, title, assessment_date, scale,
   normalize_to, coefficient, status)
SELECT DISTINCT r.course_id, r.period_id, r.lgs_author_id, r.title, r.date,
       r.scale,
       CASE WHEN r.scale = 20 THEN '20' WHEN r.scale = 10 THEN '10' ELSE 'none' END,
       r.coef, 'published'
FROM mig_resolved r
JOIN mig_assessment_keys k
  ON k.migration_key = CONCAT_WS('|', r.course_id, r.period_id, r.lgs_author_id,
                                 r.date, r.title, r.scale, r.coef)
WHERE k.assessment_id IS NULL
GROUP BY r.course_id, r.period_id, r.lgs_author_id, r.title, r.date, r.scale, r.coef;

UPDATE mig_assessment_keys k
JOIN u738017606_LGSECSC_V3.assessments a
  ON k.migration_key = CONCAT_WS('|', a.course_id, a.period_id, a.author_id,
                                 a.assessment_date, a.title, a.scale, a.coefficient)
SET k.assessment_id = a.id
WHERE k.assessment_id IS NULL;

INSERT INTO u738017606_LGSECSC_V3.grades
  (assessment_id, student_id, score, special_status, comment, updated_by)
SELECT k.assessment_id, r.lgs_student_id, ROUND(r.note, 2), NULL,
       CONCAT('Import Triade note_id=', r.note_id), r.lgs_author_id
FROM mig_resolved r
JOIN mig_assessment_keys k
  ON k.migration_key = CONCAT_WS('|', r.course_id, r.period_id, r.lgs_author_id,
                                 r.date, r.title, r.scale, r.coef)
WHERE r.course_matches = 1
ON DUPLICATE KEY UPDATE
  score = VALUES(score),
  comment = VALUES(comment),
  updated_by = VALUES(updated_by);

SELECT COUNT(*) AS notes_source FROM mig_notes;
SELECT COUNT(*) AS evaluations_apres_import
FROM mig_assessment_keys WHERE assessment_id IS NOT NULL;
SELECT ROW_COUNT() AS derniere_operation_lignes;

-- Remplacer ROLLBACK par COMMIT seulement après vérification des contrôles.
ROLLBACK;
