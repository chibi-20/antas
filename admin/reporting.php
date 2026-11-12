<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role(['admin']);
$pdo = db();
$year = require_active_school_year();

$term = (int) ($_GET['term'] ?? 1);
if ($term < 1 || $term > 3) {
    $term = 1;
}

// Both reports read effective_term_grades: published grades only, one row per student per
// top-level subject (MAPEH counted once as its merged grade, never as its two components).
// Grades are compared as the rounded whole number shown on Card Slips/Consolidated Grades, so
// a 74.5 that prints as 75 is not counted as failing.
$failStmt = $pdo->prepare('SELECT etg.student_id, etg.subject_id, st.full_name, st.sex,
        sec.id AS section_id, sec.section_name, gl.id AS grade_level_id, gl.name AS grade_level, gl.sort_order AS gl_sort,
        sub.subject_name, sub.sort_order AS sub_sort, ROUND(etg.transmuted_grade) AS grade
    FROM effective_term_grades etg
    JOIN students st ON st.id = etg.student_id AND st.is_active = 1
    JOIN sections sec ON sec.id = etg.section_id
    JOIN grade_levels gl ON gl.id = sec.grade_level_id
    JOIN subjects sub ON sub.id = etg.subject_id
    WHERE etg.term = ? AND etg.school_year_id = ? AND etg.transmuted_grade IS NOT NULL AND ROUND(etg.transmuted_grade) < 75');
$failStmt->execute([$term, $year['id']]);

$failingByStudent = [];
foreach ($failStmt->fetchAll() as $row) {
    $sid = (int) $row['student_id'];
    $failingByStudent[$sid] ??= [
        'name' => $row['full_name'],
        'sex' => $row['sex'],
        'section_id' => (int) $row['section_id'],
        'section_name' => $row['section_name'],
        'grade_level_id' => (int) $row['grade_level_id'],
        'grade_level' => $row['grade_level'],
        'gl_sort' => (int) $row['gl_sort'],
        'subjects' => [],
    ];
    $failingByStudent[$sid]['subjects'][(int) $row['subject_id']] = [
        'name' => $row['subject_name'],
        'sort' => (int) $row['sub_sort'],
        'grade' => (int) $row['grade'],
    ];
}

$multiFail = [];
$multiFailTotals = ['M' => 0, 'F' => 0];
foreach ($failingByStudent as $student) {
    if (count($student['subjects']) < 2) {
        continue;
    }
    uasort($student['subjects'], fn($a, $b) => $a['sort'] <=> $b['sort'] ?: strcmp($a['name'], $b['name']));
    $glId = $student['grade_level_id'];
    $multiFail[$glId] ??= ['name' => $student['grade_level'], 'sort' => $student['gl_sort'], 'M' => 0, 'F' => 0, 'sections' => []];
    $multiFail[$glId][$student['sex']]++;
    $multiFail[$glId]['sections'][$student['section_id']]['name'] = $student['section_name'];
    $multiFail[$glId]['sections'][$student['section_id']]['students'][] = $student;
    $multiFailTotals[$student['sex']]++;
}
uasort($multiFail, fn($a, $b) => $a['sort'] <=> $b['sort']);
foreach ($multiFail as &$gl) {
    uasort($gl['sections'], fn($a, $b) => strcmp($a['name'], $b['name']));
    foreach ($gl['sections'] as &$sec) {
        usort($sec['students'], fn($a, $b) => strcmp($b['sex'], $a['sex']) ?: strcmp($a['name'], $b['name']));
    }
    unset($sec);
}
unset($gl);

// Population for the failing-rate columns: every active learner enrolled in a section of this
// school year, per grade level and sex. $gradedByGl counts learners with at least one published
// grade this term, so a low rate caused by grades not being published yet is visible.
$popStmt = $pdo->prepare('SELECT gl.id AS grade_level_id, gl.name AS grade_level, gl.sort_order AS gl_sort, st.sex, COUNT(*) AS n
    FROM students st
    JOIN sections sec ON sec.id = st.section_id AND sec.school_year_id = ?
    JOIN grade_levels gl ON gl.id = sec.grade_level_id
    WHERE st.is_active = 1
    GROUP BY gl.id, gl.name, gl.sort_order, st.sex');
$popStmt->execute([$year['id']]);
$gradedStmt = $pdo->prepare('SELECT sec.grade_level_id, COUNT(DISTINCT etg.student_id) AS n
    FROM effective_term_grades etg
    JOIN students st ON st.id = etg.student_id AND st.is_active = 1
    JOIN sections sec ON sec.id = etg.section_id
    WHERE etg.term = ? AND etg.school_year_id = ? AND etg.transmuted_grade IS NOT NULL
    GROUP BY sec.grade_level_id');
$gradedStmt->execute([$term, $year['id']]);
$gradedByGl = array_map('intval', array_column($gradedStmt->fetchAll(), 'n', 'grade_level_id'));

// One row per grade level that has learners, even when nobody there is failing 2+ subjects.
$blankRate = fn(string $name, int $sort, int $glId) => ['name' => $name, 'sort' => $sort, 'pop' => ['M' => 0, 'F' => 0], 'fail' => ['M' => 0, 'F' => 0], 'graded' => $gradedByGl[$glId] ?? 0];
$rateRows = [];
foreach ($popStmt->fetchAll() as $row) {
    $glId = (int) $row['grade_level_id'];
    $rateRows[$glId] ??= $blankRate($row['grade_level'], (int) $row['gl_sort'], $glId);
    if (isset($rateRows[$glId]['pop'][$row['sex']])) {
        $rateRows[$glId]['pop'][$row['sex']] += (int) $row['n'];
    }
}
foreach ($multiFail as $glId => $gl) {
    $rateRows[$glId] ??= $blankRate($gl['name'], $gl['sort'], $glId);
    $rateRows[$glId]['fail'] = ['M' => $gl['M'], 'F' => $gl['F']];
}
uasort($rateRows, fn($a, $b) => $a['sort'] <=> $b['sort']);
$popTotals = ['M' => 0, 'F' => 0];
$gradedTotal = 0;
foreach ($rateRows as $r) {
    $popTotals['M'] += $r['pop']['M'];
    $popTotals['F'] += $r['pop']['F'];
    $gradedTotal += $r['graded'];
}
$pct = fn(int $part, int $whole): ?float => $whole > 0 ? round($part / $whole * 100, 1) : null;
$fmtPct = fn(?float $v): string => $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.') . '%';
$maxRate = 0.0;
foreach ($rateRows as $r) {
    $maxRate = max($maxRate, (float) $pct($r['fail']['M'] + $r['fail']['F'], $r['pop']['M'] + $r['pop']['F']));
}
// Chart scale rounded up to a tidy 10% step.
$chartMax = max(10, (int) ceil($maxRate / 10) * 10);
$pctLabel = fn(float $v): string => rtrim(rtrim(number_format($v, 1), '0'), '.') . '%';

// --- Which subjects drive it: among learners failing 2+ subjects this term, how many are
// failing each subject, per grade level. One learner counts once per subject they fail.
$driverSubjects = [];
foreach ($multiFail as $glId => $gl) {
    foreach ($gl['sections'] as $sec) {
        foreach ($sec['students'] as $student) {
            foreach ($student['subjects'] as $subId => $sub) {
                $driverSubjects[$subId] ??= ['name' => $sub['name'], 'sort' => $sub['sort'], 'byGl' => [], 'total' => 0];
                $driverSubjects[$subId]['byGl'][$glId] = ($driverSubjects[$subId]['byGl'][$glId] ?? 0) + 1;
                $driverSubjects[$subId]['total']++;
            }
        }
    }
}
uasort($driverSubjects, fn($a, $b) => $b['total'] <=> $a['total'] ?: $a['sort'] <=> $b['sort']);
$driverMax = $driverSubjects ? max(array_column($driverSubjects, 'total')) : 0;

// --- By section: failing-2+ rate for every section with enrolled learners, this term.
$secPopStmt = $pdo->prepare('SELECT sec.id, sec.section_name, gl.name AS grade_level, gl.sort_order AS gl_sort, u.full_name AS adviser, COUNT(st.id) AS n
    FROM sections sec
    JOIN grade_levels gl ON gl.id = sec.grade_level_id
    JOIN students st ON st.section_id = sec.id AND st.is_active = 1
    LEFT JOIN users u ON u.id = sec.adviser_id
    WHERE sec.school_year_id = ?
    GROUP BY sec.id, sec.section_name, gl.name, gl.sort_order, u.full_name');
$secPopStmt->execute([$year['id']]);
$sectionRates = [];
foreach ($secPopStmt->fetchAll() as $row) {
    $fails = 0;
    foreach ($multiFail as $gl) {
        $fails += count($gl['sections'][(int) $row['id']]['students'] ?? []);
    }
    $sectionRates[] = [
        'name' => $row['section_name'],
        'grade_level' => $row['grade_level'],
        'gl_sort' => (int) $row['gl_sort'],
        'adviser' => $row['adviser'],
        'pop' => (int) $row['n'],
        'fail' => $fails,
        'rate' => (float) $pct($fails, (int) $row['n']),
    ];
}
usort($sectionRates, fn($a, $b) => $b['rate'] <=> $a['rate'] ?: $b['fail'] <=> $a['fail'] ?: $a['gl_sort'] <=> $b['gl_sort'] ?: strcmp($a['name'], $b['name']));
$sectionMax = $sectionRates ? max(10, (int) ceil(max(array_column($sectionRates, 'rate')) / 10) * 10) : 10;

// --- Term trend: the same failing-2+ rate for Terms 1–3 side by side, per grade level.
// Uses this year's current enrollment as the population for every term. A term with no
// published grades at all shows "—" rather than a misleading 0%.
$trendStmt = $pdo->prepare('SELECT f.term, sec.grade_level_id, COUNT(*) AS n
    FROM (SELECT etg.term, etg.student_id, MIN(etg.section_id) AS section_id
          FROM effective_term_grades etg
          JOIN students st ON st.id = etg.student_id AND st.is_active = 1
          WHERE etg.school_year_id = ? AND etg.transmuted_grade IS NOT NULL AND ROUND(etg.transmuted_grade) < 75
          GROUP BY etg.term, etg.student_id
          HAVING COUNT(*) >= 2) f
    JOIN sections sec ON sec.id = f.section_id
    GROUP BY f.term, sec.grade_level_id');
$trendStmt->execute([$year['id']]);
$trendFail = [];
foreach ($trendStmt->fetchAll() as $row) {
    $trendFail[(int) $row['grade_level_id']][(int) $row['term']] = (int) $row['n'];
}
// Which terms have any published grade, per grade level.
$termsStmt = $pdo->prepare('SELECT DISTINCT sec.grade_level_id, etg.term FROM effective_term_grades etg
    JOIN sections sec ON sec.id = etg.section_id
    WHERE etg.school_year_id = ? AND etg.transmuted_grade IS NOT NULL');
$termsStmt->execute([$year['id']]);
$termsWithGrades = [];
foreach ($termsStmt->fetchAll() as $row) {
    $termsWithGrades[(int) $row['grade_level_id']][(int) $row['term']] = true;
}
$trendRows = [];
$trendMaxRate = 0.0;
foreach ($rateRows as $glId => $r) {
    $pop = $r['pop']['M'] + $r['pop']['F'];
    $rates = [];
    for ($t = 1; $t <= 3; $t++) {
        $rates[$t] = isset($termsWithGrades[$glId][$t]) ? $pct($trendFail[$glId][$t] ?? 0, $pop) : null;
        $trendMaxRate = max($trendMaxRate, (float) $rates[$t]);
    }
    $trendRows[$glId] = ['name' => $r['name'], 'rates' => $rates, 'fail' => $trendFail[$glId] ?? []];
}
$trendMax = max(10, (int) ceil($trendMaxRate / 10) * 10);
// Three ordered shades of one hue: later terms are darker.
$trendShades = [1 => 'bg-rose-200 dark:bg-rose-900', 2 => 'bg-rose-400 dark:bg-rose-600', 3 => 'bg-rose-600 dark:bg-rose-400'];

$plStmt = $pdo->prepare('SELECT gl.id AS grade_level_id, gl.name AS grade_level, gl.sort_order AS gl_sort,
        sub.id AS subject_id, sub.subject_name, sub.sort_order AS sub_sort,
        ROUND(etg.transmuted_grade) AS grade, COUNT(*) AS n
    FROM effective_term_grades etg
    JOIN students st ON st.id = etg.student_id AND st.is_active = 1
    JOIN sections sec ON sec.id = etg.section_id
    JOIN grade_levels gl ON gl.id = sec.grade_level_id
    JOIN subjects sub ON sub.id = etg.subject_id
    WHERE etg.term = ? AND etg.school_year_id = ? AND etg.transmuted_grade IS NOT NULL
    GROUP BY gl.id, gl.name, gl.sort_order, sub.id, sub.subject_name, sub.sort_order, ROUND(etg.transmuted_grade)');
$plStmt->execute([$term, $year['id']]);

$bandKeys = array_column(PL_SECTION_BANDS, 'key');
$bandLabels = array_combine($bandKeys, array_column(PL_SECTION_BANDS, 'label'));
$bandRanges = array_combine($bandKeys, array_column(PL_SECTION_BANDS, 'range'));

$plByGradeLevel = [];
foreach ($plStmt->fetchAll() as $row) {
    $glId = (int) $row['grade_level_id'];
    $subId = (int) $row['subject_id'];
    $plByGradeLevel[$glId] ??= ['name' => $row['grade_level'], 'sort' => (int) $row['gl_sort'], 'subjects' => []];
    $plByGradeLevel[$glId]['subjects'][$subId] ??= [
        'name' => $row['subject_name'],
        'sort' => (int) $row['sub_sort'],
        'bands' => array_fill_keys($bandKeys, 0),
    ];
    $plByGradeLevel[$glId]['subjects'][$subId]['bands'][pl_section_band((float) $row['grade'])] += (int) $row['n'];
}
uasort($plByGradeLevel, fn($a, $b) => $a['sort'] <=> $b['sort']);
foreach ($plByGradeLevel as &$gl) {
    uasort($gl['subjects'], fn($a, $b) => $a['sort'] <=> $b['sort'] ?: strcmp($a['name'], $b['name']));
}
unset($gl);

$multiFailTotal = $multiFailTotals['M'] + $multiFailTotals['F'];

render_header('Reporting', 'School-wide reports from published grades · ' . $year['year_label'] . ' · Term ' . $term . '.');
?>
<form method="get" class="flex gap-1 mb-6">
  <?php for ($t = 1; $t <= 3; $t++): ?>
    <button type="submit" name="term" value="<?= $t ?>" class="px-3 py-1.5 rounded-lg text-sm <?= $t === $term ? 'bg-accent-600 text-white' : 'bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700' ?>">Term <?= $t ?></button>
  <?php endfor; ?>
</form>

<h2 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-1">Students Failing 2 or More Subjects</h2>
<p class="text-xs text-slate-500 dark:text-slate-400 mb-4">Learners with a published grade below 75 in at least two subjects this term. MAPEH counts as one subject.</p>

<?php if (!$rateRows): ?>
<div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm px-4 py-8 text-center text-slate-400 dark:text-slate-500 text-sm mb-10">No enrolled students for this school year yet.</div>
<?php else:
    $allPop = $popTotals['M'] + $popTotals['F'];
?>
<div class="grid gap-4 xl:grid-cols-2 mb-4 items-start">
  <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm p-5 overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="text-slate-500 dark:text-slate-400 text-xs uppercase">
        <tr>
          <th class="text-left py-2 align-bottom" rowspan="2">Grade Level</th>
          <th class="text-center py-1 px-2 border-b border-slate-100 dark:border-slate-700" colspan="2">Male</th>
          <th class="text-center py-1 px-2 border-b border-slate-100 dark:border-slate-700" colspan="2">Female</th>
          <th class="text-center py-1 pl-2 border-b border-slate-100 dark:border-slate-700" colspan="2">Total</th>
        </tr>
        <tr class="text-[10px]">
          <th class="text-right px-2 py-1 font-medium">No.</th><th class="text-right px-2 py-1 font-medium">%</th>
          <th class="text-right px-2 py-1 font-medium">No.</th><th class="text-right px-2 py-1 font-medium">%</th>
          <th class="text-right px-2 py-1 font-medium">No.</th><th class="text-right pl-2 py-1 font-medium">%</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
        <?php foreach ($rateRows as $r): $f = $r['fail']['M'] + $r['fail']['F']; $pop = $r['pop']['M'] + $r['pop']['F']; ?>
        <tr>
          <td class="py-2 font-medium whitespace-nowrap"><?= h($r['name']) ?><div class="text-[11px] font-normal text-slate-400 dark:text-slate-500"><?= number_format($pop) ?> enrolled</div></td>
          <td class="py-2 px-2 text-right whitespace-nowrap"><?= $r['fail']['M'] ?><span class="text-slate-400 dark:text-slate-500 text-xs"> / <?= $r['pop']['M'] ?></span></td>
          <td class="py-2 px-2 text-right text-slate-500 dark:text-slate-400"><?= $fmtPct($pct($r['fail']['M'], $r['pop']['M'])) ?></td>
          <td class="py-2 px-2 text-right whitespace-nowrap"><?= $r['fail']['F'] ?><span class="text-slate-400 dark:text-slate-500 text-xs"> / <?= $r['pop']['F'] ?></span></td>
          <td class="py-2 px-2 text-right text-slate-500 dark:text-slate-400"><?= $fmtPct($pct($r['fail']['F'], $r['pop']['F'])) ?></td>
          <td class="py-2 px-2 text-right font-semibold <?= $f > 0 ? 'text-rose-600 dark:text-rose-400' : '' ?>"><?= $f ?></td>
          <td class="py-2 pl-2 text-right font-semibold <?= $f > 0 ? 'text-rose-600 dark:text-rose-400' : '' ?>"><?= $fmtPct($pct($f, $pop)) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="border-t-2 border-slate-200 dark:border-slate-600 font-semibold">
          <td class="py-2">Total<div class="text-[11px] font-normal text-slate-400 dark:text-slate-500"><?= number_format($allPop) ?> enrolled</div></td>
          <td class="py-2 px-2 text-right"><?= $multiFailTotals['M'] ?></td>
          <td class="py-2 px-2 text-right text-slate-500 dark:text-slate-400"><?= $fmtPct($pct($multiFailTotals['M'], $popTotals['M'])) ?></td>
          <td class="py-2 px-2 text-right"><?= $multiFailTotals['F'] ?></td>
          <td class="py-2 px-2 text-right text-slate-500 dark:text-slate-400"><?= $fmtPct($pct($multiFailTotals['F'], $popTotals['F'])) ?></td>
          <td class="py-2 px-2 text-right <?= $multiFailTotal > 0 ? 'text-rose-600 dark:text-rose-400' : '' ?>"><?= $multiFailTotal ?></td>
          <td class="py-2 pl-2 text-right <?= $multiFailTotal > 0 ? 'text-rose-600 dark:text-rose-400' : '' ?>"><?= $fmtPct($pct($multiFailTotal, $allPop)) ?></td>
        </tr>
      </tfoot>
    </table>
    <p class="mt-3 text-[11px] text-slate-400 dark:text-slate-500">% = students failing 2+ subjects ÷ students enrolled (per grade level and sex). <?= number_format($gradedTotal) ?> of <?= number_format($allPop) ?> enrolled learners have at least one published grade for Term <?= $term ?><?= $gradedTotal < $allPop ? ' — rates can still rise as more grades are published' : '' ?>.</p>
  </div>

  <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm p-5">
    <div class="text-sm font-semibold text-slate-700 dark:text-slate-200">Failing rate by grade level</div>
    <div class="text-xs text-slate-500 dark:text-slate-400 mb-5">Share of enrolled learners failing 2 or more subjects · Term <?= $term ?></div>
    <div class="space-y-3" role="img" aria-label="Bar chart of the failing rate per grade level; the same numbers are in the table.">
      <?php foreach ($rateRows as $r):
          $f = $r['fail']['M'] + $r['fail']['F']; $pop = $r['pop']['M'] + $r['pop']['F'];
          $rate = (float) $pct($f, $pop);
      ?>
      <div class="group relative grid grid-cols-[5.5rem_1fr_3.5rem] items-center gap-3 text-sm outline-none" tabindex="0">
        <div class="font-medium text-slate-600 dark:text-slate-300 whitespace-nowrap"><?= h($r['name']) ?></div>
        <div class="relative h-7">
          <?php for ($g = 0; $g <= 4; $g++): ?><div class="absolute top-0 bottom-0 border-l <?= $g === 0 ? 'border-slate-300 dark:border-slate-600' : 'border-dashed border-slate-100 dark:border-slate-700' ?>" style="left: <?= $g * 25 ?>%"></div><?php endfor; ?>
          <div class="absolute left-0 top-1 bottom-1 rounded-r bg-rose-500 dark:bg-rose-400 group-hover:bg-rose-600 dark:group-hover:bg-rose-300 group-focus:bg-rose-600 transition-colors" style="width: <?= min(100, $rate / $chartMax * 100) ?>%"></div>
        </div>
        <div class="text-right font-semibold text-slate-700 dark:text-slate-200 tabular-nums"><?= $fmtPct($rate) ?></div>
        <div class="pointer-events-none absolute z-10 left-24 top-0 -translate-y-full hidden group-hover:block group-focus:block bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg shadow-lg px-3 py-2 text-xs whitespace-nowrap">
          <div class="font-semibold text-slate-700 dark:text-slate-200 mb-0.5"><?= h($r['name']) ?> · <?= $fmtPct($rate) ?></div>
          <div class="text-slate-600 dark:text-slate-300"><?= $f ?> of <?= number_format($pop) ?> learners failing 2+ subjects</div>
          <div class="text-slate-500 dark:text-slate-400">Male <?= $r['fail']['M'] ?> / <?= $r['pop']['M'] ?> (<?= $fmtPct($pct($r['fail']['M'], $r['pop']['M'])) ?>) · Female <?= $r['fail']['F'] ?> / <?= $r['pop']['F'] ?> (<?= $fmtPct($pct($r['fail']['F'], $r['pop']['F'])) ?>)</div>
        </div>
      </div>
      <?php endforeach; ?>
      <div class="grid grid-cols-[5.5rem_1fr_3.5rem] gap-3 text-[10px] text-slate-400 dark:text-slate-500">
        <div></div>
        <div class="relative h-3">
          <?php for ($g = 0; $g <= 4; $g++): ?><span class="absolute <?= $g === 0 ? '' : ($g === 4 ? '-translate-x-full' : '-translate-x-1/2') ?>" style="left: <?= $g * 25 ?>%"><?= rtrim(rtrim(number_format($chartMax * $g / 4, 1), '0'), '.') ?>%</span><?php endfor; ?>
        </div>
        <div></div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($multiFail): ?>

<div class="space-y-3 mb-10">
  <?php foreach ($multiFail as $gl): ?>
  <details class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm">
    <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-700 dark:text-slate-200"><?= h($gl['name']) ?> — <?= $gl['M'] + $gl['F'] ?> student<?= $gl['M'] + $gl['F'] === 1 ? '' : 's' ?> (view list)</summary>
    <div class="overflow-x-auto border-t border-slate-100 dark:border-slate-700">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 dark:bg-slate-900 text-slate-500 dark:text-slate-400 text-xs uppercase">
          <tr><th class="text-left px-4 py-2">Section</th><th class="text-left px-4 py-2">Student</th><th class="text-center px-4 py-2">Subjects</th><th class="text-left px-4 py-2">Subjects Below 75</th></tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
          <?php foreach ($gl['sections'] as $sec): ?>
            <?php foreach ($sec['students'] as $i => $student): ?>
            <tr>
              <td class="px-4 py-2 whitespace-nowrap <?= $i === 0 ? 'font-medium' : 'text-slate-300 dark:text-slate-600' ?>"><?= $i === 0 ? h($sec['name']) : '' ?></td>
              <td class="px-4 py-2 whitespace-nowrap"><?= h($student['name']) ?></td>
              <td class="px-4 py-2 text-center font-semibold text-rose-600 dark:text-rose-400"><?= count($student['subjects']) ?></td>
              <td class="px-4 py-2 text-slate-600 dark:text-slate-300">
                <?= h(implode(' · ', array_map(fn($s) => $s['name'] . ' (' . $s['grade'] . ')', $student['subjects']))) ?>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </details>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($rateRows): ?>
<!-- Which subjects drive it -->
<h2 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-1">Which Subjects Are They Failing?</h2>
<p class="text-xs text-slate-500 dark:text-slate-400 mb-4">Among the <?= number_format($multiFailTotal) ?> learners failing 2 or more subjects in Term <?= $term ?>, how many are failing each subject. A learner counts once in every subject they fail, so the column adds up to more than the number of learners.</p>
<?php if (!$driverSubjects): ?>
<div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm px-4 py-8 text-center text-slate-400 dark:text-slate-500 text-sm mb-10">No learners are failing 2 or more subjects in Term <?= $term ?>.</div>
<?php else: ?>
<div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm p-5 mb-10 overflow-x-auto">
  <table class="w-full text-sm">
    <thead class="text-slate-500 dark:text-slate-400 text-xs uppercase">
      <tr>
        <th class="text-left py-2 pr-3">Subject</th>
        <?php foreach ($rateRows as $glId => $r): ?><th class="text-right py-2 px-2 whitespace-nowrap"><?= h($r['name']) ?></th><?php endforeach; ?>
        <th class="text-right py-2 px-2">Total</th>
        <th class="text-left py-2 pl-4 w-1/3">Share of these learners</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
      <?php foreach ($driverSubjects as $sub): $share = (float) $pct($sub['total'], $multiFailTotal); ?>
      <tr>
        <td class="py-2 pr-3 font-medium whitespace-nowrap"><?= h($sub['name']) ?></td>
        <?php foreach ($rateRows as $glId => $r): $n = $sub['byGl'][$glId] ?? 0; ?>
          <td class="py-2 px-2 text-right tabular-nums <?= $n ? '' : 'text-slate-300 dark:text-slate-600' ?>"><?= $n ?></td>
        <?php endforeach; ?>
        <td class="py-2 px-2 text-right font-semibold tabular-nums"><?= $sub['total'] ?></td>
        <td class="py-2 pl-4">
          <div class="flex items-center gap-2" title="<?= h($sub['name']) ?>: <?= $sub['total'] ?> of <?= $multiFailTotal ?> learners failing 2+ subjects (<?= $pctLabel($share) ?>)">
            <div class="flex-1 h-4 relative"><div class="absolute left-0 top-0 bottom-0 rounded-r bg-rose-500 dark:bg-rose-400" style="width: <?= $driverMax ? $sub['total'] / $driverMax * 100 : 0 ?>%"></div></div>
            <span class="w-12 text-right text-xs font-semibold text-slate-600 dark:text-slate-300 tabular-nums"><?= $pctLabel($share) ?></span>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- By section -->
<h2 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-1">Failing Rate by Section</h2>
<p class="text-xs text-slate-500 dark:text-slate-400 mb-4">Share of each section's enrolled learners failing 2 or more subjects in Term <?= $term ?>, highest first.</p>
<details class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm mb-10" <?= count($sectionRates) <= 12 ? 'open' : '' ?>>
  <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-700 dark:text-slate-200"><?= count($sectionRates) ?> section<?= count($sectionRates) === 1 ? '' : 's' ?><?= $sectionRates && $sectionRates[0]['fail'] > 0 ? ' — highest: ' . h($sectionRates[0]['grade_level'] . ' - ' . $sectionRates[0]['name']) . ' (' . $pctLabel($sectionRates[0]['rate']) . ')' : '' ?></summary>
  <div class="overflow-x-auto border-t border-slate-100 dark:border-slate-700">
    <table class="w-full text-sm">
      <thead class="bg-slate-50 dark:bg-slate-900 text-slate-500 dark:text-slate-400 text-xs uppercase">
        <tr>
          <th class="text-left px-4 py-2">Section</th>
          <th class="text-left px-4 py-2">Adviser</th>
          <th class="text-right px-4 py-2">Enrolled</th>
          <th class="text-right px-4 py-2">Failing 2+</th>
          <th class="text-left px-4 py-2 w-1/3">Rate</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
        <?php foreach ($sectionRates as $s): ?>
        <tr>
          <td class="px-4 py-2 whitespace-nowrap"><span class="text-slate-400 dark:text-slate-500"><?= h($s['grade_level']) ?> -</span> <span class="font-medium"><?= h($s['name']) ?></span></td>
          <td class="px-4 py-2 whitespace-nowrap text-slate-600 dark:text-slate-300"><?= $s['adviser'] !== null ? h($s['adviser']) : '<span class="text-slate-300 dark:text-slate-600">—</span>' ?></td>
          <td class="px-4 py-2 text-right tabular-nums"><?= $s['pop'] ?></td>
          <td class="px-4 py-2 text-right tabular-nums font-semibold <?= $s['fail'] ? 'text-rose-600 dark:text-rose-400' : 'text-slate-300 dark:text-slate-600' ?>"><?= $s['fail'] ?></td>
          <td class="px-4 py-2">
            <div class="flex items-center gap-2">
              <div class="flex-1 h-4 relative"><div class="absolute left-0 top-0 bottom-0 rounded-r bg-rose-500 dark:bg-rose-400" style="width: <?= min(100, $s['rate'] / $sectionMax * 100) ?>%"></div></div>
              <span class="w-12 text-right text-xs font-semibold text-slate-600 dark:text-slate-300 tabular-nums"><?= $pctLabel($s['rate']) ?></span>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</details>

<!-- Term trend -->
<h2 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-1">Term-over-Term Trend</h2>
<p class="text-xs text-slate-500 dark:text-slate-400 mb-4">Failing-2+ rate per grade level across the three terms, against this year's current enrollment. "—" means no grades are published for that term yet. A falling rate suggests interventions are working.</p>
<div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm p-5 mb-10">
  <div class="flex flex-wrap gap-4 mb-4 text-xs text-slate-600 dark:text-slate-300">
    <?php for ($t = 1; $t <= 3; $t++): ?><span class="flex items-center gap-1.5"><span class="inline-block w-3 h-3 rounded-sm <?= $trendShades[$t] ?>"></span>Term <?= $t ?></span><?php endfor; ?>
  </div>
  <div class="space-y-4">
    <?php foreach ($trendRows as $tr): ?>
    <div class="grid grid-cols-[5.5rem_1fr] gap-3 items-center">
      <div class="text-sm font-medium text-slate-600 dark:text-slate-300 whitespace-nowrap"><?= h($tr['name']) ?></div>
      <div class="space-y-0.5">
        <?php for ($t = 1; $t <= 3; $t++): $v = $tr['rates'][$t]; ?>
        <div class="grid grid-cols-[1fr_6.5rem] gap-2 items-center" title="<?= h($tr['name']) ?> · Term <?= $t ?>: <?= $v === null ? 'no published grades yet' : ($tr['fail'][$t] ?? 0) . ' learners (' . $pctLabel($v) . ')' ?>">
          <div class="relative h-3.5">
            <div class="absolute top-0 bottom-0 left-0 border-l border-slate-300 dark:border-slate-600"></div>
            <?php if ($v !== null): ?><div class="absolute left-0 top-0 bottom-0 rounded-r <?= $trendShades[$t] ?>" style="width: <?= min(100, $v / $trendMax * 100) ?>%"></div><?php endif; ?>
          </div>
          <div class="text-xs tabular-nums text-slate-500 dark:text-slate-400 whitespace-nowrap"><span class="text-slate-400 dark:text-slate-500">T<?= $t ?></span> <span class="font-semibold text-slate-700 dark:text-slate-200"><?= $v === null ? '—' : $pctLabel($v) ?></span><?php
            if ($t > 1 && $v !== null && $tr['rates'][$t - 1] !== null):
                $d = round($v - $tr['rates'][$t - 1], 1);
                if ($d != 0): ?> <span class="<?= $d > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400' ?>"><?= $d > 0 ? '▲' : '▼' ?><?= $pctLabel(abs($d)) ?></span><?php endif;
            endif; ?></div>
        </div>
        <?php endfor; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <p class="mt-4 text-[11px] text-slate-400 dark:text-slate-500">▲ red = rate went up from the previous term, ▼ green = went down (percentage points). Scale 0–<?= $trendMax ?>%.</p>
</div>
<?php endif; ?>

<h2 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-1">Proficiency Level by Subject</h2>
<p class="text-xs text-slate-500 dark:text-slate-400 mb-4">Number of learners per proficiency band, per subject and grade level, from published grades this term.</p>

<?php if (!$plByGradeLevel): ?>
<div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm px-4 py-8 text-center text-slate-400 dark:text-slate-500 text-sm">No published grades yet for Term <?= $term ?>.</div>
<?php else: ?>
<?php foreach ($plByGradeLevel as $gl):
    $glTotals = array_fill_keys($bandKeys, 0);
    foreach ($gl['subjects'] as $sub) {
        foreach ($sub['bands'] as $k => $v) {
            $glTotals[$k] += $v;
        }
    }
?>
<div class="font-semibold text-slate-800 dark:text-slate-100 mb-3"><?= h($gl['name']) ?></div>
<div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm p-5 mb-6">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="text-slate-500 dark:text-slate-400 text-xs uppercase">
        <tr>
          <th class="text-left py-2 pr-3">Subject</th>
          <?php foreach ($bandKeys as $k): ?>
            <th class="text-center py-2 px-2 whitespace-nowrap"><?= h($bandLabels[$k]) ?><div class="text-[10px] font-normal normal-case text-slate-400 dark:text-slate-500"><?= h($bandRanges[$k]) ?></div></th>
          <?php endforeach; ?>
          <th class="text-right py-2 pl-3">Total</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
        <?php foreach ($gl['subjects'] as $sub): ?>
        <tr>
          <td class="py-2 pr-3 font-medium whitespace-nowrap"><?= h($sub['name']) ?></td>
          <?php foreach ($bandKeys as $k): ?>
            <td class="text-center py-2 px-2 <?= $k === 'at_risk' && $sub['bands'][$k] > 0 ? 'text-rose-600 dark:text-rose-400 font-semibold' : '' ?>"><?= $sub['bands'][$k] ?></td>
          <?php endforeach; ?>
          <td class="text-right py-2 pl-3 font-semibold"><?= array_sum($sub['bands']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="border-t-2 border-slate-200 dark:border-slate-600 font-semibold">
          <td class="py-2 pr-3">All Subjects</td>
          <?php foreach ($bandKeys as $k): ?>
            <td class="text-center py-2 px-2 <?= $k === 'at_risk' && $glTotals[$k] > 0 ? 'text-rose-600 dark:text-rose-400' : '' ?>"><?= $glTotals[$k] ?></td>
          <?php endforeach; ?>
          <td class="text-right py-2 pl-3"><?= array_sum($glTotals) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
<?php render_footer(); ?>
