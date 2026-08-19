<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role(['admin']);
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $lrn = trim((string) ($_POST['lrn'] ?? '')) ?: null;
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $sex = (string) ($_POST['sex'] ?? '');
    $birthdate = trim((string) ($_POST['birthdate'] ?? '')) ?: null;
    $sectionId = (int) ($_POST['section_id'] ?? 0);
    $schoolYearId = (int) ($_POST['school_year_id'] ?? 0);
    $err = ($fullName === '' || !in_array($sex, ['M', 'F'], true) || !$sectionId || !$schoolYearId) ? 'Full name, sex, section, and school year are required.' : null;

    if ($action === 'create') {
        if ($err) {
            flash_set('error', $err);
        } else {
            try {
                $pdo->prepare('INSERT INTO students (lrn, full_name, sex, birthdate, section_id, school_year_id) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$lrn, $fullName, $sex, $birthdate, $sectionId, $schoolYearId]);
                flash_set('success', "Student \"$fullName\" added.");
            } catch (PDOException $e) {
                flash_set('error', 'Could not add student — LRN may already be in use.');
            }
        }
    } elseif ($action === 'update') {
        $id = (int) $_POST['id'];
        if ($err) {
            flash_set('error', $err);
        } else {
            try {
                $pdo->prepare('UPDATE students SET lrn=?, full_name=?, sex=?, birthdate=?, section_id=?, school_year_id=? WHERE id=?')
                    ->execute([$lrn, $fullName, $sex, $birthdate, $sectionId, $schoolYearId, $id]);
                flash_set('success', 'Student updated.');
            } catch (PDOException $e) {
                flash_set('error', 'Could not update student — LRN may already be in use.');
            }
        }
    } elseif ($action === 'toggle_active') {
        $id = (int) $_POST['id'];
        $pdo->prepare('UPDATE students SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
        flash_set('success', 'Status updated.');
    }
    redirect('/admin/students.php' . (isset($_POST['section_filter']) ? '?section_id=' . (int) $_POST['section_filter'] : ''));
}

$schoolYears = $pdo->query('SELECT * FROM school_years ORDER BY year_label DESC')->fetchAll();
$sections = $pdo->query('SELECT sec.*, gl.name AS grade_level, sy.year_label FROM sections sec
    JOIN grade_levels gl ON gl.id = sec.grade_level_id
    JOIN school_years sy ON sy.id = sec.school_year_id
    ORDER BY sy.year_label DESC, gl.sort_order, sec.section_name')->fetchAll();
$sectionLabels = array_map(fn($s) => ['id' => $s['id'], 'label' => $s['grade_level'] . ' - ' . $s['section_name'] . ' (' . $s['year_label'] . ')'], $sections);

$filterSectionId = (int) ($_GET['section_id'] ?? 0);
if ($filterSectionId) {
    $stmt = $pdo->prepare('SELECT st.*, gl.name AS grade_level, sec.section_name FROM students st
        JOIN sections sec ON sec.id = st.section_id
        JOIN grade_levels gl ON gl.id = sec.grade_level_id
        WHERE st.section_id = ? ORDER BY st.full_name');
    $stmt->execute([$filterSectionId]);
    $students = $stmt->fetchAll();
} else {
    $students = [];
}

$editing = null;
if (isset($_GET['edit'])) {
    $s = $pdo->prepare('SELECT * FROM students WHERE id = ?');
    $s->execute([(int) $_GET['edit']]);
    $editing = $s->fetch() ?: null;
}

render_header('Students');
?>
<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6 max-w-lg">
  <h2 class="text-sm font-semibold text-slate-600 mb-4"><?= $editing ? 'Edit Student' : 'Add Student' ?></h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
    <input type="hidden" name="section_filter" value="<?= h($filterSectionId) ?>">
    <label class="block text-sm font-medium text-slate-600 mb-1">School year</label>
    <select name="school_year_id" required class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
      <?= select_options($schoolYears, 'id', 'year_label', $editing['school_year_id'] ?? (active_school_year()['id'] ?? null)) ?>
    </select>
    <label class="block text-sm font-medium text-slate-600 mb-1">Section</label>
    <select name="section_id" required class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
      <?= select_options($sectionLabels, 'id', 'label', $editing['section_id'] ?? $filterSectionId ?: null) ?>
    </select>
    <div class="grid grid-cols-2 gap-3 mb-4">
      <div><label class="block text-xs font-medium text-slate-500 mb-1">LRN (optional)</label><input type="text" name="lrn" maxlength="20" value="<?= h($editing['lrn'] ?? '') ?>" class="w-full px-3 py-2 border border-slate-300 rounded-lg"></div>
      <div><label class="block text-xs font-medium text-slate-500 mb-1">Sex</label>
        <select name="sex" required class="w-full px-3 py-2 border border-slate-300 rounded-lg">
          <option value="M" <?= ($editing['sex'] ?? '') === 'M' ? 'selected' : '' ?>>Male</option>
          <option value="F" <?= ($editing['sex'] ?? '') === 'F' ? 'selected' : '' ?>>Female</option>
        </select>
      </div>
    </div>
    <label class="block text-sm font-medium text-slate-600 mb-1">Full name</label>
    <input type="text" name="full_name" required value="<?= h($editing['full_name'] ?? '') ?>" class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
    <label class="block text-sm font-medium text-slate-600 mb-1">Birthdate (optional)</label>
    <input type="date" name="birthdate" value="<?= h($editing['birthdate'] ?? '') ?>" class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
    <div class="flex gap-2">
      <button type="submit" class="bg-accent-600 hover:bg-accent-700 text-white font-medium px-4 py-2 rounded-lg text-sm"><?= $editing ? 'Save Changes' : 'Add' ?></button>
      <?php if ($editing): ?><a href="<?= h(url('/admin/students.php')) ?>" class="px-4 py-2 rounded-lg text-sm text-slate-600 hover:bg-slate-100">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="flex items-center justify-between mb-4">
  <form method="get" class="flex items-center gap-2 text-sm">
    <label class="text-slate-500">Section:</label>
    <select name="section_id" onchange="this.form.submit()" class="px-3 py-2 border border-slate-300 rounded-lg">
      <option value="">Select a section…</option>
      <?= select_options($sectionLabels, 'id', 'label', $filterSectionId ?: null) ?>
    </select>
  </form>
  <a href="<?= h(url('/admin/import_students.php')) ?>" class="px-3 py-1.5 rounded-lg text-sm bg-slate-100 text-slate-600 hover:bg-slate-200">Import Students</a>
</div>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
  <table class="w-full text-sm">
    <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
      <tr><th class="text-left px-4 py-3">LRN</th><th class="text-left px-4 py-3">Name</th><th class="text-left px-4 py-3">Sex</th><th class="text-left px-4 py-3">Status</th><th class="px-4 py-3"></th></tr>
    </thead>
    <tbody class="divide-y divide-slate-100">
      <?php foreach ($students as $st): ?>
      <tr>
        <td class="px-4 py-3 text-slate-600"><?= $st['lrn'] !== null ? h($st['lrn']) : '<span class="text-slate-300">—</span>' ?></td>
        <td class="px-4 py-3 font-medium"><?= h($st['full_name']) ?></td>
        <td class="px-4 py-3 text-slate-600"><?= $st['sex'] === 'M' ? 'Male' : 'Female' ?></td>
        <td class="px-4 py-3"><?= $st['is_active'] ? '<span class="text-emerald-600">Active</span>' : '<span class="text-slate-400">Inactive</span>' ?></td>
        <td class="px-4 py-3 text-right space-x-2">
          <a href="<?= h(url('/admin/students.php?edit=' . $st['id'] . '&section_id=' . $filterSectionId)) ?>" class="text-accent-600 hover:underline">Edit</a>
          <form method="post" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_active">
            <input type="hidden" name="id" value="<?= (int) $st['id'] ?>">
            <input type="hidden" name="section_filter" value="<?= h($filterSectionId) ?>">
            <button type="submit" class="text-slate-500 hover:underline"><?= $st['is_active'] ? 'Deactivate' : 'Activate' ?></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if ($filterSectionId && !$students): ?><tr><td colspan="5" class="px-4 py-6 text-center text-slate-400">No students in this section yet.</td></tr><?php endif; ?>
      <?php if (!$filterSectionId): ?><tr><td colspan="5" class="px-4 py-6 text-center text-slate-400">Select a section above to view its students.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php render_footer(); ?>
