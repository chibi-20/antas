<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role(['admin']);
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $name = trim((string) ($_POST['name'] ?? ''));
    $sortOrder = (int) ($_POST['sort_order'] ?? 0);
    $err = $name === '' ? 'Name is required.' : null;

    if ($action === 'create') {
        if ($err) {
            flash_set('error', $err);
        } else {
            try {
                $pdo->prepare('INSERT INTO grade_levels (name, sort_order) VALUES (?, ?)')->execute([$name, $sortOrder]);
                flash_set('success', "Grade level \"$name\" created.");
            } catch (PDOException $e) {
                flash_set('error', 'Could not create grade level — name may already be in use.');
            }
        }
    } elseif ($action === 'update') {
        $id = (int) $_POST['id'];
        if ($err) {
            flash_set('error', $err);
        } else {
            try {
                $pdo->prepare('UPDATE grade_levels SET name=?, sort_order=? WHERE id=?')->execute([$name, $sortOrder, $id]);
                flash_set('success', 'Grade level updated.');
            } catch (PDOException $e) {
                flash_set('error', 'Could not update grade level — name may already be in use.');
            }
        }
    } elseif ($action === 'toggle_active') {
        $id = (int) $_POST['id'];
        $pdo->prepare('UPDATE grade_levels SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
        flash_set('success', 'Status updated.');
    }
    redirect('/admin/grade_levels.php');
}

$gradeLevels = $pdo->query('SELECT * FROM grade_levels ORDER BY sort_order, name')->fetchAll();
$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM grade_levels WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}

render_header('Grade Levels');
?>
<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6 max-w-lg">
  <h2 class="text-sm font-semibold text-slate-600 mb-4"><?= $editing ? 'Edit Grade Level' : 'Add Grade Level' ?></h2>
  <p class="text-xs text-slate-400 mb-4">A controlled list keeps section entry consistent across the whole school (or division, if this ever scales that far) instead of free-text like "Grade 7" vs "G7". Sort order controls display order everywhere (0 = first).</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
    <div class="grid grid-cols-3 gap-3 mb-4">
      <div class="col-span-2"><label class="block text-xs font-medium text-slate-500 mb-1">Name</label><input type="text" name="name" required placeholder="Grade 7" value="<?= h($editing['name'] ?? '') ?>" class="w-full px-3 py-2 border border-slate-300 rounded-lg"></div>
      <div><label class="block text-xs font-medium text-slate-500 mb-1">Sort order</label><input type="number" name="sort_order" value="<?= h($editing['sort_order'] ?? (int) ($pdo->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM grade_levels')->fetchColumn())) ?>" class="w-full px-3 py-2 border border-slate-300 rounded-lg"></div>
    </div>
    <div class="flex gap-2">
      <button type="submit" class="bg-accent-600 hover:bg-accent-700 text-white font-medium px-4 py-2 rounded-lg text-sm"><?= $editing ? 'Save Changes' : 'Add' ?></button>
      <?php if ($editing): ?><a href="<?= h(url('/admin/grade_levels.php')) ?>" class="px-4 py-2 rounded-lg text-sm text-slate-600 hover:bg-slate-100">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
  <table class="w-full text-sm">
    <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
      <tr><th class="text-left px-4 py-3">Name</th><th class="text-left px-4 py-3">Sort Order</th><th class="text-left px-4 py-3">Status</th><th class="px-4 py-3"></th></tr>
    </thead>
    <tbody class="divide-y divide-slate-100">
      <?php foreach ($gradeLevels as $gl): ?>
      <tr>
        <td class="px-4 py-3 font-medium"><?= h($gl['name']) ?></td>
        <td class="px-4 py-3 text-slate-600"><?= (int) $gl['sort_order'] ?></td>
        <td class="px-4 py-3"><?= $gl['is_active'] ? '<span class="text-emerald-600">Active</span>' : '<span class="text-slate-400">Inactive</span>' ?></td>
        <td class="px-4 py-3 text-right space-x-2">
          <a href="<?= h(url('/admin/grade_levels.php?edit=' . $gl['id'])) ?>" class="text-accent-600 hover:underline">Edit</a>
          <form method="post" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_active">
            <input type="hidden" name="id" value="<?= (int) $gl['id'] ?>">
            <button type="submit" class="text-slate-500 hover:underline"><?= $gl['is_active'] ? 'Deactivate' : 'Activate' ?></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php render_footer(); ?>
