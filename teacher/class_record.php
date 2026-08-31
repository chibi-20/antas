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
        if ($rejected) {
            $nameStmt = $pdo->prepare('SELECT full_name FROM students WHERE id = ?');
            $details = [];
            foreach ($rejected as $r) {
                $nameStmt->execute([$r['student_id']]);
                $studentName = $nameStmt->fetchColumn() ?: 'that student';
                $details[] = "$studentName — {$r['item_name']}: {$r['entered']} (highest is {$r['highest']})";
            }
            flash_set('error', 'Everything else saved, but ' . count($rejected) . ' score(s) were out of range and NOT saved: ' . implode('; ', $details));
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

// Male-then-Female, alphabetical within each — the standard class record roster order.
// Restricted to this assignment's sex_scope (e.g. a boys-only TLE assignment never shows
// girls, even though they share a section_id).
$sexScope = strtoupper(trim((string) ($assignment['sex_scope'] ?? 'ALL')));

if ($sexScope === 'ALL') {
    $studentsStmt = $pdo->prepare("
        SELECT *
        FROM students
        WHERE section_id = ?
          AND is_active = 1
        ORDER BY FIELD(sex, 'M', 'F'), full_name
    ");

    $studentsStmt->execute([
        $assignment['section_id'],
    ]);
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

render_header($assignment['grade_level'] . ' - ' . $assignment['section_name'] . ' · ' . $assignment['subject_name']);
?>
<div class="flex items-center justify-between mb-6">
  <div class="flex items-center gap-3">
    <span class="text-sm text-slate-500">Term <?= $term ?></span>
    <?= status_badge($submission['status'] ?? 'not_started') ?>
  </div>
  <form method="get" class="flex gap-1">
    <input type="hidden" name="sst_id" value="<?= $sstId ?>">
    <?php for ($t = 1; $t <= 3; $t++): ?>
      <button type="submit" name="term" value="<?= $t ?>" class="px-3 py-1.5 rounded-lg text-sm <?= $t === $term ? 'bg-accent-600 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">Term <?= $t ?></button>
    <?php endfor; ?>
  </form>
</div>

<?php if ($submission && $submission['status'] === 'returned_for_revision' && $submission['revision_comment']): ?>
<div class="mb-6 px-4 py-3 rounded-lg text-sm bg-rose-50 text-rose-700 border border-rose-200">
  <strong>Returned for revision:</strong> <?= h($submission['revision_comment']) ?>
</div>
<?php endif; ?>

<?php if (!$editable): ?>
<div class="mb-6 px-4 py-3 rounded-lg text-sm bg-slate-100 text-slate-600 border border-slate-200">
  This term is <?= h(STATUS_LABELS[$submission['status']] ?? $submission['status']) ?> and can no longer be edited.
</div>
<?php endif; ?>

<?php if ($submission && $submission['status'] === 'published'): ?>
  <?php if ($editRequest && $editRequest['status'] === 'pending'): ?>
  <div class="mb-6 px-4 py-3 rounded-lg text-sm bg-amber-50 text-amber-700 border border-amber-200">
    <strong>Edit request pending Head Teacher approval.</strong>
    <div class="mt-1 text-amber-600">Reason: <?= h($editRequest['reason']) ?></div>
  </div>
  <?php else: ?>
    <?php if ($editRequest && $editRequest['status'] === 'rejected'): ?>
    <div class="mb-4 px-4 py-3 rounded-lg text-sm bg-rose-50 text-rose-700 border border-rose-200">
      <strong>Edit request rejected<?= $editRequest['reviewed_by_name'] ? ' by ' . h($editRequest['reviewed_by_name']) : '' ?>.</strong>
      <?php if ($editRequest['review_comment']): ?><div class="mt-1">Reason: <?= h($editRequest['review_comment']) ?></div><?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6 max-w-lg">
      <h2 class="text-sm font-semibold text-slate-600 mb-1">Spot an error?</h2>
      <p class="text-xs text-slate-400 mb-3">Published grades are locked. Request an edit and explain why — the Head Teacher who supervises this subject must approve before you can make changes.</p>
      <form method="post" action="<?= h(url('/teacher/request_edit.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="sst_id" value="<?= $sstId ?>">
        <input type="hidden" name="term" value="<?= $term ?>">
        <textarea name="reason" required placeholder="Explain what needs to be corrected and why…" class="w-full mb-3 px-3 py-2 border border-slate-300 rounded-lg text-sm" rows="2"></textarea>
        <button type="submit" class="bg-accent-600 hover:bg-accent-700 text-white font-medium px-4 py-2 rounded-lg text-sm">Request Edit</button>
      </form>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php if ($editable): ?>
<div class="mb-4 px-4 py-3 rounded-lg text-sm bg-sky-50 text-sky-700 border border-sky-200">
  Tip: click a score cell, then paste a block of cells copied straight from Excel — it'll fill across students and items starting from that cell. Column names and highest scores are editable too (click directly on them).
</div>
<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6">
  <h2 class="text-sm font-semibold text-slate-600 mb-4">Add Assessment Item</h2>
  <form method="post" class="flex flex-wrap items-end gap-3">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_item">
    <input type="hidden" name="sst_id" value="<?= $sstId ?>">
    <input type="hidden" name="term" value="<?= $term ?>">
    <div>
      <label class="block text-xs font-medium text-slate-500 mb-1">Component</label>
      <select name="component_type" required class="px-3 py-2 border border-slate-300 rounded-lg">
        <option value="WW">Written Work</option>
        <option value="PT">Performance Task</option>
      </select>
      <p class="text-[10px] text-slate-400 mt-1">Examinations is fixed to Summative Test 1, Summative Test 2 &amp; Term Exam.</p>
    </div>
    <div>
      <label class="block text-xs font-medium text-slate-500 mb-1">Item name</label>
      <input type="text" name="item_name" required placeholder="Quiz 1" class="px-3 py-2 border border-slate-300 rounded-lg">
    </div>
    <div>
      <label class="block text-xs font-medium text-slate-500 mb-1">Highest score</label>
      <input type="number" step="0.01" min="0.01" name="highest_possible_score" required class="w-28 px-3 py-2 border border-slate-300 rounded-lg">
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
  <div id="grid-scroll-top" class="overflow-x-auto mb-1"><div id="grid-scroll-spacer" style="height:1px;"></div></div>
  <div id="grid-scroll-bottom" class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-x-auto mb-4">
    <table class="text-sm min-w-full">
      <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
        <tr>
          <th class="text-left px-4 py-3 sticky left-0 bg-slate-50">Student</th>
          <?php foreach (['WW', 'PT', 'EX'] as $type): ?>
            <?php foreach ($itemsByType[$type] as $item): ?>
              <th class="text-center px-2 py-3 whitespace-nowrap">
                <input type="text" name="item_name[<?= (int) $item['id'] ?>]" value="<?= h($item['item_name']) ?>"
                  <?= $editable ? '' : 'disabled' ?>
                  class="w-24 text-center text-xs font-semibold text-slate-600 normal-case border-b border-dashed border-slate-300 focus:border-accent-500 outline-none bg-transparent disabled:border-transparent">
                <div class="flex items-center justify-center gap-1 mt-0.5">
                  <span class="text-[10px] font-normal text-slate-400">/</span>
                  <input type="number" step="0.01" min="0.01" name="item_highest[<?= (int) $item['id'] ?>]" value="<?= h(rtrim(rtrim((string) $item['highest_possible_score'], '0'), '.')) ?>"
                    <?= $editable ? '' : 'disabled' ?>
                    class="w-10 text-center text-[10px] font-normal text-slate-400 border-b border-dashed border-slate-300 focus:border-accent-500 outline-none bg-transparent disabled:border-transparent">
                  <span class="text-[10px] font-normal text-slate-800"><?= $componentLabels[$type] ?></span>
                </div>
                <?php if ($editable && $type !== 'EX'): ?>
                <button type="submit" form="delete-item-<?= (int) $item['id'] ?>" class="text-rose-400 hover:text-rose-600 text-[10px]">remove</button>
                <?php endif; ?>
              </th>
            <?php endforeach; ?>
          <?php endforeach; ?>
          <?php for ($t = 1; $t < $term; $t++): ?>
            <th class="text-center px-3 py-3 whitespace-nowrap">Term <?= $t ?></th>
          <?php endfor; ?>
          <th class="text-center px-3 py-3">Initial Grade</th>
          <th class="text-center px-3 py-3">Transmuted Grade</th>
          <?php if ($term === 3): ?>
            <th class="text-center px-3 py-3 whitespace-nowrap text-accent-600">Final Grade</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        <?php $lastSex = null; foreach ($students as $rowIndex => $student): ?>
        <?php if ($student['sex'] !== $lastSex): $lastSex = $student['sex']; ?>
        <tr>
          <td colspan="99" class="px-4 py-1.5 text-xs font-semibold text-slate-500 uppercase tracking-wide bg-slate-50 sticky left-0"><?= $student['sex'] === 'M' ? 'Male' : 'Female' ?></td>
        </tr>
        <?php endif; ?>
        <?php
            $gradeStmt->execute([$student['id'], $assignment['subject_id'], $term]);
            $grade = $gradeStmt->fetch();
        ?>
        <tr>
          <td class="px-4 py-2 font-medium whitespace-nowrap sticky left-0 bg-white"><?= h($student['full_name']) ?></td>
          <?php foreach (['WW', 'PT', 'EX'] as $type): ?>
            <?php foreach ($itemsByType[$type] as $item): ?>
              <td class="px-3 py-2 text-center">
                <?php $rawScore = $scoreLookup[$item['id']][$student['id']] ?? null; ?>
                <input type="number" step="1" min="0" max="<?= h($item['highest_possible_score']) ?>"
                  name="scores[<?= (int) $item['id'] ?>][<?= (int) $student['id'] ?>]"
                  value="<?= $rawScore !== null ? (int) round((float) $rawScore) : '' ?>"
                  data-row="<?= $rowIndex ?>" data-col="<?= $itemColumns[$item['id']] ?>" data-item-id="<?= (int) $item['id'] ?>"
                  <?= $editable ? '' : 'disabled' ?>
                  class="js-grade-cell w-16 px-2 py-1 border border-slate-300 rounded text-center disabled:bg-slate-50 disabled:text-slate-400 focus:border-accent-500 focus:ring-1 focus:ring-accent-500">
              </td>
            <?php endforeach; ?>
          <?php endforeach; ?>
          <?php for ($t = 1; $t < $term; $t++): $pg = $priorGrades[$t][$student['id']] ?? null; ?>
            <td class="px-3 py-2 text-center <?= $pg !== null ? grade_display_class((float) $pg) : 'text-slate-500' ?>"><?= $pg !== null ? h($pg) : '—' ?></td>
          <?php endfor; ?>
          <td class="px-3 py-2 text-center font-medium" data-preview-initial="<?= (int) $student['id'] ?>"><?= $grade && $grade['initial_grade'] !== null ? h($grade['initial_grade']) : '—' ?></td>
          <td class="px-3 py-2 text-center font-semibold <?= $grade && $grade['transmuted_grade'] !== null ? (grade_display_class((float) $grade['transmuted_grade']) ?: 'text-accent-700') : 'text-accent-700' ?>" data-preview-transmuted="<?= (int) $student['id'] ?>"><?= $grade && $grade['transmuted_grade'] !== null ? h($grade['transmuted_grade']) : '—' ?></td>
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
  <?php if ($editable && $items && $students): ?>
  <button type="submit" class="bg-accent-600 hover:bg-accent-700 text-white font-medium px-5 py-2.5 rounded-lg text-sm">Save Scores</button>
  <?php endif; ?>
</form>

<?php if ($editable): ?>
<form method="post" action="<?= h(url('/teacher/submit.php')) ?>" class="mt-4" data-confirm="Submit this term for Head Teacher review? You won't be able to edit scores until it's returned or published.">
  <?= csrf_field() ?>
  <input type="hidden" name="sst_id" value="<?= $sstId ?>">
  <input type="hidden" name="term" value="<?= $term ?>">
  <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-medium px-5 py-2.5 rounded-lg text-sm">Submit for Review</button>
</form>
<?php endif; ?>

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
});
</script>
<?php endif; ?>
<script>
window.addEventListener('DOMContentLoaded', function () {
  initTopScrollbar('grid-scroll-top', 'grid-scroll-bottom', 'grid-scroll-spacer');
});
</script>
<?php render_footer(); ?>
