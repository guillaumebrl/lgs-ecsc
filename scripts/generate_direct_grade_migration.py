#!/usr/bin/env python3
"""Generate a target-only SQL migration from Triade and LGS SQL dumps."""

import csv
import sys
import unicodedata
from collections import defaultdict
from pathlib import Path


def norm(value):
    value = "" if value is None else str(value).strip()
    return "".join(
        c for c in unicodedata.normalize("NFKD", value).casefold()
        if not unicodedata.combining(c)
    )


def sql_value(value):
    if value is None:
        return "NULL"
    if isinstance(value, (int, float)):
        return str(value)
    return "'" + str(value).replace("\\", "\\\\").replace("'", "''") + "'"


def read_inserts(path, wanted):
    result = {name: [] for name in wanted}
    current = None
    columns = None
    with Path(path).open(encoding="utf-8") as handle:
        for raw in handle:
            if raw.startswith("INSERT INTO `"):
                table = raw.split("`", 2)[1]
                current = table if table in wanted else None
                columns = None
                if current:
                    left = raw.find("(")
                    right = raw.rfind(") VALUES")
                    columns = [part.strip(" `") for part in raw[left + 1:right].split(",")]
                continue
            if current and raw.startswith("("):
                body = raw.strip().rstrip(",;")[1:-1]
                values = next(csv.reader(
                    [body], delimiter=",", quotechar="'", escapechar="\\",
                    skipinitialspace=True
                ))
                converted = [None if value == "NULL" else value for value in values]
                result[current].append(dict(zip(columns, converted)))
    return result


