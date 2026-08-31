-- effective_term_grades (0011_scoped_teacher_assignments.sql) uses
-- "(x.sex_scope = 'ALL' OR x.sex_scope = st.sex)" twice — a literal-string comparison OR'd
-- with a column comparison in the same expression. On some MySQL versions (confirmed on a
-- live deployment, MySQL 8 with utf8mb4_uca1400_ai_ci) that shape alone can throw "Illegal
-- mix of collations" even when sex_scope and students.sex genuinely have the same stored
-- collation (see db/fix_sex_scope_collation.php) — the OR-with-a-literal is the trigger, not
-- the columns' own definitions. Rebuilt as UNION ALL / a split NOT EXISTS instead, matching
-- the same fix already applied to every PHP query with this shape. The two branches in each
-- case are mutually exclusive by definition (sex_scope can't be both 'ALL' and a specific sex
-- on the same row), so no behavior changes — this is a pure rewrite, not a logic change.
CREATE OR REPLACE VIEW effective_term_grades AS
SELECT tg.student_id, tg.term, tg.school_year_id, sst.section_id, tg.subject_id, tg.transmuted_grade
FROM term_grades tg
JOIN section_subject_teachers sst ON sst.subject_id = tg.subject_id AND sst.school_year_id = tg.school_year_id
JOIN submission_status ss ON ss.section_subject_teacher_id = sst.id AND ss.term = tg.term
JOIN students st ON st.id = tg.student_id AND st.section_id = sst.section_id
JOIN subjects sub ON sub.id = tg.subject_id
WHERE ss.status = 'published' AND sub.parent_subject_id IS NULL
  AND sst.is_active = 1
  AND (sst.term_scope = 0 OR sst.term_scope = tg.term)
  AND sst.sex_scope = 'ALL'

UNION ALL

SELECT tg.student_id, tg.term, tg.school_year_id, sst.section_id, tg.subject_id, tg.transmuted_grade
FROM term_grades tg
JOIN section_subject_teachers sst ON sst.subject_id = tg.subject_id AND sst.school_year_id = tg.school_year_id
JOIN submission_status ss ON ss.section_subject_teacher_id = sst.id AND ss.term = tg.term
JOIN students st ON st.id = tg.student_id AND st.section_id = sst.section_id
JOIN subjects sub ON sub.id = tg.subject_id
WHERE ss.status = 'published' AND sub.parent_subject_id IS NULL
  AND sst.is_active = 1
  AND (sst.term_scope = 0 OR sst.term_scope = tg.term)
  AND sst.sex_scope = st.sex

UNION ALL

SELECT tg.student_id, tg.term, tg.school_year_id, st.section_id, tg.subject_id, tg.transmuted_grade
FROM term_grades tg
JOIN students st ON st.id = tg.student_id AND st.school_year_id = tg.school_year_id
WHERE tg.transmuted_grade IS NOT NULL
  AND EXISTS (SELECT 1 FROM subjects c WHERE c.parent_subject_id = tg.subject_id)
  AND NOT EXISTS (
      SELECT 1 FROM subjects child
      JOIN section_subject_teachers csst ON csst.subject_id = child.id AND csst.section_id = st.section_id
          AND csst.school_year_id = tg.school_year_id AND csst.is_active = 1
          AND (csst.term_scope = 0 OR csst.term_scope = tg.term)
          AND csst.sex_scope = 'ALL'
      LEFT JOIN submission_status css ON css.section_subject_teacher_id = csst.id AND css.term = tg.term
      WHERE child.parent_subject_id = tg.subject_id
        AND (css.status IS NULL OR css.status <> 'published')
  )
  AND NOT EXISTS (
      SELECT 1 FROM subjects child
      JOIN section_subject_teachers csst ON csst.subject_id = child.id AND csst.section_id = st.section_id
          AND csst.school_year_id = tg.school_year_id AND csst.is_active = 1
          AND (csst.term_scope = 0 OR csst.term_scope = tg.term)
          AND csst.sex_scope = st.sex
      LEFT JOIN submission_status css ON css.section_subject_teacher_id = csst.id AND css.term = tg.term
      WHERE child.parent_subject_id = tg.subject_id
        AND (css.status IS NULL OR css.status <> 'published')
  );
