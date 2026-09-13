<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/gradeCalc.php';

$sstId = (int) ($_GET['sst_id'] ?? $_POST['sst_id'] ?? 0);
$term = (int) ($_GET['term'] ?? $_POST['term'] ?? 1);
if ($term < 1 || $term > 3) {
    $term = 1;
}

$assignment = require_own_assignment($sstId);
assert_covers_term($assignment, $term, '/teacher/index.php',
    "You don't teach {$assignment['subject_name']} for {$assignment['grade_level']} - {$assignment['section_name']} in Term $term — here are your classes.");
$pdo = db();

// Male-then-Female, alphabetical within each — the standard class record roster order.
// Restricted to this assignment's sex_scope (e.g. a boys-only TLE assignment never shows
// girls, even though they share a section_id). Resolved up here (rather than just before
// rendering, where it used to live) so the POST handler below can also use it — needed to
// check for missing scores before allowing "Save & Submit for Review" to go through.
$sexScope = strtoupper(trim((string) ($assignment['sex_scope'] ?? 'ALL')));

if ($sexScope === 'ALL') {
    // A major-scoped assignment (Special Program sections — see db/migrations/0019) only
    // covers students of that one major; major_id is NULL for every ordinary assignment, so
    // this is a no-op there.
    $sql = "SELECT * FROM students WHERE section_id = ? AND is_active = 1";
    $params = [$assignment['section_id']];
    if ($assignment['major_id'] !== null) {
        $sql .= " AND major_id = ?";
        $params[] = $assignment['major_id'];
    }
    $sql .= " ORDER BY FIELD(sex, 'M', 'F'), full_name";
    $studentsStmt = $pdo->prepare($sql);
    $studentsStmt->execute($params);
} elseif ($sexScope === 'MIX') {
    // No section/sex filter needed at all — sst_student_claims already scopes exactly to
    // this assignment.
    $studentsStmt = $pdo->prepare("
        SELECT st.*
        FROM students st
        JOIN sst_student_claims ssc ON ssc.student_id = st.id AND ssc.section_subject_teacher_id = ?
        WHERE st.is_active = 1
        ORDER BY FIELD(st.sex, 'M', 'F'), st.full_name
    ");
    $studentsStmt->execute([$sstId]);
} else {
    $studentsStmt = $pdo->prepare("
        SELECT *
        FROM students
        WHERE section_id = ?
          AND is_active = 1
          AND sex = ?
        ORDER BY FIELD(sex, 'M', 'F'), full_name
    ");

    $studentsStmt->execute([
        $assignment['section_id'],
        $sexScope,
    ]);
}

$students = $studentsStmt->fetchAll();

$statusStmt = $pdo->prepare('SELECT * FROM submission_status WHERE section_subject_teacher_id = ? AND term = ?');
$statusStmt->execute([$sstId, $term]);
$submission = $statusStmt->fetch();
$editable = !$submission || !in_array($submission['status'], ['submitted_for_review', 'published'], true);

// Most recent edit request for this assignment/term, if the term is published — drives the
// "Request Edit" UI below (a pending one blocks a new request; a rejected one shows why and
// lets the teacher try again with a new reason).
$editRequest = null;
if ($submission && $submission['status'] === 'published') {
    $erStmt = $pdo->prepare('SELECT ger.*, u.full_name AS reviewed_by_name FROM grade_edit_requests ger
        LEFT JOIN users u ON u.id = ger.reviewed_by
        WHERE ger.section_subject_teacher_id = ? AND ger.term = ? ORDER BY ger.created_at DESC LIMIT 1');
    $erStmt->execute([$sstId, $term]);
    $editRequest = $erStmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (!$editable) {
        forbidden('This term is locked and can no longer be edited.');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'add_item') {
        $componentType = (string) ($_POST['component_type'] ?? '');
        $itemName = trim((string) ($_POST['item_name'] ?? ''));
        $highest = (float) ($_POST['highest_possible_score'] ?? 0);
        // Examinations is a fixed Summative Test 1 / Summative Test 2 / Term Exam structure
        // (see EX_WEIGHTS in gradeCalc.php) — no adding a 4th item to it.
        if (!in_array($componentType, ['WW', 'PT'], true) || $itemName === '' || $highest <= 0) {
            flash_set('error', 'Item name, component, and a highest possible score above 0 are required.');
        } else {
            $sort = (int) $pdo->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM assessment_items WHERE section_subject_teacher_id = $sstId AND term = $term AND component_type = " . $pdo->quote($componentType))->fetchColumn();
            $pdo->prepare('INSERT INTO assessment_items (section_subject_teacher_id, term, component_type, item_name, highest_possible_score, sort_order) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$sstId, $term, $componentType, $itemName, $highest, $sort]);
            recompute_term_grades_for_assignment($sstId, $term);
            flash_set('success', "Added \"$itemName\".");
        }
    } elseif ($action === 'delete_item') {
        $itemId = (int) $_POST['item_id'];
        // Never allow deleting an Examinations item — the fixed 3-item structure is what
        // makes the 30/30/40 weighted formula meaningful (see EX_WEIGHTS in gradeCalc.php).
        $deleted = $pdo->prepare("DELETE FROM assessment_items WHERE id = ? AND section_subject_teacher_id = ? AND component_type <> 'EX'");
        $deleted->execute([$itemId, $sstId]);
        if ($deleted->rowCount() > 0) {
            recompute_term_grades_for_assignment($sstId, $term);
            flash_set('success', 'Item removed.');
        } else {
            flash_set('error', 'Examinations items cannot be removed.');
        }
    } elseif ($action === 'save_scores') {
        // Column headers (name + highest score) are edited inline in the same grid/form —
        // save any changes before scores, since scores are validated below against the fresh
        // highest_possible_score, not whatever the browser's max= was at page load (that's
        // client-side only and can go stale the moment a teacher edits it in the same visit —
        // see assets/js/app.js's initGradePreview for the matching client-side fix).
        $itemNames = $_POST['item_name'] ?? [];
        $itemHighest = $_POST['item_highest'] ?? [];
        if ($itemNames) {
            $updateItem = $pdo->prepare('UPDATE assessment_items SET item_name = ?, highest_possible_score = ? WHERE id = ? AND section_subject_teacher_id = ?');
            foreach ($itemNames as $itemId => $name) {
                $name = trim((string) $name);
                $highest = (float) ($itemHighest[$itemId] ?? 0);
                if ($name !== '' && $highest > 0) {
                    $updateItem->execute([$name, $highest, (int) $itemId, $sstId]);
                }
            }
        }

        // Authoritative bounds for the validation below — fetched fresh, after the update
        // above, so a highest-score change in this same submission is respected immediately.
        $currentItems = $pdo->prepare('SELECT id, item_name, highest_possible_score FROM assessment_items WHERE section_subject_teacher_id = ?');
        $currentItems->execute([$sstId]);
        $itemInfo = [];
        foreach ($currentItems->fetchAll() as $row) {
            $itemInfo[(int) $row['id']] = ['name' => $row['item_name'], 'highest' => (float) $row['highest_possible_score']];
        }

        $scores = $_POST['scores'] ?? [];
        $upsert = $pdo->prepare('INSERT INTO student_scores (student_id, assessment_item_id, raw_score) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE raw_score = VALUES(raw_score)');
        // Rejected cells are skipped, not clamped — silently capping a score a teacher typed
        // (e.g. an 80 meant to be an 8) would hide the mistake instead of surfacing it. Every
        // other valid cell in the same submission still saves; only the out-of-range ones are
        // held back and reported.
        $rejected = [];
        foreach ($scores as $itemId => $byStudent) {
            $itemId = (int) $itemId;
            $info = $itemInfo[$itemId] ?? null;
            foreach ($byStudent as $studentId => $rawValue) {
                $rawValue = trim((string) $rawValue);
                if ($rawValue === '') {
                    $upsert->execute([(int) $studentId, $itemId, null]);
                    continue;
                }
                if (!$info) {
                    continue;
                }
                // Whole numbers only — the input's step="1" already nudges this, but that's
                // client-side only, so round here too rather than trust it.
                $rounded = (float) round((float) $rawValue);
                if ($rounded < 0 || $rounded > $info['highest']) {
                    $rejected[] = ['student_id' => (int) $studentId, 'item_name' => $info['name'], 'highest' => $info['highest'], 'entered' => $rounded];
                    continue;
                }
                $upsert->execute([(int) $studentId, $itemId, $rounded]);
            }
        }
        recompute_term_grades_for_assignment($sstId, $term);
        if ($submission && $submission['status'] === 'not_started') {
            $pdo->prepare("UPDATE submission_status SET status = 'in_progress' WHERE section_subject_teacher_id = ? AND term = ?")->execute([$sstId, $term]);
        }

        // Reasons for a below-75 grade are saved whenever posted, on any Save click — the
        // picker is shown inline for any currently-failing student regardless of which button
        // was clicked, same as scores themselves, not gated behind Submit.
        $failReasons = $_POST['fail_reason'] ?? [];
        $failReasonOther = $_POST['fail_reason_other'] ?? [];
        if ($failReasons) {
            $studentIds = array_column($students, 'id');
            $recordedBy = (int) current_user()['id'];
            $reasonUpsert = $pdo->prepare('INSERT INTO term_grade_fail_reasons (student_id, subject_id, term, school_year_id, reason, reason_other, recorded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE reason = VALUES(reason), reason_other = VALUES(reason_other), recorded_by = VALUES(recorded_by)');
            foreach ($failReasons as $studentId => $reason) {
                $studentId = (int) $studentId;
                if (!in_array($studentId, $studentIds, true) || !array_key_exists($reason, FAIL_REASON_LABELS)) {
                    continue;
                }
                $otherText = $reason === 'other' ? trim((string) ($failReasonOther[$studentId] ?? '')) : null;
                if ($reason === 'other' && $otherText === '') {
                    continue; // "Other" needs the actual explanation before it's worth saving
                }
                $reasonUpsert->execute([$studentId, $assignment['subject_id'], $term, $assignment['school_year_id'], $reason, $otherText, $recordedBy]);
            }
        }

        // "Submit for Review" is a second submit button in this same form (not a separate
        // form/page) specifically so a click always saves whatever's on screen first — the
        // two used to be independent, and a teacher who clicked Submit without clicking Save
        // Scores first would lock the term with none of their scores ever persisted.
        $submitForReview = ($_POST['post_action'] ?? '') === 'submit_for_review';
        $incomplete = $submitForReview && !$rejected ? find_incomplete_graded_items($pdo, $sstId, $term, $students) : [];
        $missingReasons = $submitForReview && !$rejected && !$incomplete
            ? find_missing_fail_reasons($pdo, (int) $assignment['subject_id'], $term, (int) $assignment['school_year_id'], $students)
            : [];
        if ($submitForReview && !$rejected && $incomplete) {
            // Grouped by student (one line each, every missing item listed together) rather
            // than one line per student/item pair — repeating the same name for each gap it's
            // still hard to scan once a student is missing more than one score.
            $itemsByStudent = [];
            foreach ($incomplete as $i) {
                $itemsByStudent[$i['student_name']][] = $i['item_name'];
            }
            $lines = [];
            foreach ($itemsByStudent as $studentName => $itemNames) {
                $lines[] = "• $studentName — " . implode(', ', $itemNames);
            }
            flash_set('error', "Can't submit yet — the following have no score for an item the rest of the class already has one for (enter 0 if they didn't complete it):\n"
                . implode("\n", $lines));
        } elseif ($submitForReview && !$rejected && $missingReasons) {
            $lines = array_map(fn($m) => "• {$m['student_name']}", $missingReasons);
            flash_set('error', "Can't submit yet — pick a reason for the below-75 grade for:\n" . implode("\n", $lines));
        } elseif ($submitForReview && !$rejected) {
            $currentStatus = $pdo->prepare('SELECT status FROM submission_status WHERE section_subject_teacher_id = ? AND term = ?');
            $currentStatus->execute([$sstId, $term]);
            $statusNow = $currentStatus->fetchColumn();
            if (in_array($statusNow, ['not_started', 'in_progress', 'returned_for_revision'], true)) {
                $pdo->prepare("UPDATE submission_status SET status = 'submitted_for_review', submitted_at = NOW(), revision_comment = NULL WHERE section_subject_teacher_id = ? AND term = ?")
                    ->execute([$sstId, $term]);
                flash_set('success', 'Scores saved and submitted for Head Teacher review.');
            } else {
                flash_set('error', 'This term cannot be submitted from its current status.');
            }
        } elseif ($rejected) {
            $nameStmt = $pdo->prepare('SELECT full_name FROM students WHERE id = ?');
            $details = [];
            foreach ($rejected as $r) {
                $nameStmt->execute([$r['student_id']]);
                $studentName = $nameStmt->fetchColumn() ?: 'that student';
                $details[] = "$studentName — {$r['item_name']}: {$r['entered']} (highest is {$r['highest']})";
            }
            $msg = 'Everything else saved, but ' . count($rejected) . ' score(s) were out of range and NOT saved: ' . implode('; ', $details);
            if ($submitForReview) {
                $msg .= ' Fix these and submit again — nothing was submitted for review.';
            }
            flash_set('error', $msg);
        } else {
            flash_set('success', 'Scores saved.');
        }
    }
    redirect("/teacher/class_record.php?sst_id=$sstId&term=$term");
}

// Auto-template the standard 5 Written Work / 3 Performance Task / 3 Examination (Summative
// Test 1, Summative Test 2, Term Exam) items the first time this term is opened, so the
// teacher lands on a ready-to-fill grid instead of building columns one at a time. Only
// fires while the term has never been touched (status still not_started) — if a teacher
// deliberately deletes a WW/PT item afterward, it won't silently come back (EX items can't
// be deleted at all — see EX_WEIGHTS below).
if ($editable && (!$submission || $submission['status'] === 'not_started')) {
    $existingCount = $pdo->prepare('SELECT COUNT(*) FROM assessment_items WHERE section_subject_teacher_id = ? AND term = ?');
    $existingCount->execute([$sstId, $term]);
    if ((int) $existingCount->fetchColumn() === 0) {
        $defaults = [
            'WW' => ['WW 1', 'WW 2', 'WW 3', 'WW 4', 'WW 5'],
            'PT' => ['PT 1', 'PT 2', 'PT 3'],
            'EX' => ['Summative Test 1', 'Summative Test 2', 'Term Exam'],
        ];
        // EX's default point totals mirror a realistic 25-item/25-item/50-item test split —
        // they're independent of the 30%/30%/40% WEIGHT breakdown gradeCalc.php applies (a
        // teacher can still edit these to match their actual test's item count).
        $exHighest = [25, 25, 50];
        $insertDefault = $pdo->prepare('INSERT INTO assessment_items (section_subject_teacher_id, term, component_type, item_name, highest_possible_score, sort_order) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($defaults as $type => $names) {
            foreach ($names as $i => $name) {
                $highest = $type === 'EX' ? $exHighest[$i] : 100;
                $insertDefault->execute([$sstId, $term, $type, $name, $highest, $i + 1]);
            }
        }
    }
}

$items = $pdo->prepare('SELECT * FROM assessment_items WHERE section_subject_teacher_id = ? AND term = ? ORDER BY component_type, sort_order, id');
$items->execute([$sstId, $term]);
$items = $items->fetchAll();
$itemsByType = ['WW' => [], 'PT' => [], 'EX' => []];
foreach ($items as $item) {
    $itemsByType[$item['component_type']][] = $item;
}
// Stable left-to-right column index per item, for the Excel-style paste grid (data-row/data-col).
$itemColumns = [];
$col = 0;
foreach (['WW', 'PT', 'EX'] as $type) {
    foreach ($itemsByType[$type] as $item) {
        $itemColumns[$item['id']] = $col++;
    }
}

$scoreLookup = [];
if ($items) {
    $itemIds = array_column($items, 'id');
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $scoreStmt = $pdo->prepare("SELECT student_id, assessment_item_id, raw_score FROM student_scores WHERE assessment_item_id IN ($placeholders)");
    $scoreStmt->execute($itemIds);
    foreach ($scoreStmt->fetchAll() as $row) {
        $scoreLookup[$row['assessment_item_id']][$row['student_id']] = $row['raw_score'];
    }
}

$gradeStmt = $pdo->prepare('SELECT * FROM term_grades WHERE student_id = ? AND subject_id = ? AND term = ?');
$componentLabels = ['WW' => 'Written Work', 'PT' => 'Performance Task', 'EX' => 'Examinations'];

// Any reason already saved for this subject/term, so the picker below re-shows what was
// chosen last time instead of resetting to blank on every page load.
$existingReasonsStmt = $pdo->prepare('SELECT student_id, reason, reason_other FROM term_grade_fail_reasons WHERE subject_id = ? AND term = ? AND school_year_id = ?');
$existingReasonsStmt->execute([$assignment['subject_id'], $term, $assignment['school_year_id']]);
$existingReasons = [];
foreach ($existingReasonsStmt->fetchAll() as $r) {
    $existingReasons[(int) $r['student_id']] = ['reason' => $r['reason'], 'reason_other' => $r['reason_other']];
}
// Collected while looping the roster below (grade_display_class() already flags the same
// below-75 threshold) — a currently-failing student gets an inline reason picker; one who
// recovers on a later save simply stops appearing here and is never required to have one.
$failingStudents = [];

// Prior terms' transmuted grade for THIS subject, so a teacher sees the running record
// (not just the currently selected term) once they're on Term 2 or 3.
$priorGrades = [];
for ($t = 1; $t < $term; $t++) {
    $priorGrades[$t] = [];
}
if ($students && $term > 1) {
    $studentIds = array_column($students, 'id');
    $studentPlaceholders = implode(',', array_fill(0, count($studentIds), '?'));
    $priorTerms = range(1, $term - 1);
    $priorTermPlaceholders = implode(',', array_fill(0, count($priorTerms), '?'));
    $priorStmt = $pdo->prepare("SELECT student_id, term, transmuted_grade FROM term_grades
        WHERE subject_id = ? AND term IN ($priorTermPlaceholders) AND student_id IN ($studentPlaceholders)");
    $priorStmt->execute(array_merge([$assignment['subject_id']], $priorTerms, $studentIds));
    foreach ($priorStmt->fetchAll() as $row) {
        $priorGrades[(int) $row['term']][(int) $row['student_id']] = $row['transmuted_grade'];
    }
}

// Final grade for this subject — average of Terms 1-3 — once Term 3 is being viewed.
$finalGrades = [];
if ($term === 3) {
    foreach ($students as $student) {
        $vals = [];
        for ($t = 1; $t <= 2; $t++) {
            $g = $priorGrades[$t][$student['id']] ?? null;
            if ($g === null) {
                $vals = null;
                break;
            }
            $vals[] = (float) $g;
        }
        if ($vals !== null) {
            $gradeStmt->execute([$student['id'], $assignment['subject_id'], 3]);
            $t3 = $gradeStmt->fetch();
            if ($t3 && $t3['transmuted_grade'] !== null) {
                $vals[] = (float) $t3['transmuted_grade'];
                $finalGrades[$student['id']] = round(array_sum($vals) / 3, 2);
            }
        }
    }
}

// Data for the client-side live preview in assets/js/app.js (initGradePreview) — mirrors
// includes/gradeCalc.php's formula so typed-but-unsaved scores preview correctly; PHP
// remains the source of truth once "Save Scores" is submitted.
$weightsStmt = $pdo->prepare('SELECT wp.written_work_pct, wp.performance_task_pct, wp.examination_pct
    FROM subjects s JOIN grade_weight_profiles wp ON wp.id = s.weight_profile_id WHERE s.id = ?');
$weightsStmt->execute([$assignment['subject_id']]);
$weights = $weightsStmt->fetch();
// PDO returns DECIMAL columns as strings — cast to float so json_encode() below produces
// JSON numbers, not strings (app.js's initGradePreview calls .toFixed() on these).
$transmutationTable = array_map(
    fn($row) => ['min' => (float) $row['min'], 'max' => (float) $row['max'], 'transmuted' => (float) $row['transmuted']],
    $pdo->query('SELECT min_initial AS `min`, max_initial AS `max`, transmuted FROM transmutation_table')->fetchAll()
);

$yearStmt = $pdo->prepare('SELECT year_label FROM school_years WHERE id = ?');
$yearStmt->execute([$assignment['school_year_id']]);
$yearLabel = $yearStmt->fetchColumn() ?: '';

render_header($assignment['grade_level'] . ' - ' . $assignment['section_name'] . ' · ' . $assignment['subject_name']);
?>
<div class="flex items-center justify-between mb-6">
  <div class="flex items-center gap-3">
    <span class="text-sm text-slate-500 dark:text-slate-400">Term <?= $term ?></span>
    <?= status_badge($submission['status'] ?? 'not_started') ?>
  </div>
  <div class="flex items-center gap-3">
    <button id="download-pdf" type="button" class="px-3 py-1.5 rounded-lg text-sm bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 flex items-center gap-1.5"><?= icon_svg('download', 'w-4 h-4') ?> Download PDF</button>
    <form method="get" class="flex gap-1">
      <input type="hidden" name="sst_id" value="<?= $sstId ?>">
      <?php for ($t = 1; $t <= 3; $t++): ?>
        <button type="submit" name="term" value="<?= $t ?>" class="px-3 py-1.5 rounded-lg text-sm <?= $t === $term ? 'bg-accent-600 text-white' : 'bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700' ?>">Term <?= $t ?></button>
      <?php endfor; ?>
    </form>
  </div>
</div>

<?php if ($submission && $submission['status'] === 'returned_for_revision' && $submission['revision_comment']): ?>
<div class="mb-6 px-4 py-3 rounded-lg text-sm bg-rose-50 dark:bg-rose-900/30 text-rose-700 dark:text-rose-300 border border-rose-200 dark:border-rose-800">
  <strong>Returned for revision:</strong> <?= h($submission['revision_comment']) ?>
</div>
<?php endif; ?>

<?php if (!$editable): ?>
<div class="mb-6 px-4 py-3 rounded-lg text-sm bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700">
  This term is <?= h(STATUS_LABELS[$submission['status']] ?? $submission['status']) ?> and can no longer be edited.
</div>
<?php endif; ?>

<?php if ($submission && $submission['status'] === 'published'): ?>
  <?php if ($editRequest && $editRequest['status'] === 'pending'): ?>
  <div class="mb-6 px-4 py-3 rounded-lg text-sm bg-amber-50 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 border border-amber-200 dark:border-amber-800">
    <strong>Edit request pending Head Teacher approval.</strong>
    <div class="mt-1 text-amber-600 dark:text-amber-400">Reason: <?= h($editRequest['reason']) ?></div>
  </div>
  <?php else: ?>
    <?php if ($editRequest && $editRequest['status'] === 'rejected'): ?>
    <div class="mb-4 px-4 py-3 rounded-lg text-sm bg-rose-50 dark:bg-rose-900/30 text-rose-700 dark:text-rose-300 border border-rose-200 dark:border-rose-800">
      <strong>Edit request rejected<?= $editRequest['reviewed_by_name'] ? ' by ' . h($editRequest['reviewed_by_name']) : '' ?>.</strong>
      <?php if ($editRequest['review_comment']): ?><div class="mt-1">Reason: <?= h($editRequest['review_comment']) ?></div><?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm p-6 mb-6 max-w-lg">
      <h2 class="text-sm font-semibold text-slate-600 dark:text-slate-300 mb-1">Spot an error?</h2>
      <p class="text-xs text-slate-400 dark:text-slate-500 mb-3">Published grades are locked. Request an edit and explain why — the Head Teacher who supervises this subject must approve before you can make changes.</p>
      <form method="post" action="<?= h(url('/teacher/request_edit.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="sst_id" value="<?= $sstId ?>">
        <input type="hidden" name="term" value="<?= $term ?>">
        <textarea name="reason" required placeholder="Explain what needs to be corrected and why…" class="w-full mb-3 px-3 py-2 border border-slate-300 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100 rounded-lg text-sm" rows="2"></textarea>
        <button type="submit" class="bg-accent-600 hover:bg-accent-700 text-white font-medium px-4 py-2 rounded-lg text-sm">Request Edit</button>
      </form>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php if ($editable): ?>
<div id="paste-tip-banner" class="mb-4 px-4 py-3 rounded-lg text-sm bg-sky-50 dark:bg-sky-900/30 text-sky-700 dark:text-sky-300 border border-sky-200 dark:border-sky-800 flex items-start gap-3 hidden">
  <div class="flex-1">Tip: click a score cell, then paste a block of cells copied straight from Excel — it'll fill across students and items starting from that cell. Column names and highest scores are editable too (click directly on them).</div>
  <button type="button" id="paste-tip-dismiss" aria-label="Dismiss" class="flex-shrink-0 text-sky-400 dark:text-sky-500 hover:text-sky-600 dark:hover:text-sky-300 leading-none text-lg">&times;</button>
</div>
<div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm p-6 mb-6">
  <h2 class="text-sm font-semibold text-slate-600 dark:text-slate-300 mb-4">Add Assessment Item</h2>
  <form method="post" class="flex flex-wrap items-end gap-3">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_item">
    <input type="hidden" name="sst_id" value="<?= $sstId ?>">
    <input type="hidden" name="term" value="<?= $term ?>">
    <div>
      <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Component</label>
      <select name="component_type" required class="px-3 py-2 border border-slate-300 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100 rounded-lg">
        <option value="WW">Written Work</option>
        <option value="PT">Performance Task</option>
      </select>
      <p class="text-[10px] text-slate-400 dark:text-slate-500 mt-1">Examinations is fixed to Summative Test 1, Summative Test 2 &amp; Term Exam.</p>
    </div>
    <div>
      <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Item name</label>
      <input type="text" name="item_name" required placeholder="Quiz 1" class="px-3 py-2 border border-slate-300 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100 rounded-lg">
    </div>
    <div>
      <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Highest score</label>
      <input type="number" step="0.01" min="0.01" name="highest_possible_score" required class="w-28 px-3 py-2 border border-slate-300 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100 rounded-lg">
    </div>
    <button type="submit" class="bg-accent-600 hover:bg-accent-700 text-white font-medium px-4 py-2 rounded-lg text-sm">Add Item</button>
  </form>
</div>
<?php endif; ?>

<?php if ($editable): ?>
<?php foreach ($items as $item): ?>
<form method="post" id="delete-item-<?= (int) $item['id'] ?>" data-confirm="Remove this item and its scores?">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="delete_item">
  <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
  <input type="hidden" name="sst_id" value="<?= $sstId ?>">
  <input type="hidden" name="term" value="<?= $term ?>">
</form>
<?php endforeach; ?>
<?php endif; ?>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_scores">
  <input type="hidden" name="sst_id" value="<?= $sstId ?>">
  <input type="hidden" name="term" value="<?= $term ?>">
  <?php // A bounded-height pane that scrolls internally on both axes (rather than the whole
  // page scrolling past it) — this is what makes the sticky thead below actually stick:
  // position:sticky only sticks relative to its nearest ancestor that truly scrolls, and an
  // overflow-x-auto div whose height just grows with its content never scrolls itself (the
  // page does), so anything sticky inside it would just scroll away with the page instead of
  // staying put. Capping the height here makes this div the real scrolling viewport for the
  // grid, so the header row and the student's frozen name column stay pinned exactly like an
  // Excel split view while the roster scrolls underneath them.
  //
  // The rounded corners live on this OUTER wrapper, not on the scrolling div itself — a
  // rounded-corner element that is also the scroll container tends to let a frame of the
  // content scrolling past underneath smear through the corner during compositing (a
  // Chromium seam bug when position:sticky, overflow:auto and border-radius all land on the
  // same element). Splitting "clip to rounded corners" (outer, overflow-hidden, no scrolling
  // of its own) from "scroll" (inner, plain rectangle) avoids that seam entirely. ?>
  <?php // Wraps the letterhead (hidden until a PDF is actually being generated) and the grid
  // together so "Download PDF" below can capture both in one shot. ?>
  <div id="pdf-capture-root">
  <div id="pdf-letterhead" class="hidden text-center leading-tight mb-4 text-slate-800">
    <div>Republic of the Philippines</div>
    <div>Department of Education</div>
    <div>Region IV-A CALABARZON</div>
    <div>Division of Binan City</div>
    <div class="font-semibold">JACOBO Z. GONZALES MEMORIAL NATIONAL HIGH SCHOOL</div>
    <div class="font-semibold mt-1">CLASS RECORD — <?= h(strtoupper($assignment['subject_name'])) ?></div>
    <div><?= h($assignment['grade_level'] . ' - ' . $assignment['section_name']) ?> · Term <?= $term ?> · <?= h($yearLabel) ?></div>
    <div><?= h(current_user()['full_name']) ?></div>
  </div>
  <div id="pdf-clip-wrap" class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm overflow-hidden mb-4">
  <div id="grid-scroll-bottom" class="overflow-auto max-h-[70vh]">
    <table class="text-sm min-w-full">
      <?php
        // Distinct color band per component group (#7) — deliberately different from the
        // rose/emerald/amber/sky/accent colors already used elsewhere for pass-fail/status
        // meanings, so a column-group color is never mistaken for a status signal.
        // Fully opaque in both modes — these bands are now sticky headers sitting over
        // scrolling content, so a translucent background (the original dark:bg-violet-900/40
        // etc.) would let the scrolled-past row underneath show through as a "ghost" row.
        $componentBandClasses = [
            'WW' => 'bg-violet-100 dark:bg-violet-900 text-violet-700 dark:text-violet-300',
            'PT' => 'bg-teal-100 dark:bg-teal-900 text-teal-700 dark:text-teal-300',
            'EX' => 'bg-orange-100 dark:bg-orange-900 text-orange-700 dark:text-orange-300',
        ];
        // Row1 sticks to the very top of the scrolling pane (#grid-scroll-bottom, top-0);
        // row2 stacks right below it (top-0 + row1's own h-8 = top-8) — #6's sticky header,
        // combined with #7's grouped bands.
        $headSticky = 'sticky z-20 bg-slate-50 dark:bg-slate-800';
      ?>
      <thead class="text-slate-500 dark:text-slate-400 text-xs uppercase">
        <tr>
          <th rowspan="2" class="text-left px-4 py-3 sticky left-0 top-0 z-30 bg-slate-50 dark:bg-slate-800">Student</th>
          <?php foreach (['WW', 'PT', 'EX'] as $type): ?>
            <?php if (!$itemsByType[$type]) continue; ?>
            <th colspan="<?= count($itemsByType[$type]) ?>" class="h-8 text-center font-semibold normal-case <?= $headSticky ?> top-0 <?= $componentBandClasses[$type] ?>"><?= $componentLabels[$type] ?></th>
          <?php endforeach; ?>
          <?php for ($t = 1; $t < $term; $t++): ?>
            <th rowspan="2" class="text-center px-3 py-3 whitespace-nowrap <?= $headSticky ?> top-0">Term <?= $t ?></th>
          <?php endfor; ?>
          <th rowspan="2" class="text-center px-3 py-3 <?= $headSticky ?> top-0">Initial Grade</th>
          <th rowspan="2" class="text-center px-3 py-3 <?= $headSticky ?> top-0">Transmuted Grade</th>
          <?php if ($term === 3): ?>
            <th rowspan="2" class="text-center px-3 py-3 whitespace-nowrap text-accent-600 dark:text-accent-400 <?= $headSticky ?> top-0">Final Grade</th>
          <?php endif; ?>
        </tr>
        <tr>
          <?php foreach (['WW', 'PT', 'EX'] as $type): ?>
            <?php foreach ($itemsByType[$type] as $item): ?>
              <th class="text-center px-2 py-3 whitespace-nowrap <?= $headSticky ?> top-8">
                <input type="text" name="item_name[<?= (int) $item['id'] ?>]" value="<?= h($item['item_name']) ?>"
                  <?= $editable ? '' : 'disabled' ?>
                  class="w-24 text-center text-xs font-semibold text-slate-600 dark:text-slate-300 normal-case border border-slate-200 dark:border-slate-600 rounded px-1 py-0.5 hover:border-accent-400 dark:hover:border-accent-500 focus:border-accent-500 outline-none bg-white dark:bg-slate-900 disabled:bg-transparent disabled:border-transparent">
                <div class="flex items-center justify-center gap-1 mt-1">
                  <span class="text-[10px] font-normal text-slate-400 dark:text-slate-500">/</span>
                  <input type="number" step="0.01" min="0.01" name="item_highest[<?= (int) $item['id'] ?>]" value="<?= h(rtrim(rtrim((string) $item['highest_possible_score'], '0'), '.')) ?>"
                    <?= $editable ? '' : 'disabled' ?>
                    class="w-10 text-center text-[10px] font-normal text-slate-500 dark:text-slate-400 border border-slate-200 dark:border-slate-600 rounded hover:border-accent-400 dark:hover:border-accent-500 focus:border-accent-500 outline-none bg-white dark:bg-slate-900 disabled:bg-transparent disabled:border-transparent">
                  <?php if ($editable && $type !== 'EX'): ?>
                  <button type="submit" form="delete-item-<?= (int) $item['id'] ?>" title="Remove this item" class="p-1 rounded hover:bg-rose-50 dark:hover:bg-rose-900/30 text-rose-400 dark:text-rose-500 hover:text-rose-600 dark:hover:text-rose-400">
                    <?= icon_svg('trash', 'w-3 h-3') ?>
                  </button>
                  <?php endif; ?>
                </div>
              </th>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
        <?php $lastSex = null; foreach ($students as $rowIndex => $student): ?>
        <?php if ($student['sex'] !== $lastSex): $lastSex = $student['sex']; ?>
        <tr>
          <td colspan="99" class="px-4 py-1.5 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide bg-slate-50 dark:bg-slate-800 sticky left-0 z-10"><?= $student['sex'] === 'M' ? 'Male' : 'Female' ?></td>
        </tr>
        <?php endif; ?>
        <?php
            $gradeStmt->execute([$student['id'], $assignment['subject_id'], $term]);
            $grade = $gradeStmt->fetch();
            if ($grade && $grade['transmuted_grade'] !== null && (float) $grade['transmuted_grade'] < 75) {
                $failingStudents[] = ['id' => (int) $student['id'], 'full_name' => $student['full_name'], 'grade' => $grade['transmuted_grade']];
            }
        ?>
        <tr>
          <td class="px-4 py-2 font-medium whitespace-nowrap sticky left-0 z-10 bg-white dark:bg-slate-800 dark:text-slate-100"><?= h($student['full_name']) ?></td>
          <?php foreach (['WW', 'PT', 'EX'] as $type): ?>
            <?php foreach ($itemsByType[$type] as $item): ?>
              <td class="px-3 py-2 text-center">
                <?php
                  $rawScore = $scoreLookup[$item['id']][$student['id']] ?? null;
                  // Empty vs filled gets a visibly different look (#3) — dashed border + faint
                  // tint for "nothing here yet" vs a solid border for "has a value" — so a
                  // gap is visible at a glance while scanning, without looking like an error
                  // (a blank cell is completely normal mid-term).
                  $cellStateClass = $rawScore !== null
                      ? 'border-solid border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900'
                      : 'border-dashed border-slate-300 dark:border-slate-600 bg-slate-50 dark:bg-slate-900/40';
                ?>
                <input type="number" step="1" min="0" max="<?= h($item['highest_possible_score']) ?>"
                  name="scores[<?= (int) $item['id'] ?>][<?= (int) $student['id'] ?>]"
                  value="<?= $rawScore !== null ? (int) round((float) $rawScore) : '' ?>"
                  data-row="<?= $rowIndex ?>" data-col="<?= $itemColumns[$item['id']] ?>" data-item-id="<?= (int) $item['id'] ?>"
                  <?= $editable ? '' : 'disabled' ?>
                  class="js-grade-cell w-16 px-2 py-1 border <?= $cellStateClass ?> dark:text-slate-100 rounded text-center disabled:bg-slate-50 dark:disabled:bg-slate-800 disabled:text-slate-400 dark:disabled:text-slate-500 focus:border-accent-500 focus:ring-1 focus:ring-accent-500">
              </td>
            <?php endforeach; ?>
          <?php endforeach; ?>
          <?php for ($t = 1; $t < $term; $t++): $pg = $priorGrades[$t][$student['id']] ?? null; ?>
            <td class="px-3 py-2 text-center <?= $pg !== null ? grade_display_class((float) $pg) : 'text-slate-500 dark:text-slate-400' ?>"><?= $pg !== null ? h($pg) : '—' ?></td>
          <?php endfor; ?>
          <td class="px-3 py-2 text-center font-medium dark:text-slate-200" data-preview-initial="<?= (int) $student['id'] ?>"><?= $grade && $grade['initial_grade'] !== null ? h($grade['initial_grade']) : '—' ?></td>
          <td class="px-3 py-2 text-center font-semibold <?= $grade && $grade['transmuted_grade'] !== null ? (grade_display_class((float) $grade['transmuted_grade']) ?: 'text-accent-700 dark:text-accent-400') : 'text-accent-700 dark:text-accent-400' ?>" data-preview-transmuted="<?= (int) $student['id'] ?>"><?= $grade && $grade['transmuted_grade'] !== null ? h($grade['transmuted_grade']) : '—' ?></td>
          <?php if ($term === 3): $fg = $finalGrades[$student['id']] ?? null; ?>
            <td class="px-3 py-2 text-center font-semibold <?= $fg !== null ? (grade_display_class((float) $fg) ?: 'text-accent-700 dark:text-accent-400') : 'text-accent-700 dark:text-accent-400' ?>"><?= $fg !== null ? h($fg) : '—' ?></td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <?php if (!$students): ?>
        <tr><td colspan="99" class="px-4 py-6 text-center text-slate-400 dark:text-slate-500">No students in this section yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  </div>
  <?php // A plain, input-free mirror of the grid above, shown only while a PDF is being
  // generated (the live grid is hidden in its place for that moment) — html2canvas doesn't
  // reliably render values of <input> elements sitting inside position:sticky cells (the live
  // grid's header row and frozen name column both are), so capturing the interactive grid
  // directly left item names/highest-scores blank in the exported PDF. Plain text sidesteps
  // that entirely and reads better on a printed hard copy anyway — reflects the last SAVED
  // scores, not unsaved in-progress edits. ?>
  <div id="pdf-print-table" class="hidden bg-white border border-slate-200 rounded-xl shadow-sm mb-4">
    <table class="text-sm min-w-full">
      <thead class="text-slate-500 text-xs uppercase">
        <tr>
          <th rowspan="2" class="text-left px-4 py-3">Student</th>
          <?php foreach (['WW', 'PT', 'EX'] as $type): ?>
            <?php if (!$itemsByType[$type]) continue; ?>
            <th colspan="<?= count($itemsByType[$type]) ?>" class="text-center px-3 py-2 font-semibold normal-case <?= $componentBandClasses[$type] ?>"><?= $componentLabels[$type] ?></th>
          <?php endforeach; ?>
          <?php for ($t = 1; $t < $term; $t++): ?>
            <th rowspan="2" class="text-center px-3 py-3 whitespace-nowrap">Term <?= $t ?></th>
          <?php endfor; ?>
          <th rowspan="2" class="text-center px-3 py-3">Initial Grade</th>
          <th rowspan="2" class="text-center px-3 py-3">Transmuted Grade</th>
          <?php if ($term === 3): ?>
            <th rowspan="2" class="text-center px-3 py-3 whitespace-nowrap text-accent-600">Final Grade</th>
          <?php endif; ?>
        </tr>
        <tr>
          <?php foreach (['WW', 'PT', 'EX'] as $type): ?>
            <?php foreach ($itemsByType[$type] as $item): ?>
              <th class="text-center px-2 py-2 whitespace-nowrap font-semibold normal-case"><?= h($item['item_name']) ?> (<?= h(rtrim(rtrim((string) $item['highest_possible_score'], '0'), '.')) ?>)</th>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        <?php $lastSex = null; foreach ($students as $student): ?>
        <?php if ($student['sex'] !== $lastSex): $lastSex = $student['sex']; ?>
        <tr><td colspan="99" class="px-4 py-1.5 text-xs font-semibold text-slate-500 uppercase tracking-wide bg-slate-50"><?= $student['sex'] === 'M' ? 'Male' : 'Female' ?></td></tr>
        <?php endif; ?>
        <?php $gradeStmt->execute([$student['id'], $assignment['subject_id'], $term]); $grade = $gradeStmt->fetch(); ?>
        <tr>
          <td class="px-4 py-2 font-medium whitespace-nowrap"><?= h($student['full_name']) ?></td>
          <?php foreach (['WW', 'PT', 'EX'] as $type): ?>
            <?php foreach ($itemsByType[$type] as $item): $rawScore = $scoreLookup[$item['id']][$student['id']] ?? null; ?>
              <td class="px-3 py-2 text-center"><?= $rawScore !== null ? (int) round((float) $rawScore) : '—' ?></td>
            <?php endforeach; ?>
          <?php endforeach; ?>
          <?php for ($t = 1; $t < $term; $t++): $pg = $priorGrades[$t][$student['id']] ?? null; ?>
            <td class="px-3 py-2 text-center <?= $pg !== null ? grade_display_class((float) $pg) : 'text-slate-500' ?>"><?= $pg !== null ? h($pg) : '—' ?></td>
          <?php endfor; ?>
          <td class="px-3 py-2 text-center font-medium"><?= $grade && $grade['initial_grade'] !== null ? h($grade['initial_grade']) : '—' ?></td>
          <td class="px-3 py-2 text-center font-semibold <?= $grade && $grade['transmuted_grade'] !== null ? (grade_display_class((float) $grade['transmuted_grade']) ?: 'text-accent-700') : 'text-accent-700' ?>"><?= $grade && $grade['transmuted_grade'] !== null ? h($grade['transmuted_grade']) : '—' ?></td>
          <?php if ($term === 3): $fg = $finalGrades[$student['id']] ?? null; ?>
            <td class="px-3 py-2 text-center font-semibold <?= $fg !== null ? (grade_display_class((float) $fg) ?: 'text-accent-700') : 'text-accent-700' ?>"><?= $fg !== null ? h($fg) : '—' ?></td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <?php if (!$students): ?>
        <tr><td colspan="99" class="px-4 py-6 text-center text-slate-400">No students in this section yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  </div>
  <?php if ($editable && $failingStudents): ?>
  <div class="bg-white dark:bg-slate-800 border border-amber-200 dark:border-amber-800 rounded-xl shadow-sm p-5 mb-4">
    <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-1">Reasons for failing grades</h3>
    <p class="text-xs text-slate-400 dark:text-slate-500 mb-3">Required before this term can be submitted for review — pick whichever best explains it.</p>
    <div class="space-y-3">
      <?php foreach ($failingStudents as $fs): $existing = $existingReasons[$fs['id']] ?? null; ?>
      <div class="flex flex-wrap items-start gap-3 pb-3 border-b border-slate-100 dark:border-slate-700 last:border-0 last:pb-0">
        <div class="w-48 flex-shrink-0">
          <div class="text-sm font-medium text-slate-700 dark:text-slate-200"><?= h($fs['full_name']) ?></div>
          <div class="text-xs text-rose-600 dark:text-rose-400 font-semibold"><?= h($fs['grade']) ?></div>
        </div>
        <div class="flex-1 min-w-[220px]">
          <select name="fail_reason[<?= $fs['id'] ?>]" class="js-fail-reason-select w-full px-3 py-2 border border-slate-300 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100 rounded-lg text-sm" data-student-id="<?= $fs['id'] ?>">
            <option value="">— Select a reason —</option>
            <?php foreach (FAIL_REASON_LABELS as $key => $label): ?>
            <option value="<?= h($key) ?>" <?= ($existing['reason'] ?? '') === $key ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
          <textarea name="fail_reason_other[<?= $fs['id'] ?>]" data-other-for="<?= $fs['id'] ?>" placeholder="Specify…" rows="2"
            class="js-fail-reason-other mt-2 w-full px-3 py-2 border border-slate-300 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100 rounded-lg text-sm <?= ($existing['reason'] ?? '') === 'other' ? '' : 'hidden' ?>"><?= h($existing['reason_other'] ?? '') ?></textarea>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($editable && $items && $students): ?>
  <div class="flex gap-3">
    <?php /* #5: Save Scores (frequent, reversible) is now the visually lighter/secondary
       action; Submit (locks the term) stays solid and gets an icon to reinforce that it's the
       one that actually finalizes something — the two used to look like equally-weighted
       options despite very different consequences. */ ?>
    <button type="submit" class="bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 font-medium px-5 py-2.5 rounded-lg text-sm">Save Scores</button>
    <button type="submit" name="post_action" value="submit_for_review" data-confirm="Submit this term for Head Teacher review? You won't be able to edit scores until it's returned or published." class="inline-flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white font-medium px-5 py-2.5 rounded-lg text-sm"><?= icon_svg('send', 'w-3.5 h-3.5') ?> Save &amp; Submit for Review</button>
  </div>
  <?php endif; ?>
</form>

<?php if ($editable): ?>
<script>
window.addEventListener('DOMContentLoaded', function () {
  initGradePreview({
    weights: { ww: <?= (int) $weights['written_work_pct'] ?>, pt: <?= (int) $weights['performance_task_pct'] ?>, ex: <?= (int) $weights['examination_pct'] ?> },
    transmutation: <?= json_encode($transmutationTable) ?>,
    items: <?= json_encode(array_map(function ($i) use ($itemsByType) {
        $exIndex = null;
        if ($i['component_type'] === 'EX') {
            $exIndex = array_search($i['id'], array_column($itemsByType['EX'], 'id'), true);
        }
        return ['id' => (int) $i['id'], 'type' => $i['component_type'], 'highest' => (float) $i['highest_possible_score'], 'exIndex' => $exIndex];
    }, $items)) ?>,
    students: <?= json_encode(array_map(fn($s) => (int) $s['id'], $students)) ?>
  });
  initPasteGrid();
  initGridArrowNav();
  initFailReasonToggle();
  initDismissibleTip();
});
</script>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script>
(function () {
  var btn = document.getElementById('download-pdf');
  if (!btn) return;
  var fileName = <?= json_encode(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $assignment['grade_level'] . '-' . $assignment['section_name'] . '-' . $assignment['subject_name'])) . '-term' . $term . '-class-record.pdf') ?>;

  btn.addEventListener('click', async function () {
    var originalLabel = btn.innerHTML;
    btn.disabled = true;
    btn.textContent = 'Generating PDF…';

    var letterhead = document.getElementById('pdf-letterhead');
    var clipWrap = document.getElementById('pdf-clip-wrap');
    var printTable = document.getElementById('pdf-print-table');
    var root = document.getElementById('pdf-capture-root');
    var wasHidden = letterhead.classList.contains('hidden');

    try {
      // Swap the live, interactive grid for the plain print-only mirror table for the
      // capture — see the comment above #pdf-print-table for why (html2canvas doesn't
      // reliably render <input> values inside position:sticky cells) — and reveal the
      // normally-hidden letterhead.
      letterhead.classList.remove('hidden');
      clipWrap.classList.add('hidden');
      printTable.classList.remove('hidden');

      var SCALE = 2;
      // Row-boundary-aware page breaks — measured from the live DOM before capture — so a
      // page break never lands in the middle of a student's row on the printed document.
      var rowOffsetsCss = Array.prototype.map.call(printTable.querySelectorAll('tbody tr'), function (tr) {
        return tr.getBoundingClientRect().top - root.getBoundingClientRect().top;
      });

      // The page's <main> has overflow:hidden (keeps the sidebar layout from breaking on any
      // stray wide content), which silently clips html2canvas's capture to whatever width
      // happened to be visible — cutting off the right-hand columns on any class record wide
      // enough to need them. windowWidth forces html2canvas to render in its own off-screen
      // clone sized to the print table's true content width, unaffected by that clipping.
      var neededWidth = Math.ceil(root.scrollWidth) + 40;
      var canvas = await html2canvas(root, { scale: SCALE, backgroundColor: '#ffffff', windowWidth: neededWidth, width: neededWidth });

      var pageWidthMm = 277, pageHeightMm = 190; // A4 landscape minus 10mm margins
      var pxPerMm = canvas.width / pageWidthMm;
      var pageHeightPx = pageHeightMm * pxPerMm;

      var breaks = [0];
      var budgetStart = 0;
      rowOffsetsCss.forEach(function (cssTop) {
        var px = cssTop * SCALE;
        if (px - budgetStart > pageHeightPx) {
          breaks.push(px);
          budgetStart = px;
        }
      });
      breaks.push(canvas.height);

      var pdf = new window.jspdf.jsPDF({ unit: 'mm', format: 'a4', orientation: 'landscape' });
      for (var i = 0; i < breaks.length - 1; i++) {
        var sliceTop = breaks[i], sliceH = breaks[i + 1] - breaks[i];
        if (sliceH <= 0) continue;
        var pageCanvas = document.createElement('canvas');
        pageCanvas.width = canvas.width;
        pageCanvas.height = sliceH;
        pageCanvas.getContext('2d').drawImage(canvas, 0, sliceTop, canvas.width, sliceH, 0, 0, canvas.width, sliceH);
        if (i > 0) pdf.addPage();
        pdf.addImage(pageCanvas.toDataURL('image/jpeg', 0.95), 'JPEG', 10, 10, pageWidthMm, sliceH / pxPerMm);
      }
      pdf.save(fileName);
    } catch (err) {
      alert('Could not generate the PDF: ' + err.message);
    } finally {
      clipWrap.classList.remove('hidden');
      printTable.classList.add('hidden');
      if (wasHidden) letterhead.classList.add('hidden');
      btn.disabled = false;
      btn.innerHTML = originalLabel;
    }
  });
})();
</script>
<?php render_footer(); ?>
