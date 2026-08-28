-- Self-claim eligibility: a lightweight capability tag ("Teacher X may self-claim
-- [Subject] for [Grade Level]") layered on top of Subject Teacher, mirroring
-- head_teacher_assignments (0001_init.sql). NOT a real assignment — checked only by
-- teacher/claim.php's guard before it creates a real section_subject_teachers row.
-- Scoped by grade_level_id (not section_id) so a multi-section teacher doesn't need one
-- row per section; subjects no longer carry a grade level (0006_subjects_no_grade_level_
-- lrn_optional.sql), so this is the only place that scoping lives for this feature.
CREATE TABLE claim_eligibility (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    subject_id INT NOT NULL,
    grade_level_id INT NOT NULL,
    school_year_id INT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_claim_eligibility (teacher_id, subject_id, grade_level_id, school_year_id),
    CONSTRAINT fk_ce_teacher FOREIGN KEY (teacher_id) REFERENCES users(id),
    CONSTRAINT fk_ce_subject FOREIGN KEY (subject_id) REFERENCES subjects(id),
    CONSTRAINT fk_ce_grade_level FOREIGN KEY (grade_level_id) REFERENCES grade_levels(id),
    CONSTRAINT fk_ce_school_year FOREIGN KEY (school_year_id) REFERENCES school_years(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
