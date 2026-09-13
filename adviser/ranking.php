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

render_header($section['grade_level'] . ' - ' . $section['section_name'] . ' · Ranking');
?>
<form method="get" class="flex gap-1 mb-6">
  <input type="hidden" name="section_id" value="<?= $sectionId ?>">
  <?php for ($t = 1; $t <= 3; $t++): ?>
    <button type="submit" name="term" value="<?= $t ?>" class="px-3 py-1.5 rounded-lg text-sm <?= $view === (string) $t ? 'bg-accent-600 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">Term <?= $t ?></button>
  <?php endfor; ?>
  <button type="submit" name="term" value="overall" class="px-3 py-1.5 rounded-lg text-sm <?= $isOverall ? 'bg-accent-600 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">Overall</button>
</form>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden max-w-2xl">
  <table class="w-full text-sm">
    <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
      <tr><th class="text-left px-4 py-3">Rank</th><th class="text-left px-4 py-3">Student</th><th class="text-left px-4 py-3">General Average</th><th class="text-left px-4 py-3">Honor</th></tr>
    </thead>
    <tbody class="divide-y divide-slate-100">
      <?php foreach ($ranking as $r): $honor = honor_classification($r['average'] !== null ? (float) $r['average'] : null); ?>
      <tr>
        <td class="px-4 py-3 font-semibold <?= (int) $r['rank_in_section'] <= 3 ? 'text-accent-700' : 'text-slate-600' ?>">#<?= (int) $r['rank_in_section'] ?></td>
        <td class="px-4 py-3 font-medium"><?= h($r['full_name']) ?></td>
        <td class="px-4 py-3 <?= $r['average'] !== null ? grade_display_class((float) $r['average']) : '' ?>"><?= h($r['average']) ?></td>
        <td class="px-4 py-3">
          <?php if ($honor): ?>
            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700"><?= icon_svg('star', 'w-3 h-3') ?> <?= h($honor) ?></span>
          <?php else: ?>
            <span class="text-slate-300">—</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$ranking): ?>
      <tr><td colspan="4" class="px-4 py-6 text-center text-slate-400"><?= $isOverall
          ? 'Overall ranking will appear once at least 2 of the 3 terms have a General Average for a student.'
          : 'No published subjects yet for this term — ranking will appear once at least one subject is published.' ?></td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
<?php render_footer(); ?>
