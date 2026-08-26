-- Post-publish grade correction workflow: a teacher whose term is already published can
-- request an edit with a reason; only the Head Teacher who supervises that subject can
-- approve (reopening the term for editing, same lock/unlock mechanism as submission_status
-- already uses) or reject it. grade_edit_history captures a clean before/after diff per
-- student: "old" is snapshotted the moment the request is approved, "new" is filled in only
-- once the Head Teacher re-publishes the reopened term (see headteacher/review.php) — so a
-- teacher saving multiple times during the edit window produces ONE diff, not one per save.
CREATE TABLE grade_edit_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_subject_teacher_id INT NOT NULL,
    term TINYINT UNSIGNED NOT NULL,
    requested_by INT NOT NULL,
    reason TEXT NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by INT NULL,
    reviewed_at TIMESTAMP NULL,
    review_comment TEXT NULL,
    finalized_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ger_sst FOREIGN KEY (section_subject_teacher_id) REFERENCES section_subject_teachers(id),
    CONSTRAINT fk_ger_requested_by FOREIGN KEY (requested_by) REFERENCES users(id),
    CONSTRAINT fk_ger_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id),
    CONSTRAINT chk_ger_term_range CHECK (term BETWEEN 1 AND 3)
);
CREATE INDEX idx_ger_sst_term ON grade_edit_requests(section_subject_teacher_id, term, status);

CREATE TABLE grade_edit_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    edit_request_id INT NOT NULL,
    student_id INT NOT NULL,
    old_transmuted_grade DECIMAL(5,2) NULL,
    new_transmuted_grade DECIMAL(5,2) NULL,
    CONSTRAINT fk_geh_request FOREIGN KEY (edit_request_id) REFERENCES grade_edit_requests(id),
    CONSTRAINT fk_geh_student FOREIGN KEY (student_id) REFERENCES students(id)
);
CREATE INDEX idx_geh_request ON grade_edit_history(edit_request_id);
