-- Values Education's domain-based class record (the school's official VE/GMRC e-class record):
-- Written Work splits into Cognitive + Affective, Performance Tasks into Cognitive + Affective +
-- Behavioral, each domain scored on its own. Chosen per subject by admin; 'standard' keeps the
-- plain WW/PT/EX record every other subject uses.
ALTER TABLE subjects ADD COLUMN class_record_format ENUM('standard', 'domains') NOT NULL DEFAULT 'standard';

-- NULL for every standard-record item. A term whose items carry a domain is computed with the
-- domain formula (see includes/gradeCalc.php) — so a term keeps whichever structure it was
-- created with even if the subject's format is switched later.
ALTER TABLE assessment_items ADD COLUMN domain ENUM('cognitive', 'affective', 'behavioral') NULL DEFAULT NULL;
