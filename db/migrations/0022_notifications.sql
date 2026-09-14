CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('published', 'returned_for_revision', 'submitted_for_review', 'edit_request', 'section_grade_published') NOT NULL,
    section_subject_teacher_id INT NOT NULL,
    section_id INT NOT NULL,
    term TINYINT UNSIGNED NOT NULL,
    detail VARCHAR(255) NOT NULL,
    href VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_notifications_sst FOREIGN KEY (section_subject_teacher_id) REFERENCES section_subject_teachers(id),
    CONSTRAINT fk_notifications_section FOREIGN KEY (section_id) REFERENCES sections(id),
    KEY idx_notifications_user_unread (user_id, read_at, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
