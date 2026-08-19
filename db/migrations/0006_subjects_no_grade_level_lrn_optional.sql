-- Subjects apply to every grade level (grade level lives on sections, not subjects) --
-- drop the now-incorrect grade_level column and its composite unique key.
ALTER TABLE subjects DROP INDEX uq_subject_code_grade;
ALTER TABLE subjects DROP COLUMN grade_level;
ALTER TABLE subjects ADD CONSTRAINT uq_subject_code UNIQUE (subject_code);

-- LRN is sensitive PII and shouldn't be required for bulk student import (name + sex
-- only, to avoid leaking identifying data in a spreadsheet). Fill it in later per student.
ALTER TABLE students MODIFY COLUMN lrn VARCHAR(20) NULL;
