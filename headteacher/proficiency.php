<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';

$user = require_role(['subject_teacher', 'admin']);
$pdo = db();
$year = require_active_school_year();

$supervisedSubjectIds = get_supervised_subject_ids($user['id'], (int) $year['id']);
$supervisedSubjects = [];
if ($supervisedSubjectIds) {
    $placeholders = implode(',', array_fill(0, count($supervisedSubjectIds), '?'));
    $stmt = $pdo->prepare("SELECT id, subject_name FROM subjects WHERE id IN ($placeholders) ORDER BY subject_name");
    $stmt->execute($supervisedSubjectIds);
    $supervisedSubjects = $stmt->fetchAll();
}

$subjectId = (int) ($_GET['subject_id'] ?? ($supervisedSubjects[0]['id'] ?? 0));
$term = (int) ($_GET['term'] ?? 1);
if ($term < 1 || $term > 3) {
    $term = 1;
}

// 7-band Proficiency Level, per the school's own criteria — transmuted grades are always
// whole numbers (see transmutation_table), so integer-inclusive boundaries are exact.
const PL_BANDS = [
    ['min' => 98, 'label' => 'Outstanding+', 'range' => '98-100'],
    ['min' => 95, 'label' => 'Outstanding', 'range' => '95-97'],
    ['min' => 90, 'label' => 'Very Satisfactory', 'range' => '90-94'],
    ['min' => 85, 'label' => 'Satisfactory', 'range' => '85-89'],
    ['min' => 80, 'label' => 'Fairly Satisfactory', 'range' => '80-84'],
    ['min' => 75, 'label' => 'Did Not Meet Expectations', 'range' => '75-79'],
    ['min' => -1, 'label' => 'Beginning', 'range' => '74 and below'],
];
function pl_band(float $grade): string
{
    foreach (PL_BANDS as $band) {
        if ($grade >= $band['min']) {
            return $band['label'];
        }
    }
    return 'Beginning';
}
$bandLabels = array_column(PL_BANDS, 'label');
$bandRanges = array_combine($bandLabels, array_column(PL_BANDS, 'range'));

$perSection = [];
$perGradeLevel = [];
if ($subjectId && in_array($subjectId, $supervisedSubjectIds, true)) {
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
        ORDER BY sort_order, section_name');
    $stmt->execute([$term, $term, $subjectId, $year['id'], $term, $term, $term, $subjectId, $year['id'], $term]);

    foreach ($stmt->fetchAll() as $row) {
        if ($row['transmuted_grade'] === null) {
            continue;
        }
        $band = pl_band((float) $row['transmuted_grade']);
        $sex = $row['sex'];

        if (!isset($perSection[$row['section_id']])) {
            $perSection[$row['section_id']] = ['name' => $row['grade_level'] . ' - ' . $row['section_name'], 'grade_level_id' => $row['grade_level_id'], 'bands' => array_fill_keys($bandLabels, ['M' => 0, 'F' => 0])];
        }
        $perSection[$row['section_id']]['bands'][$band][$sex]++;

        if (!isset($perGradeLevel[$row['grade_level_id']])) {
            $perGradeLevel[$row['grade_level_id']] = ['name' => $row['grade_level'], 'sort_order' => $row['sort_order'], 'bands' => array_fill_keys($bandLabels, ['M' => 0, 'F' => 0])];
        }
        $perGradeLevel[$row['grade_level_id']]['bands'][$band]['M'] += $sex === 'M' ? 1 : 0;
        $perGradeLevel[$row['grade_level_id']]['bands'][$band]['F'] += $sex === 'F' ? 1 : 0;
    }
}
uasort($perGradeLevel, fn($a, $b) => $a['sort_order'] <=> $b['sort_order']);

