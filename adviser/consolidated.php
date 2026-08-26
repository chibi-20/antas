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
// MAPEH's individual components (Music-Arts, PE-Health) are shown but never counted here —
// they were never independently "pending" before this banner existed, so exclude them from
// both sides of the count instead of inflating it now that they're visible.
$countableSubjects = array_values(array_filter($data['subjects'], fn($s) => !$s['is_child']));
$pendingCount = count(array_filter($countableSubjects, fn($s) => $s['status'] !== 'published'));
$year = active_school_year();

render_header($section['grade_level'] . ' - ' . $section['section_name'] . ' · Consolidated Grades');
?>
<div class="flex items-center justify-between mb-6">
  <form method="get" class="flex gap-1">
    <input type="hidden" name="section_id" value="<?= $sectionId ?>">
    <?php for ($t = 1; $t <= 3; $t++): ?>
      <button type="submit" name="term" value="<?= $t ?>" class="px-3 py-1.5 rounded-lg text-sm <?= $t === $term ? 'bg-accent-600 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">Term <?= $t ?></button>
    <?php endfor; ?>
  </form>
  <div class="flex gap-2">
    <button id="download-pdf" type="button" class="px-3 py-1.5 rounded-lg text-sm bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 flex items-center gap-1.5"><?= icon_svg('download', 'w-4 h-4') ?> Download PDF</button>
    <a href="<?= h(url('/adviser/export_csv.php?section_id=' . $sectionId . '&term=' . $term)) ?>" class="px-3 py-1.5 rounded-lg text-sm bg-slate-100 text-slate-600 hover:bg-slate-200">Export CSV</a>
  </div>
</div>

<?php if ($pendingCount > 0): ?>
<div class="mb-6 px-4 py-3 rounded-lg text-sm bg-amber-50 text-amber-700 border border-amber-200">
  <?= $pendingCount ?> of <?= count($countableSubjects) ?> subject(s) not yet published by the Head Teacher — those columns show "Pending" and are excluded from the General Average until published.
</div>
<?php endif; ?>


<div id="grid-scroll-top" class="overflow-x-auto mb-1"><div id="grid-scroll-spacer" style="height:1px;"></div></div>
<div id="pdf-capture-root">
  <div id="pdf-letterhead" class="hidden text-center leading-tight mb-4 text-slate-800">
    <div>Republic of the Philippines</div>
    <div>Department of Education</div>
    <div>Region IV-A CALABARZON</div>
    <div>Division of Binan City</div>
    <div class="font-semibold">JACOBO Z. GONZALES MEMORIAL NATIONAL HIGH SCHOOL</div>
    <div>School Year <?= h($year['year_label'] ?? '') ?></div>
    <div class="font-semibold mt-1">CONSOLIDATION OF GRADES FOR <?= h(strtoupper($section['grade_level'] . ' ' . $section['section_name'])) ?></div>
  </div>
  <p class="text-xs text-slate-400 mb-2"><?= h(GRADE_DESCRIPTOR_LEGEND) ?></p>
