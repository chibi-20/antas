-- Custom curriculum display order for Consolidated Grades / Card Slips (previously plain
-- alphabetical). A subject not in the seeded list below keeps the column default (100),
-- which sorts it after MAPEH — it stays a fully normal, fully-averaged subject; sort_order
-- only controls display position, never whether a subject counts toward the general average
-- (that's governed entirely by subjects.parent_subject_id / effective_term_grades).
ALTER TABLE subjects ADD COLUMN sort_order INT NOT NULL DEFAULT 100 AFTER subject_code;

UPDATE subjects SET sort_order = 1 WHERE subject_name = 'FILIPINO';
UPDATE subjects SET sort_order = 2 WHERE subject_name = 'ENGLISH';
UPDATE subjects SET sort_order = 3 WHERE subject_name = 'MATH';
UPDATE subjects SET sort_order = 4 WHERE subject_name = 'SCIENCE';
UPDATE subjects SET sort_order = 5 WHERE subject_name = 'ARALING PANLIPUNAN';
UPDATE subjects SET sort_order = 6 WHERE subject_name = 'VALUES EDUCATION';
UPDATE subjects SET sort_order = 7 WHERE subject_name = 'TECHNOLOGY AND LIVELIHOOD EDUCATION';
UPDATE subjects SET sort_order = 8 WHERE subject_name = 'MAPEH';
