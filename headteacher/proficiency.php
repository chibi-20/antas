<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';

$user = require_role(['subject_teacher', 'admin']);
$pdo = db();
$year = require_active_school_year();

$supervisedSubjectIds = get_supervised_subject_ids($user['id'], (int) $year['id']);
// A supervised MAPEH component (Music-Arts/PE-Health) offers one merged "MAPEH" option
// instead of two separate ones — head_teacher_assignments always stores the COMPONENT ids
// (see admin/head_teachers.php, which excludes compound parents from the assignable list),
// never the compound parent's, so this has to be resolved here rather than assumed correct.
$supervisedSubjects = [];
if ($supervisedSubjectIds) {
    $placeholders = implode(',', array_fill(0, count($supervisedSubjectIds), '?'));
    $stmt = $pdo->prepare("SELECT id, subject_name, parent_subject_id FROM subjects WHERE id IN ($placeholders)");
    $stmt->execute($supervisedSubjectIds);
    $byId = [];
    $parentIdsNeeded = [];
    foreach ($stmt->fetchAll() as $r) {
        if ($r['parent_subject_id'] !== null) {
            $parentIdsNeeded[(int) $r['parent_subject_id']] = true;
        } else {
            $byId[(int) $r['id']] = $r['subject_name'];
        }
    }
    if ($parentIdsNeeded) {
        $ph = implode(',', array_fill(0, count($parentIdsNeeded), '?'));
        $parentStmt = $pdo->prepare("SELECT id, subject_name FROM subjects WHERE id IN ($ph)");
        $parentStmt->execute(array_keys($parentIdsNeeded));
        foreach ($parentStmt->fetchAll() as $p) {
            $byId[(int) $p['id']] = $p['subject_name'];
        }
    }
    foreach ($byId as $id => $name) {
        $supervisedSubjects[] = ['id' => $id, 'subject_name' => $name];
    }
    usort($supervisedSubjects, fn($a, $b) => strcmp($a['subject_name'], $b['subject_name']));
}
$supervisedSubjectPickerIds = array_column($supervisedSubjects, 'id');

$subjectId = (int) ($_GET['subject_id'] ?? ($supervisedSubjects[0]['id'] ?? 0));
$term = (int) ($_GET['term'] ?? 1);
if ($term < 1 || $term > 3) {
    $term = 1;
}

// Section-level rollup matching the school's own summary-sheet format: rows are sections,
// columns are bands — not the earlier band-rows/Male-Female-columns shape. Uses the same
// five-letter descriptor scale already shown per-grade on Card Slips/Consolidated Grades
// (GRADE_DESCRIPTOR_LEGEND), except Emerging is split further into 60-64 vs a separate
// "At-Risk of Failing" (below 60) column, per the sheet the school actually uses. That split
// exists ONLY on this page — GRADE_DESCRIPTOR_LEGEND itself (and the letter shown everywhere
// else) still treats the whole 0-64 range as one Emerging band, so don't reuse these keys
// elsewhere expecting them to line up.
const PL_SECTION_BANDS = [
    ['min' => 90, 'key' => 'advancing', 'label' => 'Advancing', 'range' => '90-100'],
    ['min' => 80, 'key' => 'benchmarking', 'label' => 'Benchmarking', 'range' => '80-89'],
    ['min' => 75, 'key' => 'connecting', 'label' => 'Connecting', 'range' => '75-79'],
    ['min' => 65, 'key' => 'developing', 'label' => 'Developing', 'range' => '65-74'],
    ['min' => 60, 'key' => 'emerging', 'label' => 'Emerging', 'range' => '60-64'],
    ['min' => -1, 'key' => 'at_risk', 'label' => 'At-Risk of Failing', 'range' => 'Below 60'],
];
function pl_section_band(float $grade): string
{
    foreach (PL_SECTION_BANDS as $band) {
        if ($grade >= $band['min']) {
            return $band['key'];
        }
    }
    return 'at_risk';
}
$bandKeys = array_column(PL_SECTION_BANDS, 'key');
$bandLabels = array_combine($bandKeys, array_column(PL_SECTION_BANDS, 'label'));
$bandRanges = array_combine($bandKeys, array_column(PL_SECTION_BANDS, 'range'));

