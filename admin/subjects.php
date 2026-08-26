<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role(['admin']);
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $name = trim((string) ($_POST['subject_name'] ?? ''));
    $code = trim((string) ($_POST['subject_code'] ?? ''));
    $weightProfileId = (int) ($_POST['weight_profile_id'] ?? 0);
    $parentSubjectId = (int) ($_POST['parent_subject_id'] ?? 0) ?: null;
    $sortOrder = ($_POST['sort_order'] ?? '') !== '' ? (int) $_POST['sort_order'] : 100;
    $err = ($name === '' || $code === '' || $weightProfileId === 0) ? 'All fields are required.' : null;

    if ($action === 'create') {
        if ($err) {
            flash_set('error', $err);
        } else {
            try {
                $pdo->prepare('INSERT INTO subjects (subject_name, subject_code, weight_profile_id, parent_subject_id, sort_order) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$name, $code, $weightProfileId, $parentSubjectId, $sortOrder]);
                flash_set('success', "Subject \"$name\" created.");
            } catch (PDOException $e) {
                flash_set('error', 'Could not create subject — subject code may already be in use.');
            }
        }
    } elseif ($action === 'update') {
        $id = (int) $_POST['id'];
        if ($parentSubjectId === $id) {
            $err = 'A subject cannot be its own parent.';
        }
        if ($err) {
            flash_set('error', $err);
        } else {
            try {
                $pdo->prepare('UPDATE subjects SET subject_name=?, subject_code=?, weight_profile_id=?, parent_subject_id=?, sort_order=? WHERE id=?')
                    ->execute([$name, $code, $weightProfileId, $parentSubjectId, $sortOrder, $id]);
                flash_set('success', 'Subject updated.');
            } catch (PDOException $e) {
                flash_set('error', 'Could not update subject — subject code may already be in use.');
            }
        }
    } elseif ($action === 'toggle_active') {
        $id = (int) $_POST['id'];
        $pdo->prepare('UPDATE subjects SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
        flash_set('success', 'Status updated.');
    }
    redirect('/admin/subjects.php');
}

$weightProfiles = $pdo->query('SELECT * FROM grade_weight_profiles WHERE is_active = 1 ORDER BY profile_name')->fetchAll();
$subjects = $pdo->query('SELECT sub.*, wp.profile_name, parent.subject_name AS parent_name
    FROM subjects sub
    JOIN grade_weight_profiles wp ON wp.id = sub.weight_profile_id
    LEFT JOIN subjects parent ON parent.id = sub.parent_subject_id
    ORDER BY sub.sort_order, sub.subject_name')->fetchAll();
// Which subject ids are themselves a compound parent (have at least one child) — a subject
// that's part of one compound (e.g. Music-Arts) can't also host children of its own.
$parentIds = $pdo->query('SELECT DISTINCT parent_subject_id FROM subjects WHERE parent_subject_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM subjects WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}

// Eligible "parent subject" options: any other subject that doesn't itself already have a
// parent (no nesting) and isn't the subject being edited.
$parentOptions = array_values(array_filter($subjects, function ($s) use ($editing) {
    if ($s['parent_subject_id'] !== null) {
        return false;
    }
    return !$editing || (int) $s['id'] !== (int) $editing['id'];
}));

render_header('Subjects');
?>
<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6 max-w-lg">
  <h2 class="text-sm font-semibold text-slate-600 mb-4"><?= $editing ? 'Edit Subject' : 'Add Subject' ?></h2>
  <p class="text-xs text-slate-400 mb-4">Subjects apply across every grade level — which grade a subject is actually taught in is set per section under Assignments. For a MAPEH-style compound subject (graded as separate components that merge into one report-card grade), create the merged subject first (e.g. "MAPEH"), then create each component (e.g. "Music-Arts", "PE-Health") with its Parent subject set to it — components are taught, encoded, and reviewed independently; the parent's grade is the average of its components once all are published, and its own weight profile is unused.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
    <label class="block text-sm font-medium text-slate-600 mb-1">Subject name</label>
    <input type="text" name="subject_name" required value="<?= h($editing['subject_name'] ?? '') ?>" class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
    <label class="block text-sm font-medium text-slate-600 mb-1">Subject code</label>
    <input type="text" name="subject_code" required value="<?= h($editing['subject_code'] ?? '') ?>" class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
    <label class="block text-sm font-medium text-slate-600 mb-1">Sort order</label>
    <input type="number" name="sort_order" value="<?= h($editing['sort_order'] ?? 100) ?>" class="w-full mb-1 px-3 py-2 border border-slate-300 rounded-lg">
    <p class="text-xs text-slate-400 mb-4">Controls display order on Consolidated Grades and Card Slips — lower numbers appear first. A compound component's own value is unused; it always displays right after its parent.</p>
    <label class="block text-sm font-medium text-slate-600 mb-1">Weight profile</label>
    <select name="weight_profile_id" required class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
      <option value="">Select a weight profile…</option>
      <?= select_options($weightProfiles, 'id', 'profile_name', $editing['weight_profile_id'] ?? null) ?>
    </select>
    <label class="block text-sm font-medium text-slate-600 mb-1">Parent subject (optional — for MAPEH-style components)</label>
    <select name="parent_subject_id" class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
      <option value="">None — this is a regular subject</option>
      <?= select_options($parentOptions, 'id', 'subject_name', $editing['parent_subject_id'] ?? null) ?>
    </select>
    <div class="flex gap-2">
      <button type="submit" class="bg-accent-600 hover:bg-accent-700 text-white font-medium px-4 py-2 rounded-lg text-sm"><?= $editing ? 'Save Changes' : 'Add' ?></button>
      <?php if ($editing): ?><a href="<?= h(url('/admin/subjects.php')) ?>" class="px-4 py-2 rounded-lg text-sm text-slate-600 hover:bg-slate-100">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
  <table class="w-full text-sm">
    <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
      <tr><th class="text-left px-4 py-3">Subject</th><th class="text-left px-4 py-3">Code</th><th class="text-left px-4 py-3">Order</th><th class="text-left px-4 py-3">Weight Profile</th><th class="text-left px-4 py-3">Status</th><th class="px-4 py-3"></th></tr>
    </thead>
    <tbody class="divide-y divide-slate-100">
      <?php foreach ($subjects as $s): ?>
      <tr>
        <td class="px-4 py-3 font-medium">
          <?= h($s['subject_name']) ?>
          <?php if (in_array($s['id'], $parentIds)): ?>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-violet-100 text-violet-700 ml-1">Compound</span>
          <?php elseif ($s['parent_name']): ?>
            <span class="text-xs text-slate-400 block">Part of <?= h($s['parent_name']) ?></span>
          <?php endif; ?>
        </td>
        <td class="px-4 py-3 text-slate-600"><?= h($s['subject_code']) ?></td>
        <td class="px-4 py-3 text-slate-500"><?= $s['parent_subject_id'] ? '—' : (int) $s['sort_order'] ?></td>
        <td class="px-4 py-3 text-slate-600"><?= h($s['profile_name']) ?></td>
        <td class="px-4 py-3"><?= $s['is_active'] ? '<span class="text-emerald-600">Active</span>' : '<span class="text-slate-400">Inactive</span>' ?></td>
        <td class="px-4 py-3 text-right space-x-2">
          <a href="<?= h(url('/admin/subjects.php?edit=' . $s['id'])) ?>" class="text-accent-600 hover:underline">Edit</a>
          <form method="post" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_active">
            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <button type="submit" class="text-slate-500 hover:underline"><?= $s['is_active'] ? 'Deactivate' : 'Activate' ?></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php render_footer(); ?>
