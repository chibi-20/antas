-- Core schema for the Grade Consolidation, Ranking & Proficiency System.

CREATE TABLE school_years (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year_label VARCHAR(20) NOT NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NULL UNIQUE,
    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('subject_teacher','head_teacher','adviser','admin') NOT NULL,
    employee_number VARCHAR(50) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_year_id INT NOT NULL,
    grade_level VARCHAR(20) NOT NULL,
    section_name VARCHAR(100) NOT NULL,
    adviser_id INT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_section (school_year_id, grade_level, section_name),
    CONSTRAINT fk_sections_school_year FOREIGN KEY (school_year_id) REFERENCES school_years(id),
    CONSTRAINT fk_sections_adviser FOREIGN KEY (adviser_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE grade_weight_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    profile_name VARCHAR(100) NOT NULL UNIQUE,
    written_work_pct TINYINT UNSIGNED NOT NULL,
    performance_task_pct TINYINT UNSIGNED NOT NULL,
    quarterly_assessment_pct TINYINT UNSIGNED NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT chk_weights_sum_100 CHECK (written_work_pct + performance_task_pct + quarterly_assessment_pct = 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subject_name VARCHAR(150) NOT NULL,
    subject_code VARCHAR(30) NOT NULL,
    grade_level VARCHAR(20) NOT NULL,
    weight_profile_id INT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_subject_code_grade (subject_code, grade_level),
    CONSTRAINT fk_subjects_weight_profile FOREIGN KEY (weight_profile_id) REFERENCES grade_weight_profiles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE section_subject_teachers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_id INT NOT NULL,
    subject_id INT NOT NULL,
    teacher_id INT NOT NULL,
    school_year_id INT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_section_subject_year (section_id, subject_id, school_year_id),
    CONSTRAINT fk_sst_section FOREIGN KEY (section_id) REFERENCES sections(id),
    CONSTRAINT fk_sst_subject FOREIGN KEY (subject_id) REFERENCES subjects(id),
    CONSTRAINT fk_sst_teacher FOREIGN KEY (teacher_id) REFERENCES users(id),
    CONSTRAINT fk_sst_school_year FOREIGN KEY (school_year_id) REFERENCES school_years(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE head_teacher_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    head_teacher_id INT NOT NULL,
    subject_id INT NOT NULL,
    school_year_id INT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ht_subject_year (head_teacher_id, subject_id, school_year_id),
    CONSTRAINT fk_hta_head_teacher FOREIGN KEY (head_teacher_id) REFERENCES users(id),
    CONSTRAINT fk_hta_subject FOREIGN KEY (subject_id) REFERENCES subjects(id),
    CONSTRAINT fk_hta_school_year FOREIGN KEY (school_year_id) REFERENCES school_years(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lrn VARCHAR(20) NOT NULL UNIQUE,
    full_name VARCHAR(150) NOT NULL,
    sex ENUM('M','F') NOT NULL,
    birthdate DATE NULL,
    section_id INT NOT NULL,
    school_year_id INT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_students_section FOREIGN KEY (section_id) REFERENCES sections(id),
    CONSTRAINT fk_students_school_year FOREIGN KEY (school_year_id) REFERENCES school_years(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE assessment_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_subject_teacher_id INT NOT NULL,
    quarter TINYINT UNSIGNED NOT NULL,
    component_type ENUM('WW','PT','QA') NOT NULL,
    item_name VARCHAR(150) NOT NULL,
    highest_possible_score DECIMAL(6,2) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ai_sst FOREIGN KEY (section_subject_teacher_id) REFERENCES section_subject_teachers(id),
    CONSTRAINT chk_quarter_range CHECK (quarter BETWEEN 1 AND 4)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE student_scores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    assessment_item_id INT NOT NULL,
    raw_score DECIMAL(6,2) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_student_item (student_id, assessment_item_id),
    CONSTRAINT fk_scores_student FOREIGN KEY (student_id) REFERENCES students(id),
    CONSTRAINT fk_scores_item FOREIGN KEY (assessment_item_id) REFERENCES assessment_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE quarterly_grades (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    subject_id INT NOT NULL,
    quarter TINYINT UNSIGNED NOT NULL,
    ww_pct DECIMAL(5,2) NULL,
    pt_pct DECIMAL(5,2) NULL,
    qa_pct DECIMAL(5,2) NULL,
    initial_grade DECIMAL(5,2) NULL,
    transmuted_grade DECIMAL(5,2) NULL,
    school_year_id INT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_qg (student_id, subject_id, quarter, school_year_id),
    CONSTRAINT fk_qg_student FOREIGN KEY (student_id) REFERENCES students(id),
    CONSTRAINT fk_qg_subject FOREIGN KEY (subject_id) REFERENCES subjects(id),
    CONSTRAINT fk_qg_school_year FOREIGN KEY (school_year_id) REFERENCES school_years(id),
    CONSTRAINT chk_qg_quarter_range CHECK (quarter BETWEEN 1 AND 4)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE final_grades (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    subject_id INT NOT NULL,
    school_year_id INT NOT NULL,
    final_grade DECIMAL(5,2) NULL,
    UNIQUE KEY uq_fg (student_id, subject_id, school_year_id),
    CONSTRAINT fk_fg_student FOREIGN KEY (student_id) REFERENCES students(id),
    CONSTRAINT fk_fg_subject FOREIGN KEY (subject_id) REFERENCES subjects(id),
    CONSTRAINT fk_fg_school_year FOREIGN KEY (school_year_id) REFERENCES school_years(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE submission_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_subject_teacher_id INT NOT NULL,
    quarter TINYINT UNSIGNED NOT NULL,
    status ENUM('not_started','in_progress','submitted_for_review','returned_for_revision','published') NOT NULL DEFAULT 'not_started',
    submitted_at TIMESTAMP NULL,
    reviewed_by INT NULL,
    reviewed_at TIMESTAMP NULL,
    revision_comment TEXT NULL,
    UNIQUE KEY uq_submission (section_subject_teacher_id, quarter),
    CONSTRAINT fk_ss_sst FOREIGN KEY (section_subject_teacher_id) REFERENCES section_subject_teachers(id),
    CONSTRAINT fk_ss_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id),
    CONSTRAINT chk_ss_quarter_range CHECK (quarter BETWEEN 1 AND 4)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE report_cards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    school_year_id INT NOT NULL,
    quarter TINYINT UNSIGNED NULL,
    file_path VARCHAR(255) NULL,
    generated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rc_student FOREIGN KEY (student_id) REFERENCES students(id),
    CONSTRAINT fk_rc_school_year FOREIGN KEY (school_year_id) REFERENCES school_years(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Backs computeTransmutedGrade() as data, not hard-coded logic — see includes/gradeCalc.php.
-- PLACEHOLDER rows (DepEd 2020 simplified linear formula) until the user's real class record sample confirms the actual mapping.
CREATE TABLE transmutation_table (
    id INT AUTO_INCREMENT PRIMARY KEY,
    min_initial DECIMAL(5,2) NOT NULL,
    max_initial DECIMAL(5,2) NOT NULL,
    transmuted DECIMAL(5,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_students_section ON students(section_id);
CREATE INDEX idx_assessment_items_sst_quarter ON assessment_items(section_subject_teacher_id, quarter);
CREATE INDEX idx_quarterly_grades_lookup ON quarterly_grades(subject_id, quarter, school_year_id);
CREATE INDEX idx_sst_teacher ON section_subject_teachers(teacher_id);
CREATE INDEX idx_hta_head_teacher ON head_teacher_assignments(head_teacher_id);
