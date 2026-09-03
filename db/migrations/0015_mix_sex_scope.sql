-- Adds a 4th sex_scope: MIX, for a TLE teacher covering a hand-picked, mixed-sex subset of a
-- section (not "all", "male only", or "female only") — backed by an explicit per-student
-- roster in the new sst_student_claims junction table, not a category.
ALTER TABLE section_subject_teachers MODIFY COLUMN sex_scope ENUM('ALL','M','F','MIX') NOT NULL DEFAULT 'ALL';

-- uq_sst_scope (section_id, subject_id, school_year_id, term_scope, sex_scope) allowed only
-- ONE row per sex_scope value per slot — fine for ALL/M/F (the business rule already only
-- allows one of each), but breaks the actual point of MIX: 2-3 *different* teachers each
-- holding their own sex_scope='MIX' row for the same section/subject/term. Without this fix,
-- the second teacher's INSERT would collide on the unique key before sst_scope_conflict() ever
-- gets a chance to prove their picks don't overlap. This generated column differentiates MIX
-- rows by teacher while leaving every ALL/M/F row's dedup value the constant 0 — byte-for-byte
-- as discriminating as the old key for 100% of existing data, zero behavior change there. The
-- actual non-overlap of two teachers' MIX picks is sst_scope_conflict()'s job, not this key's.
ALTER TABLE section_subject_teachers
  ADD COLUMN scope_dedup_teacher_id INT
  GENERATED ALWAYS AS (CASE WHEN sex_scope = 'MIX' THEN teacher_id ELSE 0 END) STORED
  AFTER sex_scope;

-- New unique key BEFORE dropping the old one — MariaDB refuses to drop an index that's the
-- only one covering an FK column, same gotcha hit repeatedly this project.
CREATE UNIQUE INDEX uq_sst_scope_v2
  ON section_subject_teachers(section_id, subject_id, school_year_id, term_scope, sex_scope, scope_dedup_teacher_id);
ALTER TABLE section_subject_teachers DROP INDEX uq_sst_scope;

CREATE TABLE sst_student_claims (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_subject_teacher_id INT NOT NULL,
    student_id INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ssc_sst_student (section_subject_teacher_id, student_id),
    CONSTRAINT fk_ssc_sst FOREIGN KEY (section_subject_teacher_id) REFERENCES section_subject_teachers(id),
    CONSTRAINT fk_ssc_student FOREIGN KEY (student_id) REFERENCES students(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_ssc_student ON sst_student_claims(student_id);

-- effective_term_grades rebuilt again (previously in 0011, fixed for collation in 0014) with a
-- 4th UNION ALL branch for MIX, and a 3rd NOT EXISTS clause in the compound-completeness check,
-- same shape as the ALL/sex-specific branches already there. "sst.sex_scope = 'MIX'" is a single
-- literal comparison, not OR'd with a column comparison, so it is NOT the collation-bug shape
-- 0014 had to fix — safe as written.
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