def main():
    if len(sys.argv) != 4:
        raise SystemExit("usage: generate_direct_grade_migration.py TRIade.sql LGS.sql OUTPUT.sql")

    triade_path, lgs_path, output_path = sys.argv[1:]
    triade = read_inserts(triade_path, {
        "tria_notes", "tria_eleves", "tria_matieres", "tria_classes",
        "tria_personnel", "tria_groupes",
    })
    lgs = read_inserts(lgs_path, {
        "students", "classes", "subjects", "users", "courses", "enrollments",
        "class_groups", "group_students", "periods", "school_years",
    })

    students_by_registration = defaultdict(list)
    students_by_identity = defaultdict(list)
    students_by_name = defaultdict(list)
    for row in lgs["students"]:
        if norm(row["registration_number"]):
            students_by_registration[norm(row["registration_number"])].append(row)
        students_by_identity[(norm(row["last_name"]), norm(row["first_name"]), row["birth_date"])].append(row)
        students_by_name[(norm(row["last_name"]), norm(row["first_name"]))].append(row)

    student_map = {}
    for row in triade["tria_eleves"]:
        matches = students_by_registration.get(norm(row["numero_eleve"]), [])
        if not matches:
            matches = students_by_identity.get((norm(row["nom"]), norm(row["prenom"]), row["date_naissance"]), [])
        if not matches:
            matches = students_by_name.get((norm(row["nom"]), norm(row["prenom"])), [])
        if len(matches) == 1:
            student_map[int(row["elev_id"])] = int(matches[0]["id"])

    active_years = {int(row["id"]) for row in lgs["school_years"] if row["active"] == "1"}
    class_map = {}
    classes_by_name = defaultdict(list)
    for row in lgs["classes"]:
        if int(row["school_year_id"]) in active_years:
            classes_by_name[norm(row["name"])].append(row)
    for row in triade["tria_classes"]:
        matches = classes_by_name[norm(row["libelle"])]
        if len(matches) == 1:
            class_map[int(row["code_class"])] = int(matches[0]["id"])

    subjects_by_name = defaultdict(list)
    for row in lgs["subjects"]:
        subjects_by_name[norm(row["name"])].append(row)
    subject_map = {}
    for row in triade["tria_matieres"]:
        sub = (row["sous_matiere"] or "").strip()
        name = row["libelle"] if not sub or sub == "0" else f'{row["libelle"]} — {sub}'
        matches = subjects_by_name[norm(name)]
        if len(matches) == 1:
            subject_map[int(row["code_mat"])] = int(matches[0]["id"])

    users_by_email = defaultdict(list)
    users_by_identity = defaultdict(list)
    for row in lgs["users"]:
        if norm(row["email"]):
            users_by_email[norm(row["email"])].append(row)
        users_by_identity[(norm(row["last_name"]), norm(row["first_name"]))].append(row)
    author_map = {}
    for row in triade["tria_personnel"]:
        matches = users_by_email.get(norm(row["email"]), [])
        if len(matches) != 1:
            matches = users_by_identity.get((norm(row["nom"]), norm(row["prenom"])), [])
        if len(matches) == 1:
            author_map[int(row["pers_id"])] = int(matches[0]["id"])

    enrollment = {int(row["student_id"]): int(row["class_id"]) for row in lgs["enrollments"]}
    target_groups = {int(row["id"]): row for row in lgs["class_groups"]}
    target_group_students = defaultdict(set)
    for row in lgs["group_students"]:
        target_group_students[int(row["student_id"])].add(int(row["group_id"]))
    triade_groups = {int(row["group_id"]): row for row in triade["tria_groupes"]}

    courses_by_class_subject = defaultdict(list)
    for row in lgs["courses"]:
        courses_by_class_subject[(int(row["class_id"]), int(row["subject_id"]))].append(row)

    periods = sorted(
        (row for row in lgs["periods"] if int(row["school_year_id"]) in active_years),
        key=lambda row: int(row["sort_order"]),
    )

    def period_for(date):
        for row in periods:
            if row["starts_on"] <= date < row["ends_on"]:
                return int(row["id"])
        return int(periods[0]["id"]) if periods and date >= "2026-09-01" else None

    def course_for(class_id, subject_id, group_id, student_id):
        candidates = courses_by_class_subject[(class_id, subject_id)]
        if group_id == 0:
            target_group_id = None
            matches = [row for row in candidates if row["class_group_id"] is None]
        else:
            source_label = norm((triade_groups.get(group_id) or {}).get("libelle"))
            possible_groups = [gid for gid, group in target_groups.items()
                               if int(group["class_id"]) == class_id
                               and norm(group["name"]) in source_label
                               and gid in target_group_students[student_id]]
            target_group_id = possible_groups[0] if len(possible_groups) == 1 else None
            matches = [row for row in candidates
                       if (int(row["class_group_id"]) if row["class_group_id"] else None) == target_group_id]
        return (int(matches[0]["id"]) if len(matches) == 1 else None, target_group_id)

    resolved = []
    rejected = []
    for note in triade["tria_notes"]:
        if note["date"] < "2026-09-01":
            continue
        source_id = int(note["note_id"])
        score = float(note["note"])
        special_status = {
            -1.0: "absent",
            -2.0: "excused",
            -4.0: "not_graded",
            -5.0: "missing",
        }.get(score)
        scale = int(note["notationsur"] or 20) or 20
        student_id = student_map.get(int(note["elev_id"]))
        subject_id = subject_map.get(int(note["code_mat"]))
        class_id = class_map.get(int(note["id_classe"])) or enrollment.get(student_id)
        group_id = int(note["id_groupe"] or 0)
        period_id = period_for(note["date"])
        author_id = author_map.get(int(note["prof_id"]), 1)
        course_id, target_group_id = course_for(class_id, subject_id, group_id, student_id) if class_id and subject_id and student_id else (None, None)
        reason = None
        if score == -3:
            reason = "case de note vide"
        elif score < 0 and special_status is None:
            reason = "code négatif non pris en charge"
        elif score > scale:
            reason = "note hors barème"
        elif not student_id:
            reason = "élève introuvable"
        elif not subject_id:
            reason = "matière introuvable"
        elif not class_id:
            reason = "classe introuvable"
        elif not period_id:
            reason = "période introuvable"
        if reason:
            rejected.append((source_id, reason))
            continue
        title = (note["sujet"] or "").strip() or "Évaluation importée de Triade"
        resolved.append({
            "source_id": source_id, "student_id": student_id, "course_id": course_id,
            "class_id": class_id, "subject_id": subject_id, "class_group_id": target_group_id,
            "period_id": period_id, "author_id": author_id, "title": title[:150],
            "date": note["date"], "scale": scale, "coefficient": note["coef"],
            "score": None if special_status else round(score, 2),
            "special_status": special_status,
        })

    headers = [
        "-- Migration autonome Triade -> LGS-ECSC (base cible uniquement)",
        "-- Générée depuis les deux dumps fournis.",
        f"-- Notes prêtes à importer : {len(resolved)}",
        f"-- Lignes écartées : {len(rejected)}",
        "-- Le ROLLBACK final rend le premier lancement non destructif.",
        "SET NAMES utf8mb4;",
        "START TRANSACTION;",
        "DROP TEMPORARY TABLE IF EXISTS tmp_triade_grades;",
        "CREATE TEMPORARY TABLE tmp_triade_grades (",
        "  source_note_id INT PRIMARY KEY, student_id BIGINT UNSIGNED NOT NULL,",
        "  course_id BIGINT UNSIGNED NULL, class_id BIGINT UNSIGNED NOT NULL,",
        "  subject_id BIGINT UNSIGNED NOT NULL, class_group_id BIGINT UNSIGNED NULL,",
        "  period_id BIGINT UNSIGNED NOT NULL,",
        "  author_id BIGINT UNSIGNED NOT NULL, title VARCHAR(150) NOT NULL,",
        "  assessment_date DATE NOT NULL, scale DECIMAL(7,2) NOT NULL,",
        "  coefficient DECIMAL(6,2) NOT NULL, score DECIMAL(7,2) NULL,",
        "  special_status ENUM('absent','excused','missing','not_graded') NULL",
        ");",
        "INSERT INTO tmp_triade_grades VALUES",
    ]
    values = []
    for row in resolved:
        values.append("(" + ",".join(sql_value(row[key]) for key in (
            "source_id", "student_id", "course_id", "class_id", "subject_id",
            "class_group_id", "period_id", "author_id",
            "title", "date", "scale", "coefficient", "score", "special_status",
        )) + ")")

    footer = [
        ";",
        "-- Crée uniquement les cours nécessaires qui n'existent pas encore.",
        "INSERT INTO courses (class_id,subject_id,group_name,class_group_id,assignment_coefficient,display_order)",
        "SELECT DISTINCT t.class_id,t.subject_id,NULL,t.class_group_id,1.00,100",
        "FROM tmp_triade_grades t",
        "WHERE t.course_id IS NULL AND NOT EXISTS (SELECT 1 FROM courses co",
        "  WHERE co.class_id=t.class_id AND co.subject_id=t.subject_id",
        "  AND co.class_group_id <=> t.class_group_id);",
        "UPDATE tmp_triade_grades t JOIN courses co ON co.class_id=t.class_id",
        " AND co.subject_id=t.subject_id AND co.class_group_id <=> t.class_group_id",
        "SET t.course_id=co.id WHERE t.course_id IS NULL;",
        "SELECT COUNT(*) AS cours_non_resolus FROM tmp_triade_grades WHERE course_id IS NULL;",
        "INSERT INTO assessments (course_id,period_id,author_id,title,assessment_date,scale,normalize_to,coefficient,status)",
        "SELECT DISTINCT t.course_id,t.period_id,t.author_id,t.title,t.assessment_date,t.scale,",
        "  CASE WHEN t.scale=20 THEN '20' WHEN t.scale=10 THEN '10' ELSE 'none' END,",
        "  t.coefficient,'published'",
        "FROM tmp_triade_grades t",
        "WHERE NOT EXISTS (SELECT 1 FROM assessments a WHERE a.course_id=t.course_id",
        "  AND a.period_id=t.period_id AND a.author_id=t.author_id AND a.title=t.title",
        "  AND a.assessment_date=t.assessment_date AND a.scale=t.scale",
        "  AND a.coefficient=t.coefficient);",
        "INSERT INTO grades (assessment_id,student_id,score,special_status,comment,updated_by)",
        "SELECT a.id,t.student_id,t.score,t.special_status,CONCAT('Import Triade note_id=',t.source_note_id),t.author_id",
        "FROM tmp_triade_grades t JOIN assessments a ON a.course_id=t.course_id",
        " AND a.period_id=t.period_id AND a.author_id=t.author_id AND a.title=t.title",
        " AND a.assessment_date=t.assessment_date AND a.scale=t.scale",
        " AND a.coefficient=t.coefficient",
        "ON DUPLICATE KEY UPDATE score=VALUES(score),special_status=VALUES(special_status),",
        " comment=VALUES(comment),updated_by=VALUES(updated_by);",
        "SELECT COUNT(*) AS notes_preparees FROM tmp_triade_grades;",
        "-- Remplacez ROLLBACK par COMMIT après validation du premier essai.",
        "ROLLBACK;",
        "",
        "-- Lignes non injectées :",
    ]
    footer.extend(f"-- Triade note_id={source_id}: {reason}" for source_id, reason in rejected)
    Path(output_path).write_text("\n".join(headers) + "\n" + ",\n".join(values) + "\n" + "\n".join(footer), encoding="utf-8")
    print(f"generated={len(resolved)} rejected={len(rejected)} output={output_path}")


if __name__ == "__main__":
    main()
