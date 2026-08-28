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
$year = active_school_year();
$pages = array_chunk($data['students'], 4);

render_header($section['grade_level'] . ' - ' . $section['section_name'] . ' · Card Slips');
?>
<div class="flex items-center justify-between mb-6 no-print">
  <form method="get" class="flex gap-1">
    <input type="hidden" name="section_id" value="<?= $sectionId ?>">
    <?php for ($t = 1; $t <= 3; $t++): ?>
      <button type="submit" name="term" value="<?= $t ?>" class="px-3 py-1.5 rounded-lg text-sm <?= $t === $term ? 'bg-accent-600 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">Term <?= $t ?></button>
    <?php endfor; ?>
  </form>
  <div class="flex gap-2">
    <button id="download-pdf" type="button" class="px-4 py-2 rounded-lg text-sm bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 font-medium flex items-center gap-1.5"><?= icon_svg('download', 'w-4 h-4') ?> Download PDF</button>
    <button onclick="window.print()" class="px-4 py-2 rounded-lg text-sm bg-accent-600 hover:bg-accent-700 text-white font-medium">Print (4 per sheet)</button>
  </div>
</div>

<?php foreach ($pages as $page): ?>
<div class="print-page">
  <?php foreach ($page as $student): ?>
  <div class="slip">
    <div class="slip-header">
      <div class="slip-title">Term Grade Slip</div>
      <div class="slip-sub"><?= h($year['year_label'] ?? '') ?> · Term <?= $term ?></div>
    </div>
    <div class="slip-student">
      <div><strong><?= h($student['full_name']) ?></strong></div>
      <div>LRN: <?= h($student['lrn']) ?></div>
      <div><?= h($section['grade_level'] . ' - ' . $section['section_name']) ?></div>
    </div>
    <table class="slip-table">
      <thead>
        <tr>
          <th>Subject</th>
          <?php for ($t = 1; $t <= $term; $t++): ?><th>T<?= $t ?></th><?php endfor; ?>
          <?php if ($term === 3): ?><th>Final</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($data['subjects'] as $subject): ?>
        <?php
            $studentAssignment = subject_assignment_for_student($subject, $student);
            $studentStatus = $studentAssignment['status'] ?? $subject['status'];
        ?>
        <tr>
          <td class="<?= $subject['is_child'] ? 'italic pl-3' : '' ?>"><?= h($subject['subject_name']) ?></td>
          <?php for ($t = 1; $t <= $term; $t++): $g = grade_whole($data['gradesByTerm'][$t][$student['id']][$subject['subject_id']] ?? null); ?>
          <td class="<?= $g !== null ? grade_display_class((float) $g) : '' ?>">
            <?php if ($t === $term && $studentStatus !== 'published'): ?>
              <span class="pending">Pending</span>
            <?php elseif ($g === null): ?>
              —
            <?php else: ?>
              <?= h($g) ?><span class="descriptor">(<?= h(grade_descriptor_letter((float) $g)) ?>)</span>
            <?php endif; ?>
          </td>
          <?php endfor; ?>
          <?php if ($term === 3): $fg = grade_whole($data['finalGrades'][$student['id']][$subject['subject_id']] ?? null); ?>
          <td class="<?= $fg !== null ? grade_display_class((float) $fg) : '' ?>">
            <?php if ($fg === null): ?><strong>—</strong><?php else: ?>
              <strong><?= h($fg) ?></strong><span class="descriptor">(<?= h(grade_descriptor_letter((float) $fg)) ?>)</span>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php $avg = grade_whole($data['averages'][$student['id']]['average'] ?? null); ?>
    <div class="slip-footer">
      <div>General Average: <strong class="<?= $avg !== null ? grade_display_class((float) $avg) : '' ?>"><?= $avg !== null ? h($avg) : '—' ?></strong><?php if ($avg !== null): ?><span class="descriptor">(<?= h(grade_descriptor_letter((float) $avg)) ?>)</span><?php endif; ?></div>
      <div>Rank in Section: <strong><?= h($data['averages'][$student['id']]['rank_in_section'] ?? '—') ?></strong></div>
    </div>
    <div class="slip-legend"><?= h(GRADE_DESCRIPTOR_LEGEND) ?></div>
  </div>
  <?php endforeach; ?>
  <?php for ($i = count($page); $i < 4; $i++): ?>
    <div class="slip slip-empty"></div>
  <?php endfor; ?>
</div>
<?php endforeach; ?>
<?php if (!$pages): ?>
<p class="text-slate-400 text-sm no-print">No students in this section yet.</p>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script>
(function () {
  var btn = document.getElementById('download-pdf');
  if (!btn) return;
  var fileName = <?= json_encode(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $section['grade_level'] . '-' . $section['section_name'])) . '-term' . $term . '-card-slips.pdf') ?>;

  btn.addEventListener('click', async function () {
    var originalLabel = btn.innerHTML;
    btn.disabled = true;
    btn.textContent = 'Generating PDF…';
    try {
      // Renders each on-screen .print-page (the same 4-slip A4 layout used for printing) to
      // an image and drops it onto its own PDF page — no server-side PDF library needed.
      var pages = document.querySelectorAll('.print-page');
      var pdf = new window.jspdf.jsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait' });
      for (var i = 0; i < pages.length; i++) {
        var canvas = await html2canvas(pages[i], { scale: 2, backgroundColor: '#ffffff' });
        var imgData = canvas.toDataURL('image/jpeg', 0.95);
        var targetWidthMm = 190;
        var targetHeightMm = targetWidthMm * (canvas.height / canvas.width);
        var x = (210 - targetWidthMm) / 2;
        var y = 10;
        if (i > 0) pdf.addPage();
        pdf.addImage(imgData, 'JPEG', x, y, targetWidthMm, targetHeightMm);
      }
      pdf.save(fileName);
    } catch (err) {
      alert('Could not generate the PDF: ' + err.message);
    } finally {
      btn.disabled = false;
      btn.innerHTML = originalLabel;
    }
  });
})();
</script>
<?php render_footer(); ?>
