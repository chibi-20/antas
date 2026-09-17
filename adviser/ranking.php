<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';

$sectionId = (int) ($_GET['section_id'] ?? 0);
$view = (string) ($_GET['term'] ?? '1');
if (!in_array($view, ['1', '2', '3', 'overall'], true)) {
    $view = '1';
}
$isOverall = $view === 'overall';

$section = require_own_section($sectionId);
$pdo = db();

if ($isOverall) {
    // A student's Overall General Average is the mean of whichever terms already have one —
    // only once at least 2 of the 3 exist, per the LGU voucher's own rule, so a student barely
    // into Term 2 isn't ranked against peers with a full year's worth of terms yet. Built from
    // general_average_view's own already-rounded per-term averages (not recomputed from raw
    // subject grades) so "Overall" always agrees with what each Term tab already shows.
    $stmt = $pdo->prepare('SELECT student_id, term, average FROM general_average_view WHERE section_id = ? AND school_year_id = ?');
    $stmt->execute([$sectionId, $section['school_year_id']]);
    $termsByStudent = [];
    foreach ($stmt->fetchAll() as $row) {
        $termsByStudent[(int) $row['student_id']][(int) $row['term']] = (float) $row['average'];
    }

    // Compared as thousandths (integers) rather than raw floats so two students whose rounded
    // averages are genuinely identical are never split apart by float representation error.
    $overallByStudent = [];
    foreach ($termsByStudent as $studentId => $terms) {
        if (count($terms) >= 2) {
            $overallByStudent[$studentId] = (int) round((array_sum($terms) / count($terms)) * 1000);
        }
    }
    arsort($overallByStudent);

    $names = [];
    if ($overallByStudent) {
        $placeholders = implode(',', array_fill(0, count($overallByStudent), '?'));
        $namesStmt = $pdo->prepare("SELECT id, full_name, lrn FROM students WHERE id IN ($placeholders)");
        $namesStmt->execute(array_keys($overallByStudent));
        foreach ($namesStmt->fetchAll() as $r) {
            $names[(int) $r['id']] = $r;
        }
    }

    // Standard competition ranking (1,2,2,4 — matches general_average_view's own RANK()
    // behavior for a single term) so Overall and per-term tabs read consistently.
    $ranking = [];
    $position = 0;
    $rank = 0;
    $prevAvg = null;
    foreach ($overallByStudent as $studentId => $avgThousandths) {
        $position++;
        if ($avgThousandths !== $prevAvg) {
            $rank = $position;
            $prevAvg = $avgThousandths;
        }
        $ranking[] = [
            'average' => number_format($avgThousandths / 1000, 3, '.', ''),
            'rank_in_section' => $rank,
            'full_name' => $names[$studentId]['full_name'] ?? '',
            'lrn' => $names[$studentId]['lrn'] ?? null,
        ];
    }
} else {
    $term = (int) $view;
    $stmt = $pdo->prepare('SELECT gav.average, gav.rank_in_section, st.full_name, st.lrn
        FROM general_average_view gav
        JOIN students st ON st.id = gav.student_id
        WHERE gav.section_id = ? AND gav.term = ? AND gav.school_year_id = ?
        ORDER BY gav.rank_in_section');
    $stmt->execute([$sectionId, $term, $section['school_year_id']]);
    $ranking = $stmt->fetchAll();
}

$year = active_school_year();

render_header($section['grade_level'] . ' - ' . $section['section_name'] . ' · Ranking');
?>
<div class="flex items-center justify-between mb-6 flex-wrap gap-3">
  <form method="get" class="flex gap-1">
    <input type="hidden" name="section_id" value="<?= $sectionId ?>">
    <?php for ($t = 1; $t <= 3; $t++): ?>
      <button type="submit" name="term" value="<?= $t ?>" class="px-3 py-1.5 rounded-lg text-sm <?= $view === (string) $t ? 'bg-accent-600 text-white' : 'bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700' ?>">Term <?= $t ?></button>
    <?php endfor; ?>
    <button type="submit" name="term" value="overall" class="px-3 py-1.5 rounded-lg text-sm <?= $isOverall ? 'bg-accent-600 text-white' : 'bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700' ?>">Overall</button>
  </form>
  <button id="download-pdf" type="button" class="px-3 py-1.5 rounded-lg text-sm bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 flex items-center gap-1.5"><?= icon_svg('download', 'w-4 h-4') ?> Download PDF</button>
</div>

<div id="pdf-capture-root">
<div id="pdf-letterhead" class="hidden text-center leading-tight mb-4 text-slate-800">
  <div>Republic of the Philippines</div>
  <div>Department of Education</div>
  <div>Region IV-A CALABARZON</div>
  <div>Division of Biñan City</div>
  <div class="font-semibold">JACOBO Z. GONZALES MEMORIAL NATIONAL HIGH SCHOOL</div>
  <div class="font-semibold mt-1">RANKING — <?= h($section['grade_level'] . ' - ' . $section['section_name']) ?></div>
  <div><?= $isOverall ? 'Overall' : 'Term ' . $view ?> · <?= h($year['year_label'] ?? '') ?></div>
</div>
<div id="pdf-clip-wrap" class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm overflow-hidden max-w-3xl">
  <table class="w-full text-sm">
    <thead class="bg-slate-50 dark:bg-slate-900 text-slate-500 dark:text-slate-400 text-xs uppercase">
      <tr><th class="text-left px-4 py-3">Rank</th><th class="text-left px-4 py-3">Student</th><th class="text-left px-4 py-3">General Average</th><th class="text-left px-4 py-3">Whole Grade</th><th class="text-left px-4 py-3">Honor</th></tr>
    </thead>
    <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
      <?php foreach ($ranking as $r):
          $wholeGrade = grade_whole($r['average']);
          // Academic Excellence eligibility is checked against the rounded whole-number
          // grade, not the raw 3-decimal average — the 3-decimal figure exists purely to
          // break ties fairly for ranking (the LGU voucher), but a grade like 89.667 is
          // meant to round to 90 and qualify, same as it always has on Consolidated
          // Grades/Card Slips; checking the un-rounded value would quietly deny the award to
          // a student who actually made the cutoff.
          $honor = honor_classification($wholeGrade !== null ? (float) $wholeGrade : null);
      ?>
      <tr>
        <td class="px-4 py-3 font-semibold <?= (int) $r['rank_in_section'] <= 3 ? 'text-accent-700 dark:text-accent-300' : 'text-slate-600 dark:text-slate-300' ?>">#<?= (int) $r['rank_in_section'] ?></td>
        <td class="px-4 py-3 font-medium"><?= h($r['full_name']) ?></td>
        <td class="px-4 py-3 <?= $r['average'] !== null ? grade_display_class((float) $r['average']) : '' ?>"><?= h($r['average']) ?></td>
        <td class="px-4 py-3 font-medium <?= $wholeGrade !== null ? grade_display_class((float) $wholeGrade) : '' ?>"><?= $wholeGrade !== null ? h($wholeGrade) : '—' ?></td>
        <td class="px-4 py-3">
          <?php if ($honor): ?>
            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300"><?= icon_svg('star', 'w-3 h-3') ?> <?= h($honor) ?></span>
          <?php else: ?>
            <span class="text-slate-300 dark:text-slate-600">—</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$ranking): ?>
      <tr><td colspan="5" class="px-4 py-6 text-center text-slate-400 dark:text-slate-500"><?= $isOverall
          ? 'Overall ranking will appear once at least 2 of the 3 terms have a General Average for a student.'
          : 'No published subjects yet for this term — ranking will appear once at least one subject is published.' ?></td></tr>
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
  var fileName = <?= json_encode(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $section['grade_level'] . '-' . $section['section_name'])) . '-' . ($isOverall ? 'overall' : 'term' . $view) . '-ranking.pdf') ?>;

  btn.addEventListener('click', async function () {
    var originalLabel = btn.innerHTML;
    btn.disabled = true;
    btn.textContent = 'Generating PDF…';

    var letterhead = document.getElementById('pdf-letterhead');
    var root = document.getElementById('pdf-capture-root');
    var wasHidden = letterhead.classList.contains('hidden');

    try {
      letterhead.classList.remove('hidden');

      var SCALE = 2;
      // Row-boundary-aware page breaks (same approach as Class Record/Consolidated Grades'
      // own PDF export) — a long roster can still run past one page, so this measures each
      // row's position before capture and only breaks between rows, never through one.
      var rowOffsetsCss = Array.prototype.map.call(root.querySelectorAll('tbody tr'), function (tr) {
        return tr.getBoundingClientRect().top - root.getBoundingClientRect().top;
      });

      var canvas = await html2canvas(root, { scale: SCALE, backgroundColor: '#ffffff' });

      var pageWidthMm = 190, pageHeightMm = 277; // A4 portrait minus 10mm margins
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

      var pdf = new window.jspdf.jsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait' });
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
      if (wasHidden) letterhead.classList.add('hidden');
      btn.disabled = false;
      btn.innerHTML = originalLabel;
    }
  });
})();
</script>
<?php render_footer(); ?>
