<?php
declare(strict_types=1);

// One-off (safe to re-run) script that recomputes every existing term_grades row using the
// CURRENT grading formula in includes/gradeCalc.php — run this once after a formula change
// (e.g. the Examinations component's new 30/30/40 weighting) so grades already entered by
// teachers — including ones already published — reflect the corrected calculation
// immediately, instead of only updating the next time someone edits a score in that term.
//
// Usage (from the project root, on whichever server/database you want to update):
//   php db/recompute_grades.php
//
// Prints every transmuted grade that actually changed (old -> new) so you can review exactly
// what this touched before/after. Purely a recalculation from existing student_scores — it
// never deletes or edits raw scores, and re-running it again is a no-op for anything already
// correct.

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/gradeCalc.php';

$pdo = db();

$assignments = $pdo->query('SELECT sst.id, sst.subject_id, sst.school_year_id, sub.subject_name,
        gl.name AS grade_level, sec.section_name
    FROM section_subject_teachers sst
    JOIN subjects sub ON sub.id = sst.subject_id
    JOIN sections sec ON sec.id = sst.section_id
    JOIN grade_levels gl ON gl.id = sec.grade_level_id
    WHERE sst.is_active = 1
    ORDER BY gl.sort_order, sec.section_name, sub.subject_name')->fetchAll();

$gradeStmt = $pdo->prepare('SELECT tg.student_id, tg.transmuted_grade, st.full_name
    FROM term_grades tg JOIN students st ON st.id = tg.student_id
    WHERE tg.subject_id = ? AND tg.term = ? AND tg.school_year_id = ?');

$processed = 0;
$changedCount = 0;

foreach ($assignments as $a) {
    for ($term = 1; $term <= 3; $term++) {
        $gradeStmt->execute([$a['subject_id'], $term, $a['school_year_id']]);
        $before = [];
        foreach ($gradeStmt->fetchAll() as $row) {
            $before[$row['student_id']] = $row['transmuted_grade'];
        }

        recompute_term_grades_for_assignment((int) $a['id'], $term);
        $processed++;

        $gradeStmt->execute([$a['subject_id'], $term, $a['school_year_id']]);
        foreach ($gradeStmt->fetchAll() as $row) {
            $old = $before[$row['student_id']] ?? null;
            $new = $row['transmuted_grade'];
            if ((string) $old !== (string) $new) {
                $changedCount++;
                fwrite(STDOUT, sprintf(
                    "%s - %s - %s - Term %d - %s: %s -> %s\n",
                    $a['grade_level'], $a['section_name'], $a['subject_name'], $term, $row['full_name'],
                    $old ?? '(none)', $new ?? '(none)'
                ));
            }
        }
    }
}

fwrite(STDOUT, "\nDone. Checked $processed section/subject/term combination(s); $changedCount grade(s) actually changed.\n");
