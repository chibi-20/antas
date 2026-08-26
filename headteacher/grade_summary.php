<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';

$user = require_role(['subject_teacher', 'admin']);
$pdo = db();
$year = require_active_school_year();

$sectionId = (int) ($_GET['section_id'] ?? 0);

if ($sectionId) {
    $term = (int) ($_GET['term'] ?? 1);
    if ($term < 1 || $term > 3) {
        $term = 1;
    }

    $section = require_supervised_section($sectionId, (int) $year['id']);
    $supervisedSubjectIds = get_supervised_subject_ids($user['id'], (int) $year['id']);
    $data = get_consolidated_data($sectionId, (int) $year['id'], $term);
    // Only the subjects THIS Head Teacher supervises — a supervised MAPEH component still
    // shows (it's this HT's own subject), everything else in the section is out of scope.
    $data['subjects'] = array_values(array_filter($data['subjects'], fn($s) => in_array($s['subject_id'], $supervisedSubjectIds, true)));

    render_header($section['grade_level'] . ' - ' . $section['section_name'] . ' · Grade Summary');
    echo ht_tab_nav('grade_summary');
    ?>
    <a href="<?= h(url('/headteacher/grade_summary.php')) ?>" class="inline-block mb-4 text-sm text-accent-600 hover:underline">&larr; Back to Sections</a>
    <div class="flex items-center justify-between mb-6">
      <form method="get" class="flex gap-1">
        <input type="hidden" name="section_id" value="<?= $sectionId ?>">
        <?php for ($t = 1; $t <= 3; $t++): ?>
          <button type="submit" name="term" value="<?= $t ?>" class="px-3 py-1.5 rounded-lg text-sm <?= $t === $term ? 'bg-accent-600 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">Term <?= $t ?></button>
        <?php endfor; ?>
      </form>
      <p class="text-xs text-slate-400">Click a grade to see that student's score breakdown.</p>
    </div>

    <?php if (!$data['subjects']): ?>
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm px-4 py-8 text-center text-slate-400 text-sm">You don't supervise a subject taught in this section.</div>
    <?php else: ?>
    <div id="grid-scroll-top" class="overflow-x-auto mb-1"><div id="grid-scroll-spacer" style="height:1px;"></div></div>
    <div id="grid-scroll-bottom" class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-x-auto">
      <table class="text-sm min-w-full">
        <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
          <tr>
            <th rowspan="2" class="text-left px-4 py-3 sticky left-0 bg-slate-50 align-bottom">Student</th>
            <?php foreach ($data['subjects'] as $subject): ?>
              <th colspan="<?= $term + ($term === 3 ? 1 : 0) ?>" class="text-center px-3 py-2 whitespace-nowrap border-l border-slate-200 <?= $subject['is_child'] ? 'italic font-normal text-slate-500' : '' ?>"><?= h($subject['subject_name']) ?></th>
            <?php endforeach; ?>
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
              <?php $reviewUrl = $subject['sst_id'] ? h(url('/headteacher/review.php?sst_id=' . $subject['sst_id'] . '&term=' . $term . '#student-' . $student['id'])) : null; ?>
              <?php for ($t = 1; $t <= $term; $t++): $g = $data['gradesByTerm'][$t][$student['id']][$subject['subject_id']] ?? null; ?>
                <td class="px-2 py-2 text-center border-l border-slate-100 <?= $g !== null ? grade_display_class((float) $g) : '' ?>">
                  <?php if ($t === $term && $subject['status'] !== 'published'): ?>
                    <span class="text-xs text-amber-500">Pending</span>
                  <?php elseif ($g === null): ?>
                    <span class="text-slate-300">—</span>
                  <?php elseif ($reviewUrl && $t === $term): ?>
                    <a href="<?= $reviewUrl ?>" class="hover:underline"><?= h($g) ?></a>
                  <?php else: ?>
                    <?= h($g) ?>
                  <?php endif; ?>
                </td>
              <?php endfor; ?>
              <?php if ($term === 3): $fg = $data['finalGrades'][$student['id']][$subject['subject_id']] ?? null; ?>
                <td class="px-2 py-2 text-center border-l border-slate-100 font-semibold <?= $fg !== null ? (grade_display_class((float) $fg) ?: 'text-accent-700') : 'text-accent-700' ?>"><?= $fg !== null ? h($fg) : '—' ?></td>
              <?php endif; ?>
            <?php endforeach; ?>
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
    <?php endif; ?>
    <?php render_footer(); ?>
    <?php
    exit;
}

$sections = get_supervised_sections($user['id'], (int) $year['id']);

render_header('Grade Summary', 'Sections where you supervise at least one subject.');
echo ht_tab_nav('grade_summary');
?>
<div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
  <?php foreach ($sections as $sec): ?>
  <a href="<?= h(url('/headteacher/grade_summary.php?section_id=' . $sec['id'])) ?>" class="block bg-white border border-slate-200 rounded-xl shadow-sm p-5 hover:border-accent-300 hover:shadow-md transition-shadow">
    <div class="font-semibold text-slate-800"><?= h($sec['grade_level'] . ' - ' . $sec['section_name']) ?></div>
    <div class="text-xs text-slate-400 mt-1"><?= h($year['year_label']) ?></div>
  </a>
  <?php endforeach; ?>
  <?php if (!$sections): ?>
  <div class="text-slate-400 text-sm">No sections found — you don't currently supervise a subject taught anywhere.</div>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
