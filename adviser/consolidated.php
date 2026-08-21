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
$pendingCount = count(array_filter($data['subjects'], fn($s) => $s['status'] !== 'published'));

render_header($section['grade_level'] . ' - ' . $section['section_name'] . ' · Consolidated Grades');
?>
<div class="flex items-center justify-between mb-6">
  <form method="get" class="flex gap-1">
    <input type="hidden" name="section_id" value="<?= $sectionId ?>">
    <?php for ($t = 1; $t <= 3; $t++): ?>
      <button type="submit" name="term" value="<?= $t ?>" class="px-3 py-1.5 rounded-lg text-sm <?= $t === $term ? 'bg-accent-600 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">Term <?= $t ?></button>
    <?php endfor; ?>
  </form>
  <a href="<?= h(url('/adviser/export_csv.php?section_id=' . $sectionId . '&term=' . $term)) ?>" class="px-3 py-1.5 rounded-lg text-sm bg-slate-100 text-slate-600 hover:bg-slate-200">Export CSV</a>
</div>

<?php if ($pendingCount > 0): ?>
<div class="mb-6 px-4 py-3 rounded-lg text-sm bg-amber-50 text-amber-700 border border-amber-200">
  <?= $pendingCount ?> of <?= count($data['subjects']) ?> subject(s) not yet published by the Head Teacher — those columns show "Pending" and are excluded from the General Average until published.
</div>
<?php endif; ?>

<div id="grid-scroll-top" class="overflow-x-auto mb-1"><div id="grid-scroll-spacer" style="height:1px;"></div></div>
<div id="grid-scroll-bottom" class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-x-auto">
  <table class="text-sm min-w-full">
    <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
      <tr>
        <th rowspan="2" class="text-left px-4 py-3 sticky left-0 bg-slate-50 align-bottom">Student</th>
        <?php foreach ($data['subjects'] as $subject): ?>
          <th colspan="<?= $term + ($term === 3 ? 1 : 0) ?>" class="text-center px-3 py-2 whitespace-nowrap border-l border-slate-200"><?= h($subject['subject_name']) ?></th>
        <?php endforeach; ?>
        <th rowspan="2" class="text-center px-3 py-3 align-bottom">General Average</th>
        <th rowspan="2" class="text-center px-3 py-3 align-bottom">Rank</th>
      </tr>
      <?php if ($term > 1): ?>
      <tr>
        <?php foreach ($data['subjects'] as $subject): ?>
          <?php for ($t = 1; $t <= $term; $t++): ?>
            <th class="text-center px-2 py-1.5 font-medium text-[10px] border-l border-slate-100">T<?= $t ?></th>
          <?php endfor; ?>
          <?php if ($term === 3): ?>
            <th class="text-center px-2 py-1.5 font-medium text-[10px] border-l border-slate-100 text-accent-600">Final</th>
          <?php endif; ?>
        <?php endforeach; ?>
      </tr>
      <?php endif; ?>
    </thead>
    <tbody class="divide-y divide-slate-100">
      <?php $lastSex = null; foreach ($data['students'] as $student): ?>
      <?php if ($student['sex'] !== $lastSex): $lastSex = $student['sex']; ?>
      <tr>
        <td colspan="99" class="px-4 py-1.5 text-xs font-semibold text-slate-500 uppercase tracking-wide bg-slate-50 sticky left-0"><?= $student['sex'] === 'M' ? 'Male' : 'Female' ?></td>
      </tr>
      <?php endif; ?>
      <tr>
        <td class="px-4 py-2 font-medium whitespace-nowrap sticky left-0 bg-white"><?= h($student['full_name']) ?></td>
        <?php foreach ($data['subjects'] as $subject): ?>
          <?php for ($t = 1; $t <= $term; $t++): $g = $data['gradesByTerm'][$t][$student['id']][$subject['subject_id']] ?? null; ?>
            <td class="px-2 py-2 text-center border-l border-slate-100 <?= $g !== null ? grade_display_class((float) $g) : '' ?>">
              <?php if ($t === $term && $subject['status'] !== 'published'): ?>
                <span class="text-xs text-amber-500">Pending</span>
              <?php else: ?>
                <?= $g !== null ? h($g) : '<span class="text-slate-300">—</span>' ?>
              <?php endif; ?>
            </td>
          <?php endfor; ?>
          <?php if ($term === 3): $fg = $data['finalGrades'][$student['id']][$subject['subject_id']] ?? null; ?>
            <td class="px-2 py-2 text-center border-l border-slate-100 font-semibold <?= $fg !== null ? (grade_display_class((float) $fg) ?: 'text-accent-700') : 'text-accent-700' ?>"><?= $fg !== null ? h($fg) : '—' ?></td>
          <?php endif; ?>
        <?php endforeach; ?>
        <?php $avg = $data['averages'][$student['id']]['average'] ?? null; ?>
        <td class="px-3 py-2 text-center font-semibold <?= $avg !== null ? (grade_display_class((float) $avg) ?: 'text-accent-700') : 'text-accent-700' ?>"><?= $avg !== null ? h($avg) : '—' ?></td>
        <td class="px-3 py-2 text-center"><?= h($data['averages'][$student['id']]['rank_in_section'] ?? '—') ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$data['students']): ?>
      <tr><td colspan="99" class="px-4 py-6 text-center text-slate-400">No students in this section yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
<script>
window.addEventListener('DOMContentLoaded', function () {
  initTopScrollbar('grid-scroll-top', 'grid-scroll-bottom', 'grid-scroll-spacer');
});
</script>
<?php render_footer(); ?>
