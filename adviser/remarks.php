<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';

$sectionId = (int) ($_GET['section_id'] ?? $_POST['section_id'] ?? 0);
$term = (int) ($_GET['term'] ?? $_POST['term'] ?? 1);
if ($term < 1 || $term > 3) {
    $term = 1;
}

$section = require_own_section($sectionId);
$pdo = db();

$studentsStmt = $pdo->prepare("SELECT id, full_name, sex FROM students WHERE section_id = ? AND is_active = 1 ORDER BY FIELD(sex, 'M', 'F'), full_name");
$studentsStmt->execute([$sectionId]);
$students = $studentsStmt->fetchAll();

// Keyed by id regardless of active state, so a remark already saved against a since-deactivated
// bank entry still resolves to its correct text when re-saving the form untouched.
$bankById = [];
foreach ($pdo->query('SELECT id, remark_text FROM remark_bank')->fetchAll() as $row) {
    $bankById[(int) $row['id']] = $row['remark_text'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $studentIds = array_column($students, 'id');
    $selections = $_POST['remark_bank_id'] ?? [];
    $others = $_POST['remark_other'] ?? [];
    $recordedBy = (int) current_user()['id'];

    $upsert = $pdo->prepare('INSERT INTO student_term_remarks (student_id, term, school_year_id, remark_bank_id, remark_text, recorded_by)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE remark_bank_id = VALUES(remark_bank_id), remark_text = VALUES(remark_text), recorded_by = VALUES(recorded_by)');
    $delete = $pdo->prepare('DELETE FROM student_term_remarks WHERE student_id = ? AND term = ? AND school_year_id = ?');

    foreach ($studentIds as $studentId) {
        $selected = (string) ($selections[$studentId] ?? '');
        if ($selected === '') {
            // Blank clears whatever was saved before — a remark is optional, never forced.
            $delete->execute([$studentId, $term, $section['school_year_id']]);
            continue;
        }
        if ($selected === 'other') {
            $otherText = trim((string) ($others[$studentId] ?? ''));
            if ($otherText === '') {
                continue; // "Other" picked but nothing typed — leave whatever was already saved
            }
            $upsert->execute([$studentId, $term, $section['school_year_id'], null, $otherText, $recordedBy]);
            continue;
        }
        $bankId = (int) $selected;
        if (!isset($bankById[$bankId])) {
            continue;
        }
        $upsert->execute([$studentId, $term, $section['school_year_id'], $bankId, $bankById[$bankId], $recordedBy]);
    }
    flash_set('success', 'Remarks saved.');
    redirect('/adviser/remarks.php?section_id=' . $sectionId . '&term=' . $term);
}

$remarkOptions = $pdo->query("SELECT * FROM remark_bank WHERE is_active = 1 ORDER BY category, remark_text")->fetchAll();
$optionsByCategory = ['positive' => [], 'needs_improvement' => []];
foreach ($remarkOptions as $r) {
    $optionsByCategory[$r['category']][] = $r;
}
$categoryLabels = ['positive' => 'Positive', 'needs_improvement' => 'Needs Improvement'];

$existingStmt = $pdo->prepare('SELECT student_id, remark_bank_id, remark_text FROM student_term_remarks WHERE term = ? AND school_year_id = ? AND student_id IN (' . implode(',', array_fill(0, max(count($students), 1), '?')) . ')');
$existingByStudent = [];
if ($students) {
    $existingStmt->execute(array_merge([$term, $section['school_year_id']], array_column($students, 'id')));
    foreach ($existingStmt->fetchAll() as $row) {
        $existingByStudent[(int) $row['student_id']] = $row;
    }
}

render_header($section['grade_level'] . ' - ' . $section['section_name'] . ' · Remarks');
?>
<div class="flex items-center justify-between mb-6">
  <form method="get" class="flex gap-1">
    <input type="hidden" name="section_id" value="<?= $sectionId ?>">
    <?php for ($t = 1; $t <= 3; $t++): ?>
      <button type="submit" name="term" value="<?= $t ?>" class="px-3 py-1.5 rounded-lg text-sm <?= $t === $term ? 'bg-accent-600 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">Term <?= $t ?></button>
    <?php endfor; ?>
  </form>
  <p class="text-xs text-slate-400">Optional per student — printed on Card Slips' 2-per-sheet layout.</p>
</div>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="section_id" value="<?= $sectionId ?>">
  <input type="hidden" name="term" value="<?= $term ?>">
  <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5 mb-4">
    <div class="space-y-3">
      <?php $lastSex = null; foreach ($students as $st): $existing = $existingByStudent[$st['id']] ?? null; ?>
      <?php if ($st['sex'] !== $lastSex): $lastSex = $st['sex']; ?>
      <div class="text-xs font-semibold text-slate-500 uppercase tracking-wide pt-2 first:pt-0"><?= $st['sex'] === 'M' ? 'Male' : 'Female' ?></div>
      <?php endif; ?>
      <div class="flex flex-wrap items-start gap-3 pb-3 border-b border-slate-100 last:border-0 last:pb-0">
        <div class="w-48 flex-shrink-0">
          <div class="text-sm font-medium text-slate-700"><?= h($st['full_name']) ?></div>
        </div>
        <div class="flex-1 min-w-[260px]">
          <select name="remark_bank_id[<?= $st['id'] ?>]" class="js-remark-select w-full px-3 py-2 border border-slate-300 rounded-lg text-sm" data-student-id="<?= $st['id'] ?>">
            <option value="">— No remark —</option>
            <?php foreach ($optionsByCategory as $cat => $opts): ?>
              <?php if (!$opts) continue; ?>
              <optgroup label="<?= h($categoryLabels[$cat]) ?>">
                <?php foreach ($opts as $opt): ?>
                <option value="<?= $opt['id'] ?>" <?= ($existing && $existing['remark_bank_id'] !== null && (int) $existing['remark_bank_id'] === (int) $opt['id']) ? 'selected' : '' ?>><?= h($opt['remark_text']) ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
            <option value="other" <?= ($existing && $existing['remark_bank_id'] === null) ? 'selected' : '' ?>>Other, specify…</option>
          </select>
          <div class="js-remark-other-wrap mt-2 <?= ($existing && $existing['remark_bank_id'] === null) ? '' : 'hidden' ?>" data-other-for="<?= $st['id'] ?>">
            <textarea name="remark_other[<?= $st['id'] ?>]" placeholder="Specify…" rows="2" maxlength="255"
              class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm"><?= h(($existing && $existing['remark_bank_id'] === null) ? $existing['remark_text'] : '') ?></textarea>
            <p class="text-xs text-slate-400 mt-1">Up to 255 characters — keeps it printable on the slip alongside a full roster of subjects.</p>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (!$students): ?>
      <p class="text-sm text-slate-400">No students in this section yet.</p>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($students): ?>
  <button type="submit" class="bg-accent-600 hover:bg-accent-700 text-white font-medium px-5 py-2.5 rounded-lg text-sm">Save Remarks</button>
  <?php endif; ?>
</form>
<script>
window.addEventListener('DOMContentLoaded', function () {
  initRemarkToggle();
});
</script>
<?php render_footer(); ?>
