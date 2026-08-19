<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role(['admin']);
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $label = trim((string) ($_POST['year_label'] ?? ''));
        if ($label === '') {
            flash_set('error', 'Year label is required.');
        } else {
            $pdo->prepare('INSERT INTO school_years (year_label) VALUES (?)')->execute([$label]);
            flash_set('success', "School year \"$label\" created.");
        }
    } elseif ($action === 'update') {
        $id = (int) $_POST['id'];
        $label = trim((string) ($_POST['year_label'] ?? ''));
        $pdo->prepare('UPDATE school_years SET year_label = ? WHERE id = ?')->execute([$label, $id]);
        flash_set('success', 'School year updated.');
    } elseif ($action === 'set_active') {
        $id = (int) $_POST['id'];
        $pdo->beginTransaction();
        $pdo->exec('UPDATE school_years SET is_active = 0');
        $pdo->prepare('UPDATE school_years SET is_active = 1 WHERE id = ?')->execute([$id]);
        $pdo->commit();
        flash_set('success', 'Active school year updated.');
    }
    redirect('/admin/school_years.php');
}

$years = $pdo->query('SELECT * FROM school_years ORDER BY year_label DESC')->fetchAll();
$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM school_years WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}

render_header('School Years');
?>
<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6 max-w-lg">
  <h2 class="text-sm font-semibold text-slate-600 mb-4"><?= $editing ? 'Edit School Year' : 'Add School Year' ?></h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
    <label class="block text-sm font-medium text-slate-600 mb-1">Year label (e.g. 2026-2027)</label>
    <input type="text" name="year_label" required value="<?= h($editing['year_label'] ?? '') ?>" class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
    <div class="flex gap-2">
      <button type="submit" class="bg-accent-600 hover:bg-accent-700 text-white font-medium px-4 py-2 rounded-lg text-sm"><?= $editing ? 'Save Changes' : 'Add' ?></button>
      <?php if ($editing): ?><a href="<?= h(url('/admin/school_years.php')) ?>" class="px-4 py-2 rounded-lg text-sm text-slate-600 hover:bg-slate-100">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
  <table class="w-full text-sm">
    <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
      <tr><th class="text-left px-4 py-3">Year</th><th class="text-left px-4 py-3">Status</th><th class="px-4 py-3"></th></tr>
    </thead>
    <tbody class="divide-y divide-slate-100">
      <?php foreach ($years as $year): ?>
      <tr>
        <td class="px-4 py-3 font-medium"><?= h($year['year_label']) ?></td>
        <td class="px-4 py-3"><?= $year['is_active'] ? '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-700">Active</span>' : '<span class="text-slate-400">—</span>' ?></td>
        <td class="px-4 py-3 text-right space-x-2">
          <a href="<?= h(url('/admin/school_years.php?edit=' . $year['id'])) ?>" class="text-accent-600 hover:underline">Edit</a>
          <?php if (!$year['is_active']): ?>
          <form method="post" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set_active">
            <input type="hidden" name="id" value="<?= (int) $year['id'] ?>">
            <button type="submit" class="text-emerald-600 hover:underline">Set Active</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php render_footer(); ?>
