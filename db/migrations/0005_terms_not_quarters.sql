-- Switches from 4 quarters to 3 terms. Renames the "quarter" concept to "term"
-- throughout (columns, table, view, constraints, indexes) and narrows the valid
-- range from 1-4 to 1-3. There's no data for a 4th period yet, so the one empty
-- tracking row for it is simply dropped.
--
-- Index note: composite indexes like (section_subject_teacher_id, quarter) can be the
-- ONLY index covering an FK column (e.g. fk_ai_sst on section_subject_teacher_id), so
-- InnoDB refuses to drop them without a replacement already in place — the new index is
-- created BEFORE the old one is dropped throughout this file for that reason.

DELETE FROM submission_status WHERE quarter = 4;

-- assessment_items
ALTER TABLE assessment_items DROP CONSTRAINT chk_quarter_range;
CREATE INDEX idx_assessment_items_sst_term ON assessment_items(section_subject_teacher_id, quarter);
DROP INDEX idx_assessment_items_sst_quarter ON assessment_items;
ALTER TABLE assessment_items CHANGE COLUMN quarter term TINYINT UNSIGNED NOT NULL;
ALTER TABLE assessment_items ADD CONSTRAINT chk_term_range CHECK (term BETWEEN 1 AND 3);

-- quarterly_grades -> term_grades
ALTER TABLE quarterly_grades DROP CONSTRAINT chk_qg_quarter_range;
CREATE INDEX idx_term_grades_lookup ON quarterly_grades(subject_id, quarter, school_year_id);
DROP INDEX idx_quarterly_grades_lookup ON quarterly_grades;
ALTER TABLE quarterly_grades CHANGE COLUMN quarter term TINYINT UNSIGNED NOT NULL;
ALTER TABLE quarterly_grades RENAME TO term_grades;
ALTER TABLE term_grades ADD CONSTRAINT chk_tg_term_range CHECK (term BETWEEN 1 AND 3);

-- submission_status
ALTER TABLE submission_status DROP CONSTRAINT chk_ss_quarter_range;
ALTER TABLE submission_status CHANGE COLUMN quarter term TINYINT UNSIGNED NOT NULL;
ALTER TABLE submission_status ADD CONSTRAINT chk_ss_term_range CHECK (term BETWEEN 1 AND 3);

-- report_cards (nullable = annual, no range constraint previously, keeping it that way)
ALTER TABLE report_cards CHANGE COLUMN quarter term TINYINT UNSIGNED NULL;

-- Rebuild the ranking view against term_grades/term (see db/migrations/0002_views.sql for
-- the original quarter-based definition this replaces).
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
        tg.term AS term,
        s.school_year_id AS school_year_id,
        ROUND(AVG(tg.transmuted_grade), 2) AS average
    FROM students s
    JOIN term_grades tg
        ON tg.student_id = s.id AND tg.school_year_id = s.school_year_id
    JOIN section_subject_teachers sst
        ON sst.section_id = s.section_id
       AND sst.subject_id = tg.subject_id
       AND sst.school_year_id = s.school_year_id
    JOIN submission_status ss
        ON ss.section_subject_teacher_id = sst.id AND ss.term = tg.term
    WHERE ss.status = 'published' AND s.is_active = 1
    GROUP BY s.id, s.section_id, tg.term, s.school_year_id
) AS averages;
