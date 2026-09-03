<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';

$user = require_role(['subject_teacher', 'admin']);
$pdo = db();

$sstId = (int) ($_GET['sst_id'] ?? $_POST['sst_id'] ?? 0);
$term = (int) ($_GET['term'] ?? $_POST['term'] ?? 1);
if ($term < 1 || $term > 3) {
    $term = 1;
}

$stmt = $pdo->prepare('SELECT sst.*, gl.name AS grade_level, sec.section_name, sub.subject_name, u.full_name AS teacher_name
    FROM section_subject_teachers sst
    JOIN sections sec ON sec.id = sst.section_id
    JOIN grade_levels gl ON gl.id = sec.grade_level_id
    JOIN subjects sub ON sub.id = sst.subject_id
    JOIN users u ON u.id = sst.teacher_id
    WHERE sst.id = ?');
$stmt->execute([$sstId]);
$assignment = $stmt->fetch();
if (!$assignment) {
    forbidden('Assignment not found.');
}
require_supervised_subject((int) $assignment['subject_id'], (int) $assignment['school_year_id']);
assert_covers_term($assignment, $term, '/headteacher/dashboard.php',
    "{$assignment['teacher_name']}'s {$assignment['subject_name']} assignment for {$assignment['grade_level']} - {$assignment['section_name']} doesn't cover Term $term.");

$statusStmt = $pdo->prepare('SELECT * FROM submission_status WHERE section_subject_teacher_id = ? AND term = ?');
$statusStmt->execute([$sstId, $term]);
$submission = $statusStmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'publish' || $action === 'return') {
        if (!$submission || $submission['status'] !== 'submitted_for_review') {
            flash_set('error', 'This term is not currently awaiting review.');
        } elseif ($action === 'publish') {
            $pdo->prepare("UPDATE submission_status SET status = 'published', reviewed_by = ?, reviewed_at = NOW() WHERE section_subject_teacher_id = ? AND term = ?")
                ->execute([$user['id'], $sstId, $term]);

            // If this publish is closing out an approved edit-request cycle, finalize its
            // history diff now: fill in each snapshotted student's new grade and mark the
            // request done, so it's a single clean before/after, not one row per save.
            $activeEdit = $pdo->prepare("SELECT id FROM grade_edit_requests
                WHERE section_subject_teacher_id = ? AND term = ? AND status = 'approved' AND finalized_at IS NULL
                ORDER BY reviewed_at DESC LIMIT 1");
            $activeEdit->execute([$sstId, $term]);
            if ($editRequestId = $activeEdit->fetchColumn()) {
                $newGradeStmt = $pdo->prepare('SELECT transmuted_grade FROM term_grades WHERE student_id = ? AND subject_id = ? AND term = ?');
                $historyRowsStmt = $pdo->prepare('SELECT id, student_id FROM grade_edit_history WHERE edit_request_id = ?');
                $historyRowsStmt->execute([$editRequestId]);
                $updateHistory = $pdo->prepare('UPDATE grade_edit_history SET new_transmuted_grade = ? WHERE id = ?');
                foreach ($historyRowsStmt->fetchAll() as $h) {
                    $newGradeStmt->execute([$h['student_id'], $assignment['subject_id'], $term]);
                    $new = $newGradeStmt->fetchColumn();
                    $updateHistory->execute([$new !== false ? $new : null, $h['id']]);
                }
                $pdo->prepare('UPDATE grade_edit_requests SET finalized_at = NOW() WHERE id = ?')->execute([$editRequestId]);
            }

            flash_set('success', 'Grades published.');
        } else {
            $comment = trim((string) ($_POST['revision_comment'] ?? ''));
            if ($comment === '') {
                flash_set('error', 'A comment is required when returning for revision.');
            } else {
                $pdo->prepare("UPDATE submission_status SET status = 'returned_for_revision', reviewed_by = ?, reviewed_at = NOW(), revision_comment = ? WHERE section_subject_teacher_id = ? AND term = ?")
                    ->execute([$user['id'], $comment, $sstId, $term]);
                flash_set('success', 'Returned to teacher for revision.');
            }
        }
    } elseif ($action === 'approve_edit' || $action === 'reject_edit') {
        $editRequestId = (int) ($_POST['edit_request_id'] ?? 0);
        $erStmt = $pdo->prepare("SELECT * FROM grade_edit_requests WHERE id = ? AND section_subject_teacher_id = ? AND term = ? AND status = 'pending'");
        $erStmt->execute([$editRequestId, $sstId, $term]);
        $er = $erStmt->fetch();

        if (!$er || !$submission || $submission['status'] !== 'published') {
            flash_set('error', 'No pending edit request found for this term.');
        } elseif ($action === 'approve_edit') {
            $pdo->prepare("UPDATE grade_edit_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")
                ->execute([$user['id'], $editRequestId]);
            // Reopens editing via the existing lock mechanism — class_record.php already
            // treats 'returned_for_revision' as editable, no changes needed there.
            $pdo->prepare("UPDATE submission_status SET status = 'returned_for_revision', reviewed_by = ?, reviewed_at = NOW(), revision_comment = ? WHERE section_subject_teacher_id = ? AND term = ?")
                ->execute([$user['id'], 'Edit request approved — ' . $er['reason'], $sstId, $term]);
            // Snapshot every student THIS assignment covers (respecting sex_scope — approving
            // the boys' teacher's request must never snapshot/diff the girls' grades) as the
            // "old" value for this request's history — "new" gets filled in once republished.
            // Split into two plain queries rather than "(? = 'ALL' OR sex = ?)" — that shape
            // (a literal-string comparison OR'd with a column comparison in one expression)
            // can throw "Illegal mix of collations" on some MySQL versions even when every
            // column's stored collation genuinely matches (see db/fix_sex_scope_collation.php).
            $sexScopeForSnapshot = strtoupper(trim((string) ($assignment['sex_scope'] ?? 'ALL')));
            if ($sexScopeForSnapshot === 'ALL') {
                $studentsStmt = $pdo->prepare('SELECT id FROM students WHERE section_id = ? AND is_active = 1');
                $studentsStmt->execute([$assignment['section_id']]);
            } elseif ($sexScopeForSnapshot === 'MIX') {
                $studentsStmt = $pdo->prepare('SELECT student_id AS id FROM sst_student_claims WHERE section_subject_teacher_id = ?');
                $studentsStmt->execute([$sstId]);
            } else {
                $studentsStmt = $pdo->prepare('SELECT id FROM students WHERE section_id = ? AND is_active = 1 AND sex = ?');
                $studentsStmt->execute([$assignment['section_id'], $sexScopeForSnapshot]);
            }
            $oldGradeStmt = $pdo->prepare('SELECT transmuted_grade FROM term_grades WHERE student_id = ? AND subject_id = ? AND term = ?');
            $insertHistory = $pdo->prepare('INSERT INTO grade_edit_history (edit_request_id, student_id, old_transmuted_grade) VALUES (?, ?, ?)');
            foreach ($studentsStmt->fetchAll(PDO::FETCH_COLUMN) as $studentId) {
                $oldGradeStmt->execute([$studentId, $assignment['subject_id'], $term]);
                $old = $oldGradeStmt->fetchColumn();
                $insertHistory->execute([$editRequestId, $studentId, $old !== false ? $old : null]);
            }
            flash_set('success', 'Edit request approved — term reopened for the teacher.');
        } else {
            $comment = trim((string) ($_POST['review_comment'] ?? ''));
            if ($comment === '') {
                flash_set('error', 'A comment is required when rejecting an edit request.');
            } else {
                $pdo->prepare("UPDATE grade_edit_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), review_comment = ? WHERE id = ?")
                    ->execute([$user['id'], $comment, $editRequestId]);
                flash_set('success', 'Edit request rejected.');
            }
        }
    }
    redirect("/headteacher/review.php?sst_id=$sstId&term=$term");
}

