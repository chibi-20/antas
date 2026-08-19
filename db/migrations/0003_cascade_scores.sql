-- Let deleting an assessment item clean up its scores, so a teacher can remove a
-- mistakenly-added item without being blocked by leftover student_scores rows.
ALTER TABLE student_scores DROP FOREIGN KEY fk_scores_item;
ALTER TABLE student_scores ADD CONSTRAINT fk_scores_item FOREIGN KEY (assessment_item_id) REFERENCES assessment_items(id) ON DELETE CASCADE;
