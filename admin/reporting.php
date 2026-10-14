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

<?php if (!$multiFail): ?>
<div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm px-4 py-8 text-center text-slate-400 dark:text-slate-500 text-sm mb-10">No students are failing 2 or more subjects for Term <?= $term ?>.</div>
<?php else: ?>
<div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm p-5 mb-4 max-w-2xl">
  <table class="w-full text-sm">
    <thead class="text-slate-500 dark:text-slate-400 text-xs uppercase">
      <tr><th class="text-left py-2">Grade Level</th><th class="text-right py-2">Male</th><th class="text-right py-2">Female</th><th class="text-right py-2">Total</th></tr>
    </thead>
    <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
      <?php foreach ($multiFail as $gl): ?>
      <tr>
        <td class="py-2 font-medium"><?= h($gl['name']) ?></td>
        <td class="py-2 text-right"><?= $gl['M'] ?></td>
        <td class="py-2 text-right"><?= $gl['F'] ?></td>
        <td class="py-2 text-right font-semibold text-rose-600 dark:text-rose-400"><?= $gl['M'] + $gl['F'] ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr class="border-t-2 border-slate-200 dark:border-slate-600 font-semibold">
        <td class="py-2">Total</td>
        <td class="py-2 text-right"><?= $multiFailTotals['M'] ?></td>
        <td class="py-2 text-right"><?= $multiFailTotals['F'] ?></td>
        <td class="py-2 text-right text-rose-600 dark:text-rose-400"><?= $multiFailTotal ?></td>
      </tr>
    </tfoot>
  </table>
</div>

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
