-- Special Program sections (e.g. Special Program for the Arts, Special Program for
-- Journalism) tag every student with an individual major, and a major-specific subject
-- should only be graded for students of that major — not the whole section. Majors are a
-- new, independent scoping dimension: unlike sex_scope='MIX' (a frozen, hand-picked
-- sst_student_claims snapshot), a student's major is a persistent, editable attribute, and
-- coverage is resolved LIVE off students.major_id everywhere, so changing a student's major
-- later automatically moves them to the right teacher's roster with no re-picking needed.

CREATE TABLE majors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    major_name VARCHAR(100) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_majors_name (major_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE sections ADD COLUMN is_special_program TINYINT(1) NOT NULL DEFAULT 0 AFTER adviser_id;

ALTER TABLE students ADD COLUMN major_id INT NULL AFTER section_id;
ALTER TABLE students ADD CONSTRAINT fk_students_major FOREIGN KEY (major_id) REFERENCES majors(id);

ALTER TABLE section_subject_teachers ADD COLUMN major_id INT NULL AFTER sex_scope;
ALTER TABLE section_subject_teachers ADD CONSTRAINT fk_sst_major FOREIGN KEY (major_id) REFERENCES majors(id);

-- v1: a major-scoped row is always sex_scope='ALL' — keeps major and MIX fully mutually
-- exclusive, so none of the existing MIX resolution code needs to consider major_id at all.
ALTER TABLE section_subject_teachers ADD CONSTRAINT chk_sst_major_sex_scope
  CHECK (major_id IS NULL OR sex_scope = 'ALL');

-- Same NULL-uniqueness trap 0011 hit for term_scope and 0015 solved for MIX via
-- scope_dedup_teacher_id: MySQL/MariaDB treat every NULL as distinct in a unique index, so
-- the raw nullable major_id can't go directly into the key (every ordinary row would have a
-- "distinct" NULL and stop colliding on genuine duplicates). This generated column collapses
-- NULL to a real, comparable 0 — byte-for-byte as discriminating as the old key for 100% of
-- existing data, zero behavior change there.
ALTER TABLE section_subject_teachers
  ADD COLUMN major_dedup_id INT GENERATED ALWAYS AS (COALESCE(major_id, 0)) STORED AFTER major_id;

-- New unique key BEFORE dropping the old one — MariaDB refuses to drop an index that's the
-- only one covering an FK column, same gotcha hit repeatedly this project.
CREATE UNIQUE INDEX uq_sst_scope_v4
  ON section_subject_teachers(section_id, subject_id, school_year_id, term_scope, sex_scope, scope_dedup_teacher_id, retired_dedup_id, major_dedup_id);
ALTER TABLE section_subject_teachers DROP INDEX uq_sst_scope_v3;

-- effective_term_grades rebuilt again with a major filter added to the ALL and sex-specific
-- branches (both already join students st, so this is a plain additive AND). The MIX branch
-- and the compound-parent branch are deliberately left untouched: major_id is mutually
-- exclusive with MIX by the CHECK constraint above, and compound (MAPEH-style) subjects are
-- blocked from ever being major-scoped at the admin-form layer (see admin/assignments.php) —
-- letting one major's unpublished status gate every other major's merged average would be a
-- real bug, not a hypothetical one, so it's prevented at data-entry time instead of adding
-- another layer of NOT EXISTS branching to this already-complex view.
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
  AND (sst.major_id IS NULL OR sst.major_id = st.major_id)

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

SELECT tg.student_id, tg.term, tg.school_year_id, sst.section_id, tg.subject_id, tg.transmuted_grade
FROM term_grades tg
JOIN section_subject_teachers sst ON sst.subject_id = tg.subject_id AND sst.school_year_id = tg.school_year_id
JOIN submission_status ss ON ss.section_subject_teacher_id = sst.id AND ss.term = tg.term
JOIN sst_student_claims ssc ON ssc.section_subject_teacher_id = sst.id AND ssc.student_id = tg.student_id
JOIN students st ON st.id = tg.student_id AND st.section_id = sst.section_id
JOIN subjects sub ON sub.id = tg.subject_id
WHERE ss.status = 'published' AND sub.parent_subject_id IS NULL
  AND sst.is_active = 1
  AND (sst.term_scope = 0 OR sst.term_scope = tg.term)
  AND sst.sex_scope = 'MIX'

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
  )
  AND NOT EXISTS (
      SELECT 1 FROM subjects child
      JOIN section_subject_teachers csst ON csst.subject_id = child.id AND csst.section_id = st.section_id
          AND csst.school_year_id = tg.school_year_id AND csst.is_active = 1
          AND (csst.term_scope = 0 OR csst.term_scope = tg.term)
          AND csst.sex_scope = 'MIX'
      JOIN sst_student_claims cssc ON cssc.section_subject_teacher_id = csst.id AND cssc.student_id = st.id
      LEFT JOIN submission_status css ON css.section_subject_teacher_id = csst.id AND css.term = tg.term
      WHERE child.parent_subject_id = tg.subject_id
        AND (css.status IS NULL OR css.status <> 'published')
  );
