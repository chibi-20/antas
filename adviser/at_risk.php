<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';

$sectionId = (int) ($_GET['section_id'] ?? 0);
$term = (int) ($_GET['term'] ?? 1);
if ($term < 1 || $term > 3) {
    $term = 1;
}

$section = require_own_section($sectionId);
$data = get_consolidated_data($sectionId, (int) $section['school_year_id'], $term);

// Every published subject grade below 75 (the DepEd passing mark) for the selected term,
// grouped per student. 70-74 is split out as "For Remedial" vs a harder "Failing" below 70 —
// the two most common DepEd-flavored bands schools act on differently.
$atRisk = [];
foreach ($data['students'] as $student) {
    foreach ($data['subjects'] as $subject) {
        // MAPEH's components (Music-Arts, PE-Health) never count toward the average and must
        // never surface here on their own — only the merged MAPEH grade can flag a student.
        if ($subject['is_child']) {
            continue;
        }
        $g = $data['gradesByTerm'][$term][$student['id']][$subject['subject_id']] ?? null;
        if ($g === null || (float) $g >= 75) {
            continue;
        }
        if (!isset($atRisk[$student['id']])) {
            $atRisk[$student['id']] = ['student' => $student, 'subjects' => []];
        }
        $atRisk[$student['id']]['subjects'][] = [
            'name' => $subject['subject_name'],
            'grade' => $g,
            'band' => (float) $g < 70 ? 'Failing' : 'For Remedial',
        ];
    }
}
$failingSubjectCount = array_sum(array_map(fn($r) => count($r['subjects']), $atRisk));

render_header($section['grade_level'] . ' - ' . $section['section_name'] . ' · At Risk', 'Students with a published grade below 75 (DepEd passing mark) this term.');
?>
<div class="flex items-center justify-between mb-6">
  <form method="get" class="flex gap-1">
    <input type="hidden" name="section_id" value="<?= $sectionId ?>">
    <?php for ($t = 1; $t <= 3; $t++): ?>
      <button type="submit" name="term" value="<?= $t ?>" class="px-3 py-1.5 rounded-lg text-sm <?= $t === $term ? 'bg-accent-600 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">Term <?= $t ?></button>
    <?php endfor; ?>
  </form>
  <div class="text-sm text-slate-500">
    <span class="font-semibold text-rose-600"><?= count($atRisk) ?></span> student<?= count($atRisk) === 1 ? '' : 's' ?>
    · <span class="font-semibold text-rose-600"><?= $failingSubjectCount ?></span> subject grade<?= $failingSubjectCount === 1 ? '' : 's' ?> below 75
  </div>
</div>

<?php if (!$atRisk): ?>
<div class="bg-white border border-slate-200 rounded-xl shadow-sm px-4 py-8 text-center text-slate-400 text-sm">No students below 75 in any published subject for this term.</div>
<?php else: ?>
<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
  <table class="w-full text-sm">
    <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
      <tr><th class="text-left px-4 py-3">Student</th><th class="text-left px-4 py-3">Subject</th><th class="text-left px-4 py-3">Grade</th><th class="text-left px-4 py-3">Status</th></tr>
    </thead>
    <tbody class="divide-y divide-slate-100">
      <?php foreach ($atRisk as $row): ?>
        <?php foreach ($row['subjects'] as $i => $s): ?>
        <tr>
          <td class="px-4 py-3 font-medium <?= $i > 0 ? 'text-slate-300' : '' ?>"><?= $i === 0 ? h($row['student']['full_name']) : '' ?></td>
          <td class="px-4 py-3 text-slate-600"><?= h($s['name']) ?></td>
          <td class="px-4 py-3 <?= grade_display_class((float) $s['grade']) ?>"><?= h($s['grade']) ?></td>
          <td class="px-4 py-3">
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $s['band'] === 'Failing' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700' ?>"><?= h($s['band']) ?></span>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php render_footer(); ?>
