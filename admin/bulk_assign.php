<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role(['admin']);
$pdo = db();

$results = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $schoolYearId = (int) ($_POST['school_year_id'] ?? 0);
    $teacherId = (int) ($_POST['teacher_id'] ?? 0);
    $subjectId = (int) ($_POST['subject_id'] ?? 0);
    $sectionIds = array_map('intval', $_POST['sections'] ?? []);

    if (!$schoolYearId || !$teacherId || !$subjectId || !$sectionIds) {
        flash_set('error', 'School year, teacher, subject, and at least one section are required.');
        redirect('/admin/bulk_assign.php');
    }

    $created = 0;
    $skipped = [];
    $sectionLabelStmt = $pdo->prepare('SELECT gl.name AS grade_level, sec.section_name FROM sections sec JOIN grade_levels gl ON gl.id = sec.grade_level_id WHERE sec.id = ?');
    $insertSst = $pdo->prepare('INSERT INTO section_subject_teachers (section_id, subject_id, teacher_id, school_year_id) VALUES (?, ?, ?, ?)');
    $insertStatus = $pdo->prepare('INSERT INTO submission_status (section_subject_teacher_id, term, status) VALUES (?, ?, ?)');

    foreach ($sectionIds as $sectionId) {
        $sectionLabelStmt->execute([$sectionId]);
        $sec = $sectionLabelStmt->fetch();
        $label = $sec ? $sec['grade_level'] . ' - ' . $sec['section_name'] : "section #$sectionId";

        // Bulk Assign only ever creates whole-year/all-students rows — but a section may
        // already have TLE-style per-term or per-sex rows for this subject, which the DB's
        // unique key alone can't catch (different term_scope/sex_scope tuple, not a literal
        // duplicate). Same conflict check admin/assignments.php uses for one-off assignments.
        $conflict = sst_scope_conflict($pdo, $sectionId, $subjectId, $schoolYearId, 0, 'ALL');
        if ($conflict) {
            $skipped[] = "$label — $conflict";
            continue;
        }

        try {
            $pdo->beginTransaction();
            $insertSst->execute([$sectionId, $subjectId, $teacherId, $schoolYearId]);
            $sstId = (int) $pdo->lastInsertId();
            for ($t = 1; $t <= 3; $t++) {
                $insertStatus->execute([$sstId, $t, 'not_started']);
            }
            $pdo->commit();
            $created++;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $skipped[] = "$label — assignment already exists for this subject/school year.";
        }
    }

    $results = ['created' => $created, 'skipped' => $skipped];
    flash_set('success', "$created assignment(s) created." . ($skipped ? ' ' . count($skipped) . ' skipped — see details below.' : ''));
}

$schoolYears = $pdo->query('SELECT * FROM school_years ORDER BY year_label DESC')->fetchAll();
$teachers = $pdo->query("SELECT * FROM users WHERE role = 'subject_teacher' AND is_active = 1 ORDER BY full_name")->fetchAll();
// Compound parent subjects (e.g. MAPEH) are never directly assigned to a teacher — only
// their components (e.g. Music-Arts, PE-Health) are. See admin/subjects.php.
$subjects = $pdo->query("SELECT * FROM subjects WHERE is_active = 1
    AND id NOT IN (SELECT DISTINCT parent_subject_id FROM subjects WHERE parent_subject_id IS NOT NULL)
    ORDER BY subject_name")->fetchAll();

$filterYearId = (int) ($_POST['school_year_id'] ?? $_GET['school_year_id'] ?? default_school_year_id($schoolYears) ?? 0);
$sectionsStmt = $pdo->prepare('SELECT sec.*, gl.name AS grade_level FROM sections sec
    JOIN grade_levels gl ON gl.id = sec.grade_level_id
    WHERE sec.school_year_id = ? AND sec.is_active = 1 ORDER BY gl.sort_order, sec.section_name');
$sectionsStmt->execute([$filterYearId]);
$sections = $sectionsStmt->fetchAll();
$sectionsByGrade = [];
foreach ($sections as $sec) {
    $sectionsByGrade[$sec['grade_level']][] = $sec;
}

render_header('Bulk Assign');
?>
<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6 max-w-2xl">
  <h2 class="text-sm font-semibold text-slate-600 mb-2">Assign one teacher to many sections at once</h2>
  <p class="text-xs text-slate-400 mb-4">Pick a teacher and subject, then check every section they teach it in — including across different grade levels. Each checked section becomes its own assignment with submission tracking for all 3 terms, same as adding one at a time.</p>
  <form method="post">
    <?= csrf_field() ?>
    <div class="grid grid-cols-3 gap-3 mb-4">
      <div>
        <label class="block text-xs font-medium text-slate-500 mb-1">School year</label>
        <select name="school_year_id" onchange="this.form.submit()" required class="w-full px-3 py-2 border border-slate-300 rounded-lg">
          <?= select_options($schoolYears, 'id', 'year_label', $filterYearId) ?>
        </select>
      </div>
      <div>
        <label class="block text-xs font-medium text-slate-500 mb-1">Teacher</label>
        <select name="teacher_id" required class="w-full px-3 py-2 border border-slate-300 rounded-lg js-searchable" data-placeholder="Search teachers…">
          <option value="">Select…</option>
          <?= select_options($teachers, 'id', 'full_name', null) ?>
        </select>
      </div>
      <div>
        <label class="block text-xs font-medium text-slate-500 mb-1">Subject</label>
        <select name="subject_id" required class="w-full px-3 py-2 border border-slate-300 rounded-lg">
          <option value="">Select…</option>
          <?= select_options($subjects, 'id', 'subject_name', null) ?>
        </select>
      </div>
    </div>

    <label class="block text-sm font-medium text-slate-600 mb-2">Sections</label>
    <?php if (!$sectionsByGrade): ?>
      <p class="text-sm text-slate-400 mb-4">No active sections for this school year yet.</p>
    <?php endif; ?>
    <?php foreach ($sectionsByGrade as $grade => $secs): ?>
      <div class="mb-3">
        <div class="text-xs font-semibold text-slate-500 mb-1"><?= h($grade) ?></div>
        <div class="flex flex-wrap gap-3">
          <?php foreach ($secs as $sec): ?>
            <label class="inline-flex items-center gap-1.5 text-sm bg-slate-50 border border-slate-200 rounded-lg px-3 py-1.5">
              <input type="checkbox" name="sections[]" value="<?= (int) $sec['id'] ?>">
              <?= h($sec['section_name']) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <div class="flex gap-2 mt-4">
      <button type="submit" class="bg-accent-600 hover:bg-accent-700 text-white font-medium px-4 py-2 rounded-lg text-sm">Create Assignments</button>
      <a href="<?= h(url('/admin/assignments.php')) ?>" class="px-4 py-2 rounded-lg text-sm text-slate-600 hover:bg-slate-100">Back to Assignments</a>
    </div>
  </form>
</div>

<?php if ($results && $results['skipped']): ?>
<div class="bg-white border border-amber-200 rounded-xl shadow-sm p-6 max-w-2xl">
  <h2 class="text-sm font-semibold text-amber-700 mb-3">Skipped (<?= count($results['skipped']) ?>)</h2>
  <ul class="text-sm text-slate-600 space-y-1 list-disc list-inside">
    <?php foreach ($results['skipped'] as $reason): ?>
      <li><?= h($reason) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>
<?php render_footer(); ?>
