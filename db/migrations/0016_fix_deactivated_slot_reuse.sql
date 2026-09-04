-- Bug: uq_sst_scope_v2 (section_id, subject_id, school_year_id, term_scope, sex_scope,
-- scope_dedup_teacher_id) has no is_active dimension, so a DEACTIVATED row permanently
-- occupies its slot -- deactivating a wrong assignment and then creating the correct one
-- (even for a completely different teacher) fails with "this combination may already exist",
-- because the raw unique key collides with the retired row even though sst_scope_conflict()
-- already correctly ignores inactive rows. This is the standard admin repair workflow
-- ("deactivate the mistake, add the right one"), so it needs to actually work.
--
-- Fix: a 3rd dedup column, same purpose as scope_dedup_teacher_id (0015_mix_sex_scope.sql) --
-- 0 for every active row (preserving today's uniqueness rule among active rows unchanged),
-- but a retired row's own id (globally unique) once deactivated, so a retired row never
-- blocks a future active one, or another retired one, from occupying that slot again.
--
-- NOT a GENERATED column this time -- MySQL/MariaDB both refuse to let a generated column
-- reference an AUTO_INCREMENT column (id isn't assigned yet when generated expressions would
-- need to compute it: "Function or expression 'AUTO_INCREMENT' cannot be used in the
-- GENERATED ALWAYS AS clause"). Instead it's a plain column the app keeps in sync -- there is
-- exactly one place in the codebase that ever flips is_active on this table
-- (admin/assignments.php's toggle_active action), so this is fully controllable there.
-- DEFAULT 0 matches every existing row today (all active) and every future INSERT (which
-- never sets is_active explicitly, so it's always created active via the column's own
-- DEFAULT 1) without needing a backfill.
ALTER TABLE section_subject_teachers
  ADD COLUMN retired_dedup_id INT NOT NULL DEFAULT 0
  AFTER scope_dedup_teacher_id;

-- DEFAULT 0 above is correct for active rows and all future inserts, but any row that was
-- ALREADY deactivated before this migration runs also got 0 from that same DEFAULT -- which
-- would still block its slot after this migration, defeating the whole point. Backfill those
-- to their own id (each row's id is globally unique, so this can never collide with itself or
-- any other row, active or retired).
UPDATE section_subject_teachers SET retired_dedup_id = id WHERE is_active = 0;

CREATE UNIQUE INDEX uq_sst_scope_v3
  ON section_subject_teachers(section_id, subject_id, school_year_id, term_scope, sex_scope, scope_dedup_teacher_id, retired_dedup_id);
ALTER TABLE section_subject_teachers DROP INDEX uq_sst_scope_v2;
