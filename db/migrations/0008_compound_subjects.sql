-- Compound subjects: some subjects (e.g. MAPEH) are graded as separate components (e.g.
-- Music-Arts, PE-Health — each with their own teacher, WW/PT/EX encoding, and Head Teacher
-- review/publish cycle) that get merged into ONE report-card grade. A child subject's
-- parent_subject_id points at the subject representing that merged grade. Nothing marks a
-- subject as "compound" explicitly — a subject is a compound parent purely by having other
-- subjects reference it here (no nesting: a child is never itself expected to have children,
-- enforced in the admin UI, not the schema).
ALTER TABLE subjects ADD COLUMN parent_subject_id INT NULL AFTER weight_profile_id;
ALTER TABLE subjects ADD CONSTRAINT fk_subjects_parent FOREIGN KEY (parent_subject_id) REFERENCES subjects(id);
CREATE INDEX idx_subjects_parent ON subjects(parent_subject_id);

-- Effective per-student/term grade to count toward General Average: a normal (non-child)
-- subject counts once its own submission is published; a compound parent counts using its
-- own term_grades row (populated by recompute_compound_term_grade — an average of its
-- children's transmuted grades) only once ALL of its children are published for that
-- section/term. Child subjects never count individually, only via their merged parent.
CREATE OR REPLACE VIEW effective_term_grades AS
SELECT tg.student_id, tg.term, tg.school_year_id, sst.section_id, tg.subject_id, tg.transmuted_grade
FROM term_grades tg
JOIN section_subject_teachers sst ON sst.subject_id = tg.subject_id AND sst.school_year_id = tg.school_year_id
JOIN submission_status ss ON ss.section_subject_teacher_id = sst.id AND ss.term = tg.term
JOIN students st ON st.id = tg.student_id AND st.section_id = sst.section_id
JOIN subjects sub ON sub.id = tg.subject_id
WHERE ss.status = 'published' AND sub.parent_subject_id IS NULL

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
      LEFT JOIN submission_status css ON css.section_subject_teacher_id = csst.id AND css.term = tg.term
      WHERE child.parent_subject_id = tg.subject_id
        AND (css.status IS NULL OR css.status <> 'published')
  );

-- Rebuild the ranking view on top of effective_term_grades instead of joining
-- term_grades/section_subject_teachers/submission_status directly (see db/migrations/0005
-- and 0002 for the prior definitions this replaces).
CREATE OR REPLACE VIEW general_average_view AS
SELECT
    averages.student_id,
    averages.section_id,
    averages.term,
    averages.school_year_id,
    averages.average,
    RANK() OVER (PARTITION BY averages.section_id, averages.term ORDER BY averages.average DESC) AS rank_in_section
FROM (
    SELECT
        s.id AS student_id,
        s.section_id AS section_id,
        eg.term AS term,
        s.school_year_id AS school_year_id,
        ROUND(AVG(eg.transmuted_grade), 2) AS average
    FROM students s
    JOIN effective_term_grades eg ON eg.student_id = s.id AND eg.school_year_id = s.school_year_id AND eg.section_id = s.section_id
    WHERE s.is_active = 1
    GROUP BY s.id, s.section_id, eg.term, s.school_year_id
) AS averages;
