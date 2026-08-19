-- Adopts DepEd Order No. 15 s. 2026: "Quarterly Assessment (QA)" is renamed to
-- "Examinations (EX)", and the Key Stage 2 & 3 (Grades 4-10) weight groups + the SY
-- 2026-2027 Adjusted Transmutation Table become the real (no longer placeholder) data.

-- Rename the QA component to EX. Widen the enum first so existing 'QA' rows can be
-- updated to 'EX' before narrowing back down.
ALTER TABLE assessment_items MODIFY component_type ENUM('WW','PT','QA','EX') NOT NULL;
UPDATE assessment_items SET component_type = 'EX' WHERE component_type = 'QA';
ALTER TABLE assessment_items MODIFY component_type ENUM('WW','PT','EX') NOT NULL;

ALTER TABLE quarterly_grades CHANGE COLUMN qa_pct ex_pct DECIMAL(5,2) NULL;

ALTER TABLE grade_weight_profiles DROP CONSTRAINT chk_weights_sum_100;
ALTER TABLE grade_weight_profiles CHANGE COLUMN quarterly_assessment_pct examination_pct TINYINT UNSIGNED NOT NULL;
ALTER TABLE grade_weight_profiles ADD CONSTRAINT chk_weights_sum_100 CHECK (written_work_pct + performance_task_pct + examination_pct = 100);

-- Real Key Stage 2 & 3 (Grades 4-10) weight groups, updated in place so existing
-- subjects.weight_profile_id references stay valid.
UPDATE grade_weight_profiles SET
    profile_name = 'English, Filipino, Math, Science, AP, GMRC/VE (WW20/PT50/EX30)',
    written_work_pct = 20, performance_task_pct = 50, examination_pct = 30, is_active = 1
    WHERE id = 2;
UPDATE grade_weight_profiles SET
    profile_name = 'EPP/TLE, MAPEH (WW20/PT60/EX20)',
    written_work_pct = 20, performance_task_pct = 60, examination_pct = 20, is_active = 1
    WHERE id = 3;
-- The old 3-way split no longer matches DepEd Order No. 15 s. 2026's 2-group table. Retire it.
UPDATE grade_weight_profiles SET is_active = 0 WHERE id = 1;

-- Replace the placeholder linear formula with the real SY 2026-2027 Adjusted
-- Transmutation Table (zero-based grading transition: IG 70.00 -> TG 75).
DELETE FROM transmutation_table;
INSERT INTO transmutation_table (min_initial, max_initial, transmuted) VALUES
    (99.50, 100.00, 100),
    (98.32, 99.49, 99),
    (97.14, 98.31, 98),
    (95.96, 97.13, 97),
    (94.78, 95.95, 96),
    (93.60, 94.77, 95),
    (92.42, 93.59, 94),
    (91.24, 92.41, 93),
    (90.06, 91.23, 92),
    (88.88, 90.05, 91),
    (87.70, 88.87, 90),
    (86.52, 87.69, 89),
    (85.34, 86.51, 88),
    (84.16, 85.33, 87),
    (82.98, 84.15, 86),
    (81.80, 82.97, 85),
    (80.62, 81.79, 84),
    (79.44, 80.61, 83),
    (78.26, 79.43, 82),
    (77.08, 78.25, 81),
    (75.90, 77.07, 80),
    (74.72, 75.89, 79),
    (73.54, 74.71, 78),
    (72.36, 73.53, 77),
    (71.18, 72.35, 76),
    (70.00, 71.17, 75),
    (65.34, 69.99, 74),
    (60.67, 65.33, 73),
    (56.01, 60.66, 72),
    (51.34, 56.00, 71),
    (46.67, 51.33, 70),
    (42.01, 46.66, 69),
    (37.34, 42.00, 68),
    (32.68, 37.33, 67),
    (28.01, 32.67, 66),
    (23.35, 28.00, 65),
    (18.68, 23.34, 64),
    (14.01, 18.67, 63),
    (9.35, 14.00, 62),
    (4.68, 9.34, 61),
    (0.00, 4.67, 60);
