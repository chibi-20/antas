-- Records why a student's term grade came in below 75, so a Head Teacher checking grades can
-- see the reason instead of just the number. A new table, not a term_grades column -- term_grades
-- rows are overwritten (ON DUPLICATE KEY UPDATE) on every recompute, so a reason column would
-- need explicit preserve-on-recompute logic; a separate table keyed to the same natural tuple
-- sidesteps that entirely.
CREATE TABLE term_grade_fail_reasons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    subject_id INT NOT NULL,
    term TINYINT UNSIGNED NOT NULL,
    school_year_id INT NOT NULL,
    reason ENUM('low_scores','missing_pt','missing_qa','absences','noncompliance_modular',
                'no_remediation','behavioral','health_absences','personal_family','other') NOT NULL,
    reason_other TEXT NULL,
    recorded_by INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tgfr (student_id, subject_id, term, school_year_id),
    CONSTRAINT fk_tgfr_student FOREIGN KEY (student_id) REFERENCES students(id),
    CONSTRAINT fk_tgfr_subject FOREIGN KEY (subject_id) REFERENCES subjects(id),
    CONSTRAINT fk_tgfr_recorded_by FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