render_header('Proficiency Level', 'Distribution of published grades across performance bands, split by sex.');
echo ht_tab_nav('proficiency');
?>
<form method="get" class="flex flex-wrap gap-3 mb-6">
  <select name="subject_id" onchange="this.form.submit()" class="px-3 py-2 text-sm border border-slate-300 rounded-lg">
    <?= select_options($supervisedSubjects, 'id', 'subject_name', $subjectId) ?>
  </select>
  <?php for ($t = 1; $t <= 3; $t++): ?>
    <button type="submit" name="term" value="<?= $t ?>" class="px-3 py-1.5 rounded-lg text-sm <?= $t === $term ? 'bg-accent-600 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">Term <?= $t ?></button>
  <?php endfor; ?>
</form>

<?php if (!$supervisedSubjects): ?>
<div class="bg-white border border-slate-200 rounded-xl shadow-sm px-4 py-8 text-center text-slate-400 text-sm">You don't currently supervise any subject.</div>
<?php elseif (!$perGradeLevel): ?>
<div class="bg-white border border-slate-200 rounded-xl shadow-sm px-4 py-8 text-center text-slate-400 text-sm">No published grades yet for this subject/term.</div>
<?php else: ?>

<h2 class="text-sm font-semibold text-slate-600 mb-3">Grade Level Rollup</h2>
<?php foreach ($perGradeLevel as $glId => $gl): ?>
<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5 mb-6">
  <div class="font-semibold text-slate-800 mb-4"><?= h($gl['name']) ?></div>
  <div class="grid md:grid-cols-2 gap-6">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="text-slate-500 text-xs uppercase">
          <tr><th class="text-left py-2">Band</th><th class="text-left py-2">Grade</th><th class="text-right py-2">Male</th><th class="text-right py-2">Female</th><th class="text-right py-2">Total</th></tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <?php foreach ($gl['bands'] as $band => $counts): ?>
          <tr>
            <td class="py-2"><?= h($band) ?></td>
            <td class="py-2 text-slate-500"><?= h($bandRanges[$band]) ?></td>
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
<?php endforeach; ?>

<h2 class="text-sm font-semibold text-slate-600 mb-3">Per Section</h2>
<div class="grid md:grid-cols-2 gap-4">
  <?php foreach ($perSection as $sec): ?>
  <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
    <div class="font-semibold text-slate-800 mb-3"><?= h($sec['name']) ?></div>
    <table class="w-full text-sm">
      <thead class="text-slate-500 text-xs uppercase">
        <tr><th class="text-left py-1.5">Band</th><th class="text-left py-1.5">Grade</th><th class="text-right py-1.5">M</th><th class="text-right py-1.5">F</th><th class="text-right py-1.5">Total</th></tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        <?php foreach ($sec['bands'] as $band => $counts): ?>
          <?php if ($counts['M'] + $counts['F'] === 0) continue; ?>
        <tr>
          <td class="py-1.5"><?= h($band) ?></td>
          <td class="py-1.5 text-slate-500"><?= h($bandRanges[$band]) ?></td>
          <td class="py-1.5 text-right"><?= $counts['M'] ?></td>
          <td class="py-1.5 text-right"><?= $counts['F'] ?></td>
          <td class="py-1.5 text-right font-medium"><?= $counts['M'] + $counts['F'] ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endforeach; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
<?php $chartLabels = array_map(fn($band) => [$band, '(' . $bandRanges[$band] . ')'], $bandLabels); ?>
<?php foreach ($perGradeLevel as $glId => $gl): ?>
new Chart(document.getElementById('pl-chart-<?= (int) $glId ?>'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($chartLabels) ?>,
    datasets: [
      { label: 'Male', data: <?= json_encode(array_column($gl['bands'], 'M')) ?>, backgroundColor: '#4f46e5' },
      { label: 'Female', data: <?= json_encode(array_column($gl['bands'], 'F')) ?>, backgroundColor: '#f472b6' }
    ]
  },
  options: {
    responsive: true,
    scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } }, x: { ticks: { autoSkip: false, maxRotation: 40, minRotation: 40, font: { size: 10 } } } },
    plugins: { legend: { position: 'bottom' } }
  }
});
<?php endforeach; ?>
</script>
<?php endif; ?>
<?php render_footer(); ?>