$perSection = [];
$perGradeLevelChart = [];
if ($subjectId && in_array($subjectId, $supervisedSubjectPickerIds, true)) {
    $childStmt = $pdo->prepare('SELECT id FROM subjects WHERE parent_subject_id = ?');
    $childStmt->execute([$subjectId]);
    $isCompound = (bool) $childStmt->fetchColumn();

    if ($isCompound) {
        // Merged subject (e.g. MAPEH) selected — reuse effective_term_grades directly rather
        // than re-deriving per-component publish-gating here: that view already requires
        // EVERY active component to be published before the merged parent's row appears (see
        // its compound-parent UNION ALL branch, db/migrations/0015_mix_sex_scope.sql), and it
        // already collapses ALL/M/F/MIX scope handling that the per-component query below has
        // to branch on manually.
        $stmt = $pdo->prepare('SELECT sec.id AS section_id, sec.section_name, gl.id AS grade_level_id, gl.name AS grade_level, gl.sort_order,
                st.id AS student_id, st.sex, etg.transmuted_grade
            FROM effective_term_grades etg
            JOIN students st ON st.id = etg.student_id AND st.is_active = 1
            JOIN sections sec ON sec.id = etg.section_id
            JOIN grade_levels gl ON gl.id = sec.grade_level_id
            WHERE etg.subject_id = ? AND etg.term = ? AND etg.school_year_id = ?
            ORDER BY gl.sort_order, sec.section_name');
        $stmt->execute([$subjectId, $term, $year['id']]);
    } else {
        // A subject can have more than one sst row per section now (a different teacher each
        // term, or split by sex — see db/migrations/0011_scoped_teacher_assignments.sql). Without
        // the term_scope/sex_scope predicates on the students join, every student in the section
        // would be joined to every matching sst row — double-counting a student when both an M
        // and F row are published for a term, or counting a student against a grade their own
        // (still-unpublished) teacher hasn't produced.
        // UNION ALL of two plain queries rather than "(sst.sex_scope = 'ALL' OR sst.sex_scope =
        // st.sex)" — that shape (a literal-string comparison OR'd with a column comparison in one
        // expression) can throw "Illegal mix of collations" on some MySQL versions even when every
        // column's stored collation genuinely matches (see db/fix_sex_scope_collation.php). The
        // two branches are mutually exclusive by definition, so UNION ALL needs no de-duplication.
        $stmt = $pdo->prepare('SELECT sec.id AS section_id, sec.section_name, gl.id AS grade_level_id, gl.name AS grade_level, gl.sort_order,
                st.id AS student_id, st.sex, tg.transmuted_grade
            FROM section_subject_teachers sst
            JOIN sections sec ON sec.id = sst.section_id
            JOIN grade_levels gl ON gl.id = sec.grade_level_id
            JOIN students st ON st.section_id = sec.id AND st.is_active = 1
            JOIN submission_status ss ON ss.section_subject_teacher_id = sst.id AND ss.term = ?
            LEFT JOIN term_grades tg ON tg.student_id = st.id AND tg.subject_id = sst.subject_id AND tg.term = ? AND tg.school_year_id = sst.school_year_id
            WHERE sst.subject_id = ? AND sst.school_year_id = ? AND sst.is_active = 1 AND ss.status = "published"
              AND (sst.term_scope = 0 OR sst.term_scope = ?)
              AND sst.sex_scope = "ALL"
              AND (sst.major_id IS NULL OR sst.major_id = st.major_id)
            UNION ALL
            SELECT sec.id AS section_id, sec.section_name, gl.id AS grade_level_id, gl.name AS grade_level, gl.sort_order,
                st.id AS student_id, st.sex, tg.transmuted_grade
            FROM section_subject_teachers sst
            JOIN sections sec ON sec.id = sst.section_id
            JOIN grade_levels gl ON gl.id = sec.grade_level_id
            JOIN students st ON st.section_id = sec.id AND st.is_active = 1 AND sst.sex_scope = st.sex
            JOIN submission_status ss ON ss.section_subject_teacher_id = sst.id AND ss.term = ?
            LEFT JOIN term_grades tg ON tg.student_id = st.id AND tg.subject_id = sst.subject_id AND tg.term = ? AND tg.school_year_id = sst.school_year_id
            WHERE sst.subject_id = ? AND sst.school_year_id = ? AND sst.is_active = 1 AND ss.status = "published"
              AND (sst.term_scope = 0 OR sst.term_scope = ?)
            UNION ALL
            SELECT sec.id AS section_id, sec.section_name, gl.id AS grade_level_id, gl.name AS grade_level, gl.sort_order,
                st.id AS student_id, st.sex, tg.transmuted_grade
            FROM section_subject_teachers sst
            JOIN sections sec ON sec.id = sst.section_id
            JOIN grade_levels gl ON gl.id = sec.grade_level_id
            JOIN sst_student_claims ssc ON ssc.section_subject_teacher_id = sst.id
            JOIN students st ON st.id = ssc.student_id AND st.is_active = 1
            JOIN submission_status ss ON ss.section_subject_teacher_id = sst.id AND ss.term = ?
            LEFT JOIN term_grades tg ON tg.student_id = st.id AND tg.subject_id = sst.subject_id AND tg.term = ? AND tg.school_year_id = sst.school_year_id
            WHERE sst.subject_id = ? AND sst.school_year_id = ? AND sst.is_active = 1 AND ss.status = "published"
              AND (sst.term_scope = 0 OR sst.term_scope = ?)
              AND sst.sex_scope = "MIX"
            ORDER BY sort_order, section_name');
        $stmt->execute([$term, $term, $subjectId, $year['id'], $term, $term, $term, $subjectId, $year['id'], $term, $term, $term, $subjectId, $year['id'], $term]);
    }

    foreach ($stmt->fetchAll() as $row) {
        if (!isset($perSection[$row['section_id']])) {
            $perSection[$row['section_id']] = [
                'name' => $row['section_name'],
                'grade_level_id' => (int) $row['grade_level_id'],
                'grade_level' => $row['grade_level'],
                'sort_order' => (int) $row['sort_order'],
                'bands' => array_fill_keys($bandKeys, 0),
            ];
        }
        if ($row['transmuted_grade'] === null) {
            continue;
        }
        $band = pl_section_band((float) $row['transmuted_grade']);
        $perSection[$row['section_id']]['bands'][$band]++;

        // Grade-level, Male/Female split — feeds only the chart below (the table itself is
        // plain totals per the school's own sheet, no sex breakdown there).
        $glId = (int) $row['grade_level_id'];
        if (!isset($perGradeLevelChart[$glId])) {
            $perGradeLevelChart[$glId] = array_fill_keys($bandKeys, ['M' => 0, 'F' => 0]);
        }
        $perGradeLevelChart[$glId][$band][$row['sex']]++;
    }
}

// Green-to-red across the bands, from "clearing the bar comfortably" to "needs intervention
// now" — deliberately distinct from the app's usual pass/fail red/green (grade_display_class,
// status_badge) so a chart legend color is never mistaken for one of those other meanings.
const PL_BAND_COLORS = [
    'advancing' => '#16a34a',
    'benchmarking' => '#4ade80',
    'connecting' => '#facc15',
    'developing' => '#fb923c',
    'emerging' => '#f87171',
    'at_risk' => '#dc2626',
];

$perGradeLevel = [];
foreach ($perSection as $secId => $sec) {
    $perGradeLevel[$sec['grade_level_id']]['name'] = $sec['grade_level'];
    $perGradeLevel[$sec['grade_level_id']]['sort_order'] = $sec['sort_order'];
    $perGradeLevel[$sec['grade_level_id']]['sections'][$secId] = $sec;
}
uasort($perGradeLevel, fn($a, $b) => $a['sort_order'] <=> $b['sort_order']);
foreach ($perGradeLevel as &$gl) {
    uasort($gl['sections'], fn($a, $b) => strcmp($a['name'], $b['name']));
}
unset($gl);

render_header('Proficiency Level', 'Number of learners per section by proficiency band, matching the school\'s own summary report.');
echo ht_tab_nav('proficiency');
?>
<form method="get" class="flex flex-wrap gap-3 mb-6">
  <select name="subject_id" onchange="this.form.submit()" class="px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 rounded-lg">
    <?= select_options($supervisedSubjects, 'id', 'subject_name', $subjectId) ?>
  </select>
  <?php for ($t = 1; $t <= 3; $t++): ?>
    <button type="submit" name="term" value="<?= $t ?>" class="px-3 py-1.5 rounded-lg text-sm <?= $t === $term ? 'bg-accent-600 text-white' : 'bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700' ?>">Term <?= $t ?></button>
  <?php endfor; ?>
</form>

<?php if (!$supervisedSubjects): ?>
<div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm px-4 py-8 text-center text-slate-400 dark:text-slate-500 text-sm">You don't currently supervise any subject.</div>
<?php elseif (!$perGradeLevel): ?>
<div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm px-4 py-8 text-center text-slate-400 dark:text-slate-500 text-sm">No published grades yet for this subject/term.</div>
<?php else: ?>

<?php foreach ($perGradeLevel as $glId => $gl):
    $glTotals = array_fill_keys($bandKeys, 0);
    foreach ($gl['sections'] as $sec) {
        foreach ($sec['bands'] as $k => $v) {
            $glTotals[$k] += $v;
        }
    }
    $glBySex = $perGradeLevelChart[$glId] ?? array_fill_keys($bandKeys, ['M' => 0, 'F' => 0]);
?>
<div class="font-semibold text-slate-800 dark:text-slate-100 mb-3"><?= h($gl['name']) ?></div>

<h2 class="text-sm font-semibold text-slate-600 dark:text-slate-300 mb-3">Grade Level Rollup</h2>
<div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm p-5 mb-6">
  <div class="grid lg:grid-cols-2 gap-6">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="text-slate-500 dark:text-slate-400 text-xs uppercase">
          <tr><th class="text-left py-2">Band</th><th class="text-left py-2">Grade</th><th class="text-right py-2">Male</th><th class="text-right py-2">Female</th><th class="text-right py-2">Total</th></tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
          <?php foreach ($bandKeys as $k): $counts = $glBySex[$k]; ?>
          <tr>
            <td class="py-2"><?= h($bandLabels[$k]) ?></td>
            <td class="py-2 text-slate-500 dark:text-slate-400"><?= h($bandRanges[$k]) ?></td>
            <td class="py-2 text-right"><?= $counts['M'] ?></td>
            <td class="py-2 text-right"><?= $counts['F'] ?></td>
            <td class="py-2 text-right font-medium"><?= $counts['M'] + $counts['F'] ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div><canvas id="pl-chart-<?= (int) $glId ?>" height="220"></canvas></div>
  </div>
</div>

<h2 class="text-sm font-semibold text-slate-600 dark:text-slate-300 mb-3">By Section</h2>
<div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm p-5 mb-6">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="text-slate-500 dark:text-slate-400 text-xs uppercase">
        <tr>
          <th class="text-left py-2 pr-3">Section</th>
          <?php foreach ($bandKeys as $k): ?>
            <th class="text-center py-2 px-2 whitespace-nowrap"><?= h($bandLabels[$k]) ?><div class="text-[10px] font-normal normal-case text-slate-400 dark:text-slate-500"><?= h($bandRanges[$k]) ?></div></th>
          <?php endforeach; ?>
          <th class="text-right py-2 pl-3">Total</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
        <?php foreach ($gl['sections'] as $sec): $total = array_sum($sec['bands']); ?>
        <tr>
          <td class="py-2 pr-3 font-medium whitespace-nowrap"><?= h($sec['name']) ?></td>
          <?php foreach ($bandKeys as $k): ?>
            <td class="text-center py-2 px-2 <?= $k === 'at_risk' && $sec['bands'][$k] > 0 ? 'text-rose-600 dark:text-rose-400 font-semibold' : '' ?>"><?= $sec['bands'][$k] ?></td>
          <?php endforeach; ?>
          <td class="text-right py-2 pl-3 font-semibold"><?= $total ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="border-t-2 border-slate-200 dark:border-slate-600 font-semibold">
          <td class="py-2 pr-3">Total</td>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
(function () {
  // Chart.js's own default text/grid colors (a mid-grey tuned for a white canvas) go
  // low-contrast against this page's dark-mode cards — set from the theme at load time, and
  // kept in sync afterward since #theme-toggle (assets/js/app.js) flips html.dark instantly
  // without a reload, unlike every Tailwind dark: class here which repaints on its own.
  var isDark = document.documentElement.classList.contains('dark');
  var gridColor = isDark ? 'rgba(148, 163, 184, 0.15)' : 'rgba(100, 116, 139, 0.1)';
  Chart.defaults.color = isDark ? '#94a3b8' : '#64748b';
  Chart.defaults.borderColor = gridColor;

  var plCharts = [];
  <?php $chartLabels = array_map(fn($k) => [$bandLabels[$k], '(' . $bandRanges[$k] . ')'], $bandKeys); ?>
  <?php foreach ($perGradeLevel as $glId => $gl): $chartBands = $perGradeLevelChart[$glId] ?? array_fill_keys($bandKeys, ['M' => 0, 'F' => 0]); ?>
  plCharts.push(new Chart(document.getElementById('pl-chart-<?= (int) $glId ?>'), {
    type: 'bar',
    data: {
      labels: <?= json_encode($chartLabels) ?>,
      datasets: [
        { label: 'Male', data: <?= json_encode(array_values(array_map(fn($k) => $chartBands[$k]['M'], $bandKeys))) ?>, backgroundColor: '#4f46e5' },
        { label: 'Female', data: <?= json_encode(array_values(array_map(fn($k) => $chartBands[$k]['F'], $bandKeys))) ?>, backgroundColor: '#f472b6' }
      ]
    },
    options: {
      responsive: true,
      scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } }, x: { ticks: { autoSkip: false, maxRotation: 40, minRotation: 40, font: { size: 10 } } } },
      plugins: { legend: { position: 'bottom' } }
    }
  }));
  <?php endforeach; ?>

  // Watches html's class attribute directly rather than listening for the #theme-toggle click
  // itself — a click-listener race is real here: this script runs (and so attaches its
  // listener) before assets/js/app.js even loads, so it would fire BEFORE app.js's own click
  // handler actually flips the "dark" class, reading the state that's about to be replaced
  // instead of the new one. Observing the attribute directly sidesteps the ordering question
  // entirely and reacts to the actual change instead of a proxy for it.
  new MutationObserver(function () {
    var nowDark = document.documentElement.classList.contains('dark');
    var nowGrid = nowDark ? 'rgba(148, 163, 184, 0.15)' : 'rgba(100, 116, 139, 0.1)';
    var nowColor = nowDark ? '#94a3b8' : '#64748b';
    plCharts.forEach(function (chart) {
      chart.options.scales.x.ticks.color = nowColor;
      chart.options.scales.y.ticks.color = nowColor;
      chart.options.scales.x.grid.color = nowGrid;
      chart.options.scales.y.grid.color = nowGrid;
      chart.options.plugins.legend.labels.color = nowColor;
      chart.update();
    });
  }).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
})();
</script>
<?php endif; ?>
<?php render_footer(); ?>