// Pending edit request for this assignment/term, if any — drives the approve/reject card
// shown below when this term is published.
$pendingEditRequest = null;
if ($submission && $submission['status'] === 'published') {
    $perStmt = $pdo->prepare("SELECT * FROM grade_edit_requests WHERE section_subject_teacher_id = ? AND term = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 1");
    $perStmt->execute([$sstId, $term]);
    $pendingEditRequest = $perStmt->fetch() ?: null;
}

$items = $pdo->prepare('SELECT * FROM assessment_items WHERE section_subject_teacher_id = ? AND term = ? ORDER BY component_type, sort_order, id');
$items->execute([$sstId, $term]);
$items = $items->fetchAll();
$itemsByType = ['WW' => [], 'PT' => [], 'EX' => []];
foreach ($items as $item) {
    $itemsByType[$item['component_type']][] = $item;
}

// Male-then-Female, alphabetical within each — the standard class record roster order.
// Restricted to this assignment's sex_scope, same as teacher/class_record.php. Split into two
// plain queries rather than "(? = 'ALL' OR sex = ?)" — see the matching comment above.
$sexScope = strtoupper(trim((string) ($assignment['sex_scope'] ?? 'ALL')));
if ($sexScope === 'ALL') {
    $students = $pdo->prepare("SELECT * FROM students WHERE section_id = ? AND is_active = 1 ORDER BY FIELD(sex, 'M', 'F'), full_name");
    $students->execute([$assignment['section_id']]);
} elseif ($sexScope === 'MIX') {
    $students = $pdo->prepare("SELECT st.* FROM students st
        JOIN sst_student_claims ssc ON ssc.student_id = st.id AND ssc.section_subject_teacher_id = ?
        WHERE st.is_active = 1 ORDER BY FIELD(st.sex, 'M', 'F'), st.full_name");
    $students->execute([$sstId]);
} else {
    $students = $pdo->prepare("SELECT * FROM students WHERE section_id = ? AND is_active = 1 AND sex = ? ORDER BY FIELD(sex, 'M', 'F'), full_name");
    $students->execute([$assignment['section_id'], $sexScope]);
}
$students = $students->fetchAll();

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

// Prior terms' transmuted grade for THIS subject, so the reviewer sees the running record
// (not just the currently selected term) once Term 2 or 3 is open.
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

render_header($assignment['grade_level'] . ' - ' . $assignment['section_name'] . ' · ' . $assignment['subject_name']);
?>
<div class="flex items-center justify-between mb-6">
  <div class="flex items-center gap-3">
    <span class="text-sm text-slate-500">Term <?= $term ?> · Teacher: <?= h($assignment['teacher_name']) ?></span>
    <?= status_badge($submission['status'] ?? 'not_started') ?>
  </div>
  <form method="get" class="flex gap-1">
    <input type="hidden" name="sst_id" value="<?= $sstId ?>">
    <?php for ($t = 1; $t <= 3; $t++): ?>
      <button type="submit" name="term" value="<?= $t ?>" class="px-3 py-1.5 rounded-lg text-sm <?= $t === $term ? 'bg-accent-600 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">Term <?= $t ?></button>
    <?php endfor; ?>
  </form>
</div>

<div id="grid-scroll-top" class="overflow-x-auto mb-1"><div id="grid-scroll-spacer" style="height:1px;"></div></div>
<div id="grid-scroll-bottom" class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-x-auto mb-6">
  <table class="text-sm min-w-full">
    <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
      <tr>
        <th class="text-left px-4 py-3 sticky left-0 bg-slate-50">Student</th>
        <?php foreach (['WW', 'PT', 'EX'] as $type): ?>
          <?php foreach ($itemsByType[$type] as $item): ?>
            <th class="text-center px-3 py-3 whitespace-nowrap">
              <div><?= h($item['item_name']) ?></div>
              <div class="text-[10px] font-normal text-slate-400">/<?= rtrim(rtrim((string) $item['highest_possible_score'], '0'), '.') ?> · <span class="text-slate-800"><?= $componentLabels[$type] ?></span></div>
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
      <?php $lastSex = null; foreach ($students as $student): ?>
      <?php if ($student['sex'] !== $lastSex): $lastSex = $student['sex']; ?>
      <tr>
        <td colspan="99" class="px-4 py-1.5 text-xs font-semibold text-slate-500 uppercase tracking-wide bg-slate-50 sticky left-0"><?= $student['sex'] === 'M' ? 'Male' : 'Female' ?></td>
      </tr>
      <?php endif; ?>
      <?php
          $gradeStmt->execute([$student['id'], $assignment['subject_id'], $term]);
          $grade = $gradeStmt->fetch();
      ?>
      <tr id="student-<?= (int) $student['id'] ?>">
        <td class="px-4 py-2 font-medium whitespace-nowrap sticky left-0 bg-white"><?= h($student['full_name']) ?></td>
        <?php foreach (['WW', 'PT', 'EX'] as $type): ?>
          <?php foreach ($itemsByType[$type] as $item): ?>
            <td class="px-3 py-2 text-center text-slate-600"><?= h($scoreLookup[$item['id']][$student['id']] ?? '—') ?></td>
          <?php endforeach; ?>
        <?php endforeach; ?>
        <?php for ($t = 1; $t < $term; $t++): $pg = $priorGrades[$t][$student['id']] ?? null; ?>
          <td class="px-3 py-2 text-center <?= $pg !== null ? grade_display_class((float) $pg) : 'text-slate-500' ?>"><?= $pg !== null ? h($pg) : '<span class="text-slate-300">—</span>' ?></td>
        <?php endfor; ?>
        <td class="px-3 py-2 text-center font-medium"><?= $grade && $grade['initial_grade'] !== null ? h($grade['initial_grade']) : '<span class="text-slate-300">—</span>' ?></td>
        <td class="px-3 py-2 text-center font-semibold <?= $grade && $grade['transmuted_grade'] !== null ? (grade_display_class((float) $grade['transmuted_grade']) ?: 'text-accent-700') : 'text-accent-700' ?>"><?= $grade && $grade['transmuted_grade'] !== null ? h($grade['transmuted_grade']) : '<span class="text-slate-300">—</span>' ?></td>
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

<?php if ($submission && $submission['status'] === 'submitted_for_review'): ?>
<div class="flex gap-6 items-start">
  <form method="post" data-confirm="Publish this term's grades? This will lock them in and count them toward the section ranking.">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="publish">
    <input type="hidden" name="sst_id" value="<?= $sstId ?>">
    <input type="hidden" name="term" value="<?= $term ?>">
    <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-medium px-5 py-2.5 rounded-lg text-sm">Publish</button>
  </form>
  <form method="post" class="flex-1 max-w-md">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="return">
    <input type="hidden" name="sst_id" value="<?= $sstId ?>">
    <input type="hidden" name="term" value="<?= $term ?>">
    <textarea name="revision_comment" required placeholder="Explain what needs to be fixed…" class="w-full mb-2 px-3 py-2 border border-slate-300 rounded-lg text-sm" rows="2"></textarea>
    <button type="submit" class="bg-rose-600 hover:bg-rose-700 text-white font-medium px-5 py-2.5 rounded-lg text-sm">Return for Revision</button>
  </form>
</div>
<?php elseif ($submission && $submission['status'] === 'published'): ?>
<p class="text-sm text-emerald-700 mb-4">Published on <?= h($submission['reviewed_at']) ?>.</p>
<?php if ($pendingEditRequest): ?>
<div class="bg-amber-50 border border-amber-200 rounded-xl p-5 max-w-lg">
  <h2 class="text-sm font-semibold text-amber-800 mb-1">Edit request from <?= h($assignment['teacher_name']) ?></h2>
  <p class="text-sm text-amber-700 mb-4">Reason: <?= h($pendingEditRequest['reason']) ?></p>
  <div class="flex gap-3 items-start">
    <form method="post" data-confirm="Approve this edit request? The term will reopen for editing.">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="approve_edit">
      <input type="hidden" name="edit_request_id" value="<?= (int) $pendingEditRequest['id'] ?>">
      <input type="hidden" name="sst_id" value="<?= $sstId ?>">
      <input type="hidden" name="term" value="<?= $term ?>">
      <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-medium px-4 py-2 rounded-lg text-sm">Approve</button>
    </form>
    <form method="post" class="flex-1">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reject_edit">
      <input type="hidden" name="edit_request_id" value="<?= (int) $pendingEditRequest['id'] ?>">
      <input type="hidden" name="sst_id" value="<?= $sstId ?>">
      <input type="hidden" name="term" value="<?= $term ?>">
      <textarea name="review_comment" required placeholder="Explain why this is being rejected…" class="w-full mb-2 px-3 py-2 border border-slate-300 rounded-lg text-sm" rows="2"></textarea>
      <button type="submit" class="bg-rose-600 hover:bg-rose-700 text-white font-medium px-4 py-2 rounded-lg text-sm">Reject</button>
    </form>
  </div>
</div>
<?php endif; ?>
<?php else: ?>
<p class="text-sm text-slate-400">This term has not been submitted for review yet.</p>
<?php endif; ?>
<script>
window.addEventListener('DOMContentLoaded', function () {
  initTopScrollbar('grid-scroll-top', 'grid-scroll-bottom', 'grid-scroll-spacer');
  initHashHighlight();
});
</script>
<?php render_footer(); ?>
