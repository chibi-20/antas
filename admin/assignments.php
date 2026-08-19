<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role(['admin']);
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $schoolYearId = (int) ($_POST['school_year_id'] ?? 0);
    $sectionId = (int) ($_POST['section_id'] ?? 0);
    $subjectId = (int) ($_POST['subject_id'] ?? 0);
    $teacherId = (int) ($_POST['teacher_id'] ?? 0);
    $err = (!$schoolYearId || !$sectionId || !$subjectId || !$teacherId) ? 'All fields are required.' : null;

    if ($action === 'create') {
        if ($err) {
            flash_set('error', $err);
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO section_subject_teachers (section_id, subject_id, teacher_id, school_year_id) VALUES (?, ?, ?, ?)')
                    ->execute([$sectionId, $subjectId, $teacherId, $schoolYearId]);
                $sstId = (int) $pdo->lastInsertId();
                $stmt = $pdo->prepare('INSERT INTO submission_status (section_subject_teacher_id, term, status) VALUES (?, ?, ?)');
                for ($t = 1; $t <= 3; $t++) {
                    $stmt->execute([$sstId, $t, 'not_started']);
                }
                $pdo->commit();
                flash_set('success', 'Assignment created — submission tracking initialized for all 3 terms.');
            } catch (PDOException $e) {
                $pdo->rollBack();
                flash_set('error', 'Could not create assignment — this section/subject/school year combination may already exist.');
            }
        }
    } elseif ($action === 'update') {
        $id = (int) $_POST['id'];
        if ($err) {
            flash_set('error', $err);
        } else {
            $pdo->prepare('UPDATE section_subject_teachers SET section_id=?, subject_id=?, teacher_id=?, school_year_id=? WHERE id=?')
                ->execute([$sectionId, $subjectId, $teacherId, $schoolYearId, $id]);
            flash_set('success', 'Assignment updated.');
        }
    } elseif ($action === 'toggle_active') {
        $id = (int) $_POST['id'];
        $pdo->prepare('UPDATE section_subject_teachers SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
        flash_set('success', 'Status updated.');
    }
    redirect('/admin/assignments.php');
}

