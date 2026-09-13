-- The Ranking page's General Average is now used to decide a real LGU cash voucher, so ties
-- need to be broken as finely as the underlying grades actually allow. Rounding to 2 decimals
-- was creating avoidable exact ties between students whose true averages differed once the
-- source transmuted grades themselves carry a fractional part; 3 decimals keeps that
-- distinction instead of throwing it away before ranking. rank_in_section is computed from
-- this same, now-more-precise `average` column, so its tie-breaking sharpens along with it —
-- no separate change needed there.
CREATE OR REPLACE VIEW general_average_view AS
SELECT averages.student_id, averages.section_id, averages.term, averages.school_year_id, averages.average,
       RANK() OVER (PARTITION BY averages.section_id, averages.term ORDER BY averages.average DESC) AS rank_in_section
FROM (
    SELECT s.id AS student_id, s.section_id, eg.term, s.school_year_id, ROUND(AVG(eg.transmuted_grade), 3) AS average
    FROM students s
    JOIN effective_term_grades eg ON eg.student_id = s.id AND eg.school_year_id = s.school_year_id AND eg.section_id = s.section_id
    WHERE s.is_active = 1
    GROUP BY s.id, s.section_id, eg.term, s.school_year_id
) averages;
