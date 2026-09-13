-- Teacher's Comments/Remarks, matching the official SF9 report card — entered by the section
-- adviser, one per student per term, printed on the new 2-per-sheet Card Slips layout.

CREATE TABLE remark_bank (
    id INT AUTO_INCREMENT PRIMARY KEY,
    remark_text VARCHAR(255) NOT NULL,
    category ENUM('positive','needs_improvement') NOT NULL DEFAULT 'positive',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO remark_bank (remark_text, category) VALUES
    ('Always ready, hardworking, and positive in every school task.', 'positive'),
    ('Excellent in following instructions and completing tasks with quality.', 'positive'),
    ('Shows exceptional skill in all tasks and continuously strives to be excellent.', 'positive'),
    ('Easily grasps advanced topics and consistently seeks out extension activities to challenge themselves.', 'positive'),
    ('Always active in class and gives meaningful contributions to discussions.', 'positive'),
    ('Shows excellent behavior, discipline, and responsibility as a student.', 'positive'),
    ('Continuously shows a high level of diligence and dedication to studying.', 'positive'),
    ('Showed great improvement and excellence in various subjects.', 'positive'),
    ('Has excellent ability in understanding and applying fundamental concepts.', 'positive'),
    ('Takes pride in their excellent performance and being a model student.', 'positive'),
    ('Shows creativity and excellent skills in completing tasks.', 'positive'),
    ('Continuously inspires their classmates through good examples.', 'positive'),
    ('Needs additional help to understand the lessons.', 'needs_improvement'),
    ('Must improve diligence and neatness in doing tasks.', 'needs_improvement'),
    ('Needs additional guidance in understanding the lessons.', 'needs_improvement');

-- remark_bank_id is nullable (a custom "Other" remark has no bank entry); remark_text is a
-- snapshot of the actual text printed, copied at save time — so editing or deactivating a bank
-- entry later never silently changes what an already-saved remark says, same snapshot
-- philosophy already used for grade_edit_history.
CREATE TABLE student_term_remarks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    term TINYINT UNSIGNED NOT NULL,
    school_year_id INT NOT NULL,
    remark_bank_id INT NULL,
    remark_text VARCHAR(255) NOT NULL,
    recorded_by INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_str (student_id, term, school_year_id),
    CONSTRAINT fk_str_student FOREIGN KEY (student_id) REFERENCES students(id),
    CONSTRAINT fk_str_remark_bank FOREIGN KEY (remark_bank_id) REFERENCES remark_bank(id),
    CONSTRAINT fk_str_recorded_by FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
