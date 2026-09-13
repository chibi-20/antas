<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';

$sectionId = (int) ($_GET['section_id'] ?? 0);
$term = (int) ($_GET['term'] ?? 1);
if ($term < 1 || $term > 3) {
    $term = 1;
}
$mode = ($_GET['mode'] ?? '4up') === '2up' ? '2up' : '4up';

$section = require_own_section($sectionId);
$pdo = db();
$data = get_consolidated_data($sectionId, (int) $section['school_year_id'], $term);
$year = active_school_year();

// Only fetched in 2up mode — the 4up layout never shows remarks at all.
$remarksByStudent = [];
if ($mode === '2up' && $data['students']) {
    $placeholders = implode(',', array_fill(0, count($data['students']), '?'));
    $stmt = $pdo->prepare("SELECT student_id, remark_text FROM student_term_remarks
        WHERE term = ? AND school_year_id = ? AND student_id IN ($placeholders)");
    $stmt->execute(array_merge([$term, $section['school_year_id']], array_column($data['students'], 'id')));
    foreach ($stmt->fetchAll() as $row) {
        $remarksByStudent[(int) $row['student_id']] = $row['remark_text'];
    }
}

/**
 * Renders one slip's shared inner markup (header, student info, grades table, general-average
 * footer, descriptor legend) — used by both the 4-per-sheet and 2-per-sheet loops below so they
 * can never quietly drift apart into two different-looking slips. $remarkText null means "don't
 * render a remarks section at all" (4up mode); a string (possibly empty) means "render it,
 * showing that text or a — placeholder" (2up mode).
 */
function render_card_slip_inner(array $student, array $section, array $data, int $term, string $yearLabel, ?string $remarkText): void
{
    ?>
    <div class="slip-header">
      <img src="<?= h(url('/assets/img/school_logo.png')) ?>" alt="" class="slip-logo" onerror="this.style.display='none'">
      <div class="slip-header-text">
        <div class="slip-deped-line">Republic of the Philippines</div>
        <div class="slip-deped-line">Department of Education</div>
        <div class="slip-deped-line">Region IV-A (CALABARZON)</div>
        <div class="slip-deped-line">Schools Division of Biñan City</div>
        <div class="slip-school-name">Jacobo Z. Gonzales Memorial National High School</div>
        <div class="slip-title">Term Grade Slip</div>
        <div class="slip-sub"><?= h($yearLabel) ?> · Term <?= $term ?></div>
      </div>
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
    </div>
    <div class="slip-legend"><?= h(GRADE_DESCRIPTOR_LEGEND) ?></div>
    <?php if ($remarkText !== null): ?>
    <div class="slip-remarks">
      <div class="slip-remarks-label">Teacher's Comments / Remarks</div>
      <div><?= $remarkText !== '' ? h($remarkText) : '—' ?></div>
    </div>
    <?php endif; ?>
    <?php
}

$pages4 = array_chunk($data['students'], 4);
$pages2 = array_chunk($data['students'], 2);

render_header($section['grade_level'] . ' - ' . $section['section_name'] . ' · Card Slips');
?>
<div class="flex items-center justify-between mb-6 no-print">
  <div class="flex items-center gap-4">
    <form method="get" class="flex gap-1">
      <input type="hidden" name="section_id" value="<?= $sectionId ?>">
      <input type="hidden" name="mode" value="<?= h($mode) ?>">
      <?php for ($t = 1; $t <= 3; $t++): ?>
        <button type="submit" name="term" value="<?= $t ?>" class="px-3 py-1.5 rounded-lg text-sm <?= $t === $term ? 'bg-accent-600 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">Term <?= $t ?></button>
      <?php endfor; ?>
    </form>
    <form method="get" class="flex gap-1">
      <input type="hidden" name="section_id" value="<?= $sectionId ?>">
      <input type="hidden" name="term" value="<?= $term ?>">
      <button type="submit" name="mode" value="4up" class="px-3 py-1.5 rounded-lg text-sm <?= $mode === '4up' ? 'bg-accent-600 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">4 per sheet</button>
      <button type="submit" name="mode" value="2up" class="px-3 py-1.5 rounded-lg text-sm <?= $mode === '2up' ? 'bg-accent-600 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">2 per sheet (with remarks)</button>
    </form>
  </div>
  <div class="flex gap-2">
    <button id="download-pdf" type="button" class="px-4 py-2 rounded-lg text-sm bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 font-medium flex items-center gap-1.5"><?= icon_svg('download', 'w-4 h-4') ?> Download PDF</button>
    <button onclick="window.print()" class="px-4 py-2 rounded-lg text-sm bg-accent-600 hover:bg-accent-700 text-white font-medium">Print</button>
  </div>
</div>

<?php // Mode switching reloads the page (same GET-and-reload pattern as the Term buttons), so
// only the active mode's markup is ever built or sent to the browser — never both at once. ?>
<?php if ($mode === '4up'): ?>
<?php foreach ($pages4 as $page): ?>
<div class="print-page">
  <?php foreach ($page as $student): ?>
  <div class="slip">
    <?php render_card_slip_inner($student, $section, $data, $term, $year['year_label'] ?? '', null); ?>
  </div>
  <?php endforeach; ?>
  <?php for ($i = count($page); $i < 4; $i++): ?>
    <div class="slip slip-empty"></div>
  <?php endfor; ?>
</div>
<?php endforeach; ?>
<?php else: ?>
<?php foreach ($pages2 as $page): ?>
<div class="print-page-2up">
  <?php foreach ($page as $student): ?>
  <div class="slip">
    <?php render_card_slip_inner($student, $section, $data, $term, $year['year_label'] ?? '', $remarksByStudent[$student['id']] ?? ''); ?>
  </div>
  <?php endforeach; ?>
  <?php for ($i = count($page); $i < 2; $i++): ?>
    <div class="slip slip-empty"></div>
  <?php endfor; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php if (!$data['students']): ?>
<p class="text-slate-400 text-sm no-print">No students in this section yet.</p>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script>
(function () {
  var btn = document.getElementById('download-pdf');
  if (!btn) return;
  var mode = <?= json_encode($mode) ?>;
  var fileName = <?= json_encode(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $section['grade_level'] . '-' . $section['section_name'])) . '-term' . $term) ?> + (mode === '2up' ? '-2up-with-remarks' : '-card-slips') + '.pdf';

  btn.addEventListener('click', async function () {
    var originalLabel = btn.innerHTML;
    btn.disabled = true;
    btn.textContent = 'Generating PDF…';
    try {
      // Renders each on-screen page (the same layout used for printing) to an image and drops
      // it onto its own PDF page — no server-side PDF library needed. Only the active mode's
      // markup is ever in the DOM (see the PHP above), so no scoping is needed here.
      var pages = document.querySelectorAll('.print-page, .print-page-2up');
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
