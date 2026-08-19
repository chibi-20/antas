-- General average + rank per section/quarter, aggregating only PUBLISHED subjects so an
-- unreviewed subject can never skew a student's rank.
CREATE OR REPLACE VIEW general_average_view AS
SELECT
    averages.student_id,
    averages.section_id,
    averages.quarter,
    averages.school_year_id,
    averages.average,
    RANK() OVER (PARTITION BY averages.section_id, averages.quarter ORDER BY averages.average DESC) AS rank_in_section
FROM (
    SELECT
        s.id AS student_id,
        s.section_id AS section_id,
        qg.quarter AS quarter,
        s.school_year_id AS school_year_id,
        ROUND(AVG(qg.transmuted_grade), 2) AS average
    FROM students s
    JOIN quarterly_grades qg
        ON qg.student_id = s.id AND qg.school_year_id = s.school_year_id
    JOIN section_subject_teachers sst
        ON sst.section_id = s.section_id
       AND sst.subject_id = qg.subject_id
       AND sst.school_year_id = s.school_year_id
    JOIN submission_status ss
        ON ss.section_subject_teacher_id = sst.id AND ss.quarter = qg.quarter
    WHERE ss.status = 'published' AND s.is_active = 1
    GROUP BY s.id, s.section_id, qg.quarter, s.school_year_id
) AS averages;
