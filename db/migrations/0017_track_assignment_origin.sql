-- Tracks whether a section_subject_teachers row was created by an admin or self-claimed by
-- the teacher via teacher/claim.php -- needed to trace which teacher claimed a wrong section,
-- since today every row looks identical regardless of how it was created (only a created_at
-- timestamp exists, no origin). Existing rows default to 'admin' -- not retroactively accurate
-- for old self-claims, but this is about tracing things going forward, not rewriting history.
ALTER TABLE section_subject_teachers
  ADD COLUMN created_via ENUM('admin', 'self_claim') NOT NULL DEFAULT 'admin'
  AFTER teacher_id;
