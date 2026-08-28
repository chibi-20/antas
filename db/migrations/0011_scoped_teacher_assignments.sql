-- Per-term / sex-split teacher assignments: some subjects (TLE at this school) don't follow
-- the "one teacher owns this subject in this section all year" pattern every other subject
-- uses — a different teacher can cover the same section's TLE each term, and a teacher can
-- be scoped to only the boys or only the girls of a section (a different teacher covers the
-- other half). Generic, not TLE-specific by name — any subject can use this.
--
-- term_scope = 0 means "covers the whole year" (today's behavior; every existing row gets
-- this by default, zero migration needed for any subject that doesn't use this feature).
-- term_scope = 1/2/3 means "covers only that term." sex_scope = 'ALL' (default) means
-- today's behavior; 'M'/'F' means "covers only that sex."
--
-- Deliberately NOT NULL DEFAULT 0, not nullable: a nullable term would make MySQL/MariaDB
-- treat every NULL as distinct in the unique index below, silently destroying the
-- duplicate-prevention admin/bulk_assign.php and admin/assignments.php already rely on for
-- EVERY subject (re-running Bulk Assign for an already-assigned subject would otherwise
-- silently create a duplicate whole-year row, double-counting that subject in every
-- affected student's average). Named term_scope, not term, to avoid confusion with the
-- $term request variable already in scope everywhere $assignment is used.
ALTER TABLE section_subject_teachers ADD COLUMN term_scope TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER school_year_id;
ALTER TABLE section_subject_teachers ADD CONSTRAINT chk_sst_term_scope CHECK (term_scope BETWEEN 0 AND 3);
ALTER TABLE section_subject_teachers ADD COLUMN sex_scope ENUM('ALL','M','F') NOT NULL DEFAULT 'ALL' AFTER term_scope;

-- New unique key BEFORE dropping the old one — MariaDB refuses to drop an index that's the
-- only one covering an FK column (fk_sst_section), same gotcha already hit twice this project
-- (see db/migrations/0005_terms_not_quarters.sql and 0007_grade_levels_and_capability_roles.sql).
CREATE UNIQUE INDEX uq_sst_scope ON section_subject_teachers(section_id, subject_id, school_year_id, term_scope, sex_scope);
ALTER TABLE section_subject_teachers DROP INDEX uq_section_subject_year;

-- effective_term_grades (db/migrations/0008_compound_subjects.sql) previously joined sst with
-- NO term, sex, or is_active predicate at all — harmless when exactly one sst row existed per
-- (section, subject, year), but the moment a section has more than one sst row for a subject
-- (this migration's whole point), it would either double-count a grade (a published M row AND
-- a published F row both match the same term_grades row, emitting it twice, skewing AVG() in
-- general_average_view) or leak an unpublished grade into a student's average (a boy's grade
-- appears the moment the boys' teacher publishes, even though an unrelated girls' sst row also
-- matches the join). Rebuilt with the term/sex/active predicates on both UNION branches — the
-- compound-parent completeness check (branch 2) had the identical gap and failed CLOSED (a
-- per-term child would never count for any term without this fix).
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
  AND (sst.sex_scope = 'ALL' OR sst.sex_scope = st.sex)

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
          AND (csst.sex_scope = 'ALL' OR csst.sex_scope = st.sex)
      LEFT JOIN submission_status css ON css.section_subject_teacher_id = csst.id AND css.term = tg.term
      WHERE child.parent_subject_id = tg.subject_id
        AND (css.status IS NULL OR css.status <> 'published')
  );
