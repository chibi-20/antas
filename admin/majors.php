<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role(['admin']);
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $name = trim((string) ($_POST['major_name'] ?? ''));
    $err = $name === '' ? 'Name is required.' : null;

    if ($action === 'create') {
        if ($err) {
            flash_set('error', $err);
        } else {
            try {
                $pdo->prepare('INSERT INTO majors (major_name) VALUES (?)')->execute([$name]);
                flash_set('success', "Major \"$name\" created.");
            } catch (PDOException $e) {
                flash_set('error', 'Could not create major — name may already be in use.');
            }
        }
    } elseif ($action === 'update') {
        $id = (int) $_POST['id'];
        if ($err) {
            flash_set('error', $err);
        } else {
            try {
                $pdo->prepare('UPDATE majors SET major_name=? WHERE id=?')->execute([$name, $id]);
                flash_set('success', 'Major updated.');
            } catch (PDOException $e) {
                flash_set('error', 'Could not update major — name may already be in use.');
            }
        }
    } elseif ($action === 'toggle_active') {
        $id = (int) $_POST['id'];
        $pdo->prepare('UPDATE majors SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
        flash_set('success', 'Status updated.');
    }
    redirect('/admin/majors.php');
}

$majors = $pdo->query('SELECT * FROM majors ORDER BY major_name')->fetchAll();
$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM majors WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}

render_header('Majors');
?>
<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6 max-w-lg">
  <h2 class="text-sm font-semibold text-slate-600 mb-4"><?= $editing ? 'Edit Major' : 'Add Major' ?></h2>
  <p class="text-xs text-slate-400 mb-4">Majors are for Special Program sections (e.g. Special Program for the Arts, Special Program for Journalism) — tag a section as "Special Program" on the Sections page, then give each of its students one of these majors on the Students page.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
    <label class="block text-sm font-medium text-slate-600 mb-1">Name</label>
    <input type="text" name="major_name" required placeholder="Visual Arts" value="<?= h($editing['major_name'] ?? '') ?>" class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
    <div class="flex gap-2">
      <button type="submit" class="bg-accent-600 hover:bg-accent-700 text-white font-medium px-4 py-2 rounded-lg text-sm"><?= $editing ? 'Save Changes' : 'Add' ?></button>
      <?php if ($editing): ?><a href="<?= h(url('/admin/majors.php')) ?>" class="px-4 py-2 rounded-lg text-sm text-slate-600 hover:bg-slate-100">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
  <table class="w-full text-sm">
    <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
      <tr><th class="text-left px-4 py-3">Name</th><th class="text-left px-4 py-3">Status</th><th class="px-4 py-3"></th></tr>
    </thead>
    <tbody class="divide-y divide-slate-100">
      <?php foreach ($majors as $m): ?>
      <tr>
        <td class="px-4 py-3 font-medium"><?= h($m['major_name']) ?></td>
        <td class="px-4 py-3"><?= $m['is_active'] ? '<span class="text-emerald-600">Active</span>' : '<span class="text-slate-400">Inactive</span>' ?></td>
        <td class="px-4 py-3 text-right space-x-2">
          <a href="<?= h(url('/admin/majors.php?edit=' . $m['id'])) ?>" class="text-accent-600 hover:underline">Edit</a>
          <form method="post" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_active">
            <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
            <button type="submit" class="text-slate-500 hover:underline"><?= $m['is_active'] ? 'Deactivate' : 'Activate' ?></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$majors): ?><tr><td colspan="3" class="px-4 py-6 text-center text-slate-400">No majors yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php render_footer(); ?>
