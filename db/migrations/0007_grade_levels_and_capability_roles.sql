-- 1) Grade Levels become a proper reference table instead of free text on sections —
-- keeps entry consistent (no "Grade 7" vs "G7" drift) once more than one admin is
-- entering sections, and fixes alphabetical sort ordering ("Grade 10" before "Grade 2").
CREATE TABLE grade_levels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(30) NOT NULL UNIQUE,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO grade_levels (name, sort_order) VALUES
    ('Kindergarten', 0),
    ('Grade 1', 1), ('Grade 2', 2), ('Grade 3', 3), ('Grade 4', 4),
    ('Grade 5', 5), ('Grade 6', 6), ('Grade 7', 7), ('Grade 8', 8),
    ('Grade 9', 9), ('Grade 10', 10), ('Grade 11', 11), ('Grade 12', 12);

ALTER TABLE sections ADD COLUMN grade_level_id INT NULL AFTER school_year_id;
UPDATE sections sec JOIN grade_levels gl ON gl.name = sec.grade_level SET sec.grade_level_id = gl.id;
ALTER TABLE sections MODIFY COLUMN grade_level_id INT NOT NULL;

-- Add the new unique key before dropping the old one — the old uq_section is the only
-- index covering school_year_id, which fk_sections_school_year needs to stay valid.
ALTER TABLE sections ADD CONSTRAINT uq_section_grade UNIQUE (school_year_id, grade_level_id, section_name);
ALTER TABLE sections DROP INDEX uq_section;
ALTER TABLE sections DROP COLUMN grade_level;
ALTER TABLE sections ADD CONSTRAINT fk_sections_grade_level FOREIGN KEY (grade_level_id) REFERENCES grade_levels(id);

-- 2) Adviser and Head Teacher become capabilities layered on top of Subject Teacher
-- (already determined relationally by sections.adviser_id / head_teacher_assignments),
-- not mutually-exclusive account roles — a real person is very often both.
UPDATE users SET role = 'subject_teacher' WHERE role IN ('head_teacher', 'adviser');
ALTER TABLE users MODIFY COLUMN role ENUM('subject_teacher','admin') NOT NULL;