<div id="grid-scroll-bottom" class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-x-auto">
  <table class="text-sm min-w-full">
    <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
      <tr>
        <th rowspan="2" class="text-left px-4 py-3 sticky left-0 bg-slate-50 align-bottom">Student</th>
        <?php foreach ($data['subjects'] as $subject): ?>
          <th colspan="<?= $term + ($term === 3 ? 1 : 0) ?>" class="text-center px-3 py-2 whitespace-nowrap border-l border-slate-200 <?= $subject['is_child'] ? 'italic font-normal text-slate-500' : '' ?>"><?= h($subject['subject_name']) ?></th>
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
          <?php for ($t = 1; $t <= $term; $t++): $g = grade_whole($data['gradesByTerm'][$t][$student['id']][$subject['subject_id']] ?? null); ?>
            <td class="px-2 py-2 text-center border-l border-slate-100 <?= $g !== null ? grade_display_class((float) $g) : '' ?>">
              <?php if ($t === $term && $subject['status'] !== 'published'): ?>
                <span class="text-xs text-amber-500">Pending</span>
              <?php elseif ($g === null): ?>
                <span class="text-slate-300">—</span>
              <?php else: ?>
                <?= h($g) ?> <span class="text-[10px] font-normal normal-case text-slate-400">(<?= h(grade_descriptor_letter((float) $g)) ?>)</span>
              <?php endif; ?>
            </td>
          <?php endfor; ?>
          <?php if ($term === 3): $fg = grade_whole($data['finalGrades'][$student['id']][$subject['subject_id']] ?? null); ?>
            <td class="px-2 py-2 text-center border-l border-slate-100 font-semibold <?= $fg !== null ? (grade_display_class((float) $fg) ?: 'text-accent-700') : 'text-accent-700' ?>">
              <?php if ($fg === null): ?>—<?php else: ?>
                <?= h($fg) ?> <span class="text-[10px] font-normal normal-case text-slate-400">(<?= h(grade_descriptor_letter((float) $fg)) ?>)</span>
              <?php endif; ?>
            </td>
          <?php endif; ?>
        <?php endforeach; ?>
        <?php $avg = grade_whole($data['averages'][$student['id']]['average'] ?? null); ?>
        <td class="px-3 py-2 text-center font-semibold <?= $avg !== null ? (grade_display_class((float) $avg) ?: 'text-accent-700') : 'text-accent-700' ?>">
          <?php if ($avg === null): ?>—<?php else: ?>
            <?= h($avg) ?> <span class="text-[10px] font-normal normal-case text-slate-400">(<?= h(grade_descriptor_letter((float) $avg)) ?>)</span>
          <?php endif; ?>
        </td>
        <td class="px-3 py-2 text-center"><?= h($data['averages'][$student['id']]['rank_in_section'] ?? '—') ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$data['students']): ?>
      <tr><td colspan="99" class="px-4 py-6 text-center text-slate-400">No students in this section yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script>
(function () {
  var btn = document.getElementById('download-pdf');
  if (!btn) return;
  var fileName = <?= json_encode(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $section['grade_level'] . '-' . $section['section_name'])) . '-term' . $term . '-consolidated-grades.pdf') ?>;

  btn.addEventListener('click', async function () {
    var originalLabel = btn.innerHTML;
    btn.disabled = true;
    btn.textContent = 'Generating PDF…';

    var letterhead = document.getElementById('pdf-letterhead');
    var tableWrap = document.getElementById('grid-scroll-bottom');
    var root = document.getElementById('pdf-capture-root');
    var savedWrapStyle = tableWrap.getAttribute('style');
    var wasHidden = letterhead.classList.contains('hidden');

    try {
      // Un-clip the table so html2canvas captures its full width, not just what's visibly
      // scrolled into view, and reveal the normally-hidden official letterhead for the capture.
      letterhead.classList.remove('hidden');
      tableWrap.style.overflow = 'visible';
      tableWrap.style.width = 'max-content';

      var SCALE = 2;
      // Row-boundary-aware page breaks — measured from the live DOM before capture — so a
      // page break never lands in the middle of a student's row on the printed document.
      var rowOffsetsCss = Array.prototype.map.call(tableWrap.querySelectorAll('tbody tr'), function (tr) {
        return tr.getBoundingClientRect().top - root.getBoundingClientRect().top;
      });

      var canvas = await html2canvas(root, { scale: SCALE, backgroundColor: '#ffffff' });

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
      if (savedWrapStyle === null) {
        tableWrap.removeAttribute('style');
      } else {
        tableWrap.setAttribute('style', savedWrapStyle);
      }
      if (wasHidden) letterhead.classList.add('hidden');
      btn.disabled = false;
      btn.innerHTML = originalLabel;
    }
  });
})();
</script>
<script>
window.addEventListener('DOMContentLoaded', function () {
  initTopScrollbar('grid-scroll-top', 'grid-scroll-bottom', 'grid-scroll-spacer');
});
</script>
<?php render_footer(); ?>
