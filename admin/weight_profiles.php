<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role(['admin']);
$pdo = db();

function validate_weights(int $ww, int $pt, int $ex): ?string
{
    if ($ww < 0 || $pt < 0 || $ex < 0) return 'Percentages cannot be negative.';
    if ($ww + $pt + $ex !== 100) return 'Written Work + Performance Task + Examinations must total exactly 100.';
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $name = trim((string) ($_POST['profile_name'] ?? ''));
    $ww = (int) ($_POST['written_work_pct'] ?? 0);
    $pt = (int) ($_POST['performance_task_pct'] ?? 0);
    $ex = (int) ($_POST['examination_pct'] ?? 0);
    $err = $name === '' ? 'Profile name is required.' : validate_weights($ww, $pt, $ex);

    if ($action === 'create') {
        if ($err) {
            flash_set('error', $err);
        } else {
            $pdo->prepare('INSERT INTO grade_weight_profiles (profile_name, written_work_pct, performance_task_pct, examination_pct) VALUES (?, ?, ?, ?)')
                ->execute([$name, $ww, $pt, $ex]);
            flash_set('success', "Weight profile \"$name\" created.");
        }
    } elseif ($action === 'update') {
        $id = (int) $_POST['id'];
        if ($err) {
            flash_set('error', $err);
        } else {
            $pdo->prepare('UPDATE grade_weight_profiles SET profile_name=?, written_work_pct=?, performance_task_pct=?, examination_pct=? WHERE id=?')
                ->execute([$name, $ww, $pt, $ex, $id]);
            flash_set('success', 'Weight profile updated.');
        }
    } elseif ($action === 'toggle_active') {
        $id = (int) $_POST['id'];
        $pdo->prepare('UPDATE grade_weight_profiles SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
        flash_set('success', 'Status updated.');
    }
    redirect('/admin/weight_profiles.php');
}

$profiles = $pdo->query('SELECT * FROM grade_weight_profiles ORDER BY profile_name')->fetchAll();
$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM grade_weight_profiles WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}

render_header('Grade Weight Profiles');
?>
<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6 max-w-lg">
  <h2 class="text-sm font-semibold text-slate-600 mb-4"><?= $editing ? 'Edit Weight Profile' : 'Add Weight Profile' ?></h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
    <label class="block text-sm font-medium text-slate-600 mb-1">Profile name</label>
    <input type="text" name="profile_name" required value="<?= h($editing['profile_name'] ?? '') ?>" class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
    <div class="grid grid-cols-3 gap-3 mb-1">
      <div><label class="block text-xs font-medium text-slate-500 mb-1">Written Work %</label><input type="number" name="written_work_pct" min="0" max="100" required value="<?= h($editing['written_work_pct'] ?? 0) ?>" class="w-full px-3 py-2 border border-slate-300 rounded-lg"></div>
      <div><label class="block text-xs font-medium text-slate-500 mb-1">Performance Task %</label><input type="number" name="performance_task_pct" min="0" max="100" required value="<?= h($editing['performance_task_pct'] ?? 0) ?>" class="w-full px-3 py-2 border border-slate-300 rounded-lg"></div>
      <div><label class="block text-xs font-medium text-slate-500 mb-1">Examinations %</label><input type="number" name="examination_pct" min="0" max="100" required value="<?= h($editing['examination_pct'] ?? 0) ?>" class="w-full px-3 py-2 border border-slate-300 rounded-lg"></div>
    </div>
    <p class="text-xs text-slate-400 mb-4">Must total 100%.</p>
    <div class="flex gap-2">
      <button type="submit" class="bg-accent-600 hover:bg-accent-700 text-white font-medium px-4 py-2 rounded-lg text-sm"><?= $editing ? 'Save Changes' : 'Add' ?></button>
      <?php if ($editing): ?><a href="<?= h(url('/admin/weight_profiles.php')) ?>" class="px-4 py-2 rounded-lg text-sm text-slate-600 hover:bg-slate-100">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
  <table class="w-full text-sm">
    <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
      <tr><th class="text-left px-4 py-3">Profile</th><th class="text-left px-4 py-3">WW / PT / EX</th><th class="text-left px-4 py-3">Status</th><th class="px-4 py-3"></th></tr>
    </thead>
    <tbody class="divide-y divide-slate-100">
      <?php foreach ($profiles as $p): ?>
      <tr>
        <td class="px-4 py-3 font-medium"><?= h($p['profile_name']) ?></td>
        <td class="px-4 py-3 text-slate-600"><?= (int) $p['written_work_pct'] ?>% / <?= (int) $p['performance_task_pct'] ?>% / <?= (int) $p['examination_pct'] ?>%</td>
        <td class="px-4 py-3"><?= $p['is_active'] ? '<span class="text-emerald-600">Active</span>' : '<span class="text-slate-400">Inactive</span>' ?></td>
        <td class="px-4 py-3 text-right space-x-2">
          <a href="<?= h(url('/admin/weight_profiles.php?edit=' . $p['id'])) ?>" class="text-accent-600 hover:underline">Edit</a>
          <form method="post" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_active">
            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
            <button type="submit" class="text-slate-500 hover:underline"><?= $p['is_active'] ? 'Deactivate' : 'Activate' ?></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php render_footer(); ?>