$schoolYears = $pdo->query('SELECT * FROM school_years ORDER BY year_label DESC')->fetchAll();
$sections = $pdo->query('SELECT sec.*, gl.name AS grade_level, gl.sort_order, sy.year_label FROM sections sec
    JOIN grade_levels gl ON gl.id = sec.grade_level_id
    JOIN school_years sy ON sy.id = sec.school_year_id
    ORDER BY sy.year_label DESC, gl.sort_order, sec.section_name')->fetchAll();
// Compound parent subjects (e.g. MAPEH) are never directly assigned to a teacher — only
// their components (e.g. Music-Arts, PE-Health) are. See admin/subjects.php.
$subjects = $pdo->query("SELECT * FROM subjects WHERE is_active = 1
    AND id NOT IN (SELECT DISTINCT parent_subject_id FROM subjects WHERE parent_subject_id IS NOT NULL)
    ORDER BY subject_name")->fetchAll();
$teachers = $pdo->query("SELECT * FROM users WHERE role = 'subject_teacher' AND is_active = 1 ORDER BY full_name")->fetchAll();
$sectionLabels = array_map(fn($s) => ['id' => $s['id'], 'label' => $s['grade_level'] . ' - ' . $s['section_name'] . ' (' . $s['year_label'] . ')'], $sections);

$assignments = $pdo->query('SELECT sst.*, gl.name AS grade_level, sec.section_name, sub.subject_name, u.full_name AS teacher_name, sy.year_label
    FROM section_subject_teachers sst
    JOIN sections sec ON sec.id = sst.section_id
    JOIN grade_levels gl ON gl.id = sec.grade_level_id
    JOIN subjects sub ON sub.id = sst.subject_id
    JOIN users u ON u.id = sst.teacher_id
    JOIN school_years sy ON sy.id = sst.school_year_id
    ORDER BY sy.year_label DESC, gl.sort_order, sec.section_name, sub.subject_name')->fetchAll();

$editing = null;
if (isset($_GET['edit'])) {
    $s = $pdo->prepare('SELECT * FROM section_subject_teachers WHERE id = ?');
    $s->execute([(int) $_GET['edit']]);
    $editing = $s->fetch() ?: null;
}

render_header('Subject Assignments');
?>
<div class="flex justify-end mb-4">
  <a href="<?= h(url('/admin/bulk_assign.php')) ?>" class="px-3 py-1.5 rounded-lg text-sm bg-slate-100 text-slate-600 hover:bg-slate-200">Bulk Assign</a>
</div>
<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6 max-w-lg">
  <h2 class="text-sm font-semibold text-slate-600 mb-4"><?= $editing ? 'Edit Assignment' : 'Add Assignment' ?></h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
    <label class="block text-sm font-medium text-slate-600 mb-1">School year</label>
    <select name="school_year_id" required class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
      <?= select_options($schoolYears, 'id', 'year_label', $editing['school_year_id'] ?? (active_school_year()['id'] ?? null)) ?>
    </select>
    <label class="block text-sm font-medium text-slate-600 mb-1">Section</label>
    <select name="section_id" required class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
      <?= select_options($sectionLabels, 'id', 'label', $editing['section_id'] ?? null) ?>
    </select>
    <label class="block text-sm font-medium text-slate-600 mb-1">Subject</label>
    <select name="subject_id" required class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
      <?= select_options($subjects, 'id', 'subject_name', $editing['subject_id'] ?? null) ?>
    </select>
    <label class="block text-sm font-medium text-slate-600 mb-1">Teacher</label>
    <select name="teacher_id" required class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg js-searchable" data-placeholder="Search teachers…">
      <?= select_options($teachers, 'id', 'full_name', $editing['teacher_id'] ?? null) ?>
    </select>
    <div class="flex gap-2">
      <button type="submit" class="bg-accent-600 hover:bg-accent-700 text-white font-medium px-4 py-2 rounded-lg text-sm"><?= $editing ? 'Save Changes' : 'Add' ?></button>
      <?php if ($editing): ?><a href="<?= h(url('/admin/assignments.php')) ?>" class="px-4 py-2 rounded-lg text-sm text-slate-600 hover:bg-slate-100">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
  <table class="w-full text-sm">
    <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
      <tr><th class="text-left px-4 py-3">Year</th><th class="text-left px-4 py-3">Section</th><th class="text-left px-4 py-3">Subject</th><th class="text-left px-4 py-3">Teacher</th><th class="text-left px-4 py-3">Status</th><th class="px-4 py-3"></th></tr>
    </thead>
    <tbody class="divide-y divide-slate-100">
      <?php foreach ($assignments as $a): ?>
      <tr>
        <td class="px-4 py-3 text-slate-500"><?= h($a['year_label']) ?></td>
        <td class="px-4 py-3 text-slate-600"><?= h($a['grade_level'] . ' - ' . $a['section_name']) ?></td>
        <td class="px-4 py-3 font-medium"><?= h($a['subject_name']) ?></td>
        <td class="px-4 py-3 text-slate-600"><?= h($a['teacher_name']) ?></td>
        <td class="px-4 py-3"><?= $a['is_active'] ? '<span class="text-emerald-600">Active</span>' : '<span class="text-slate-400">Inactive</span>' ?></td>
        <td class="px-4 py-3 text-right space-x-2">
          <a href="<?= h(url('/admin/assignments.php?edit=' . $a['id'])) ?>" class="text-accent-600 hover:underline">Edit</a>
          <form method="post" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_active">
            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
            <button type="submit" class="text-slate-500 hover:underline"><?= $a['is_active'] ? 'Deactivate' : 'Activate' ?></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php render_footer(); ?>
