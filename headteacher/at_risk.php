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

    // A supervised MAPEH component (Music-Arts/PE-Health) is mapped up to its merged parent
    // so the parent row (the only one that ever actually carries a grade) passes this scope
    // check — head_teacher_assignments always stores the component ids, never the compound
    // parent's, so without this the merged MAPEH grade could never appear here at all.
    $keepIds = [];
    foreach ($data['subjects'] as $s) {
        if (in_array($s['subject_id'], $supervisedSubjectIds, true)) {
            $keepIds[] = $s['parent_subject_id'] ?? $s['subject_id'];
        }
    }

    // Reasons a teacher recorded for a below-75 grade (see teacher/class_record.php) — keyed
    // by [student_id][subject_id]; for a compound subject the reason lives under whichever
    // COMPONENT the teacher was grading, never the merged parent's own id, so the lookup below
    // resolves through the parent's 'children' list for those rows.
    $studentIds = array_column($data['students'], 'id');
    $reasonsByStudentSubject = [];
    if ($studentIds) {
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $reasonStmt = $pdo->prepare("SELECT student_id, subject_id, reason, reason_other FROM term_grade_fail_reasons WHERE term = ? AND school_year_id = ? AND student_id IN ($placeholders)");
        $reasonStmt->execute(array_merge([$term, (int) $year['id']], $studentIds));
        foreach ($reasonStmt->fetchAll() as $r) {
            $label = FAIL_REASON_LABELS[$r['reason']] ?? $r['reason'];
            if ($r['reason'] === 'other' && $r['reason_other']) {
                $label = $r['reason_other'];
            }
            $reasonsByStudentSubject[(int) $r['student_id']][(int) $r['subject_id']] = $label;
        }
    }

    // Scoped to only the subjects THIS Head Teacher supervises — everything else in the
    // section (other subjects, MAPEH's components) is out of scope for this report.
    $atRisk = [];
    foreach ($data['students'] as $student) {
        foreach ($data['subjects'] as $subject) {
            if ($subject['is_child'] || !in_array($subject['subject_id'], $keepIds, true)) {
                continue;
            }
            $g = $data['gradesByTerm'][$term][$student['id']][$subject['subject_id']] ?? null;
            if ($g === null || (float) $g >= 75) {
                continue;
            }
            $reasonSubjectIds = isset($subject['children']) ? array_column($subject['children'], 'subject_id') : [$subject['subject_id']];
            $reasonTexts = [];
            foreach ($reasonSubjectIds as $rid) {
                if (isset($reasonsByStudentSubject[$student['id']][$rid])) {
                    $reasonTexts[] = $reasonsByStudentSubject[$student['id']][$rid];
                }
            }
            if (!isset($atRisk[$student['id']])) {
                $atRisk[$student['id']] = ['student' => $student, 'subjects' => []];
            }
            $atRisk[$student['id']]['subjects'][] = [
                'name' => $subject['subject_name'],
                'grade' => $g,
                'band' => (float) $g < 70 ? 'Failing' : 'For Remedial',
                'reason' => $reasonTexts ? implode('; ', array_unique($reasonTexts)) : null,
            ];
        }
    }
    $failingSubjectCount = array_sum(array_map(fn($r) => count($r['subjects']), $atRisk));

    render_header($section['grade_level'] . ' - ' . $section['section_name'] . ' · At Risk');
    echo ht_tab_nav('at_risk');
    ?>
    <a href="<?= h(url('/headteacher/at_risk.php')) ?>" class="inline-block mb-4 text-sm text-accent-600 hover:underline">&larr; Back to Sections</a>
    <div class="flex items-center justify-between mb-6">
      <form method="get" class="flex gap-1">
        <input type="hidden" name="section_id" value="<?= $sectionId ?>">
        <?php for ($t = 1; $t <= 3; $t++): ?>
          <button type="submit" name="term" value="<?= $t ?>" class="px-3 py-1.5 rounded-lg text-sm <?= $t === $term ? 'bg-accent-600 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">Term <?= $t ?></button>
        <?php endfor; ?>
      </form>
      <div class="text-sm text-slate-500">
        <span class="font-semibold text-rose-600"><?= count($atRisk) ?></span> student<?= count($atRisk) === 1 ? '' : 's' ?>
        · <span class="font-semibold text-rose-600"><?= $failingSubjectCount ?></span> subject grade<?= $failingSubjectCount === 1 ? '' : 's' ?> below 75
      </div>
    </div>

    <?php if (!$atRisk): ?>
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm px-4 py-8 text-center text-slate-400 text-sm">No students below 75 in your supervised subject(s) for this term.</div>
    <?php else: ?>
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
          <tr><th class="text-left px-4 py-3">Student</th><th class="text-left px-4 py-3">Subject</th><th class="text-left px-4 py-3">Grade</th><th class="text-left px-4 py-3">Status</th><th class="text-left px-4 py-3">Reason</th></tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <?php foreach ($atRisk as $row): ?>
            <?php foreach ($row['subjects'] as $i => $s): ?>
            <tr>
              <td class="px-4 py-3 font-medium <?= $i > 0 ? 'text-slate-300' : '' ?>"><?= $i === 0 ? h($row['student']['full_name']) : '' ?></td>
              <td class="px-4 py-3 text-slate-600"><?= h($s['name']) ?></td>
              <td class="px-4 py-3 <?= grade_display_class((float) $s['grade']) ?>"><?= h($s['grade']) ?></td>
              <td class="px-4 py-3">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $s['band'] === 'Failing' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700' ?>"><?= h($s['band']) ?></span>
              </td>
              <td class="px-4 py-3 text-slate-500 text-xs"><?= $s['reason'] !== null ? h($s['reason']) : '<span class="text-slate-300 italic">Not yet given</span>' ?></td>
            </tr>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <?php render_footer(); ?>
    <?php
    exit;
}

$sections = get_supervised_sections($user['id'], (int) $year['id']);

render_header('At Risk', 'Sections where you supervise at least one subject — click through to see students below 75.');
echo ht_tab_nav('at_risk');
?>
<div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
  <?php foreach ($sections as $sec): ?>
  <a href="<?= h(url('/headteacher/at_risk.php?section_id=' . $sec['id'])) ?>" class="block bg-white border border-slate-200 rounded-xl shadow-sm p-5 hover:border-accent-300 hover:shadow-md transition-shadow">
    <div class="font-semibold text-slate-800"><?= h($sec['grade_level'] . ' - ' . $sec['section_name']) ?></div>
    <div class="text-xs text-slate-400 mt-1"><?= h($year['year_label']) ?></div>
  </a>
  <?php endforeach; ?>
  <?php if (!$sections): ?>
  <div class="text-slate-400 text-sm">No sections found — you don't currently supervise a subject taught anywhere.</div>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
