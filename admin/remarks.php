<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role(['admin']);
$pdo = db();

$categories = ['positive' => 'Positive', 'needs_improvement' => 'Needs Improvement'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $text = trim((string) ($_POST['remark_text'] ?? ''));
    $category = (string) ($_POST['category'] ?? '');
    $err = ($text === '' || !array_key_exists($category, $categories)) ? 'Remark text and category are required.' : null;

    if ($action === 'create') {
        if ($err) {
            flash_set('error', $err);
        } else {
            $pdo->prepare('INSERT INTO remark_bank (remark_text, category) VALUES (?, ?)')->execute([$text, $category]);
            flash_set('success', 'Remark added.');
        }
    } elseif ($action === 'update') {
        $id = (int) $_POST['id'];
        if ($err) {
            flash_set('error', $err);
        } else {
            $pdo->prepare('UPDATE remark_bank SET remark_text=?, category=? WHERE id=?')->execute([$text, $category, $id]);
            flash_set('success', 'Remark updated.');
        }
    } elseif ($action === 'toggle_active') {
        $id = (int) $_POST['id'];
        $pdo->prepare('UPDATE remark_bank SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
        flash_set('success', 'Status updated.');
    }
    redirect('/admin/remarks.php');
}

$remarks = $pdo->query('SELECT * FROM remark_bank ORDER BY category, remark_text')->fetchAll();
$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM remark_bank WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}

render_header('Remarks');
?>
<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6 max-w-lg">
  <h2 class="text-sm font-semibold text-slate-600 mb-4"><?= $editing ? 'Edit Remark' : 'Add Remark' ?></h2>
  <p class="text-xs text-slate-400 mb-4">The canned "Teacher's Comments/Remarks" a section adviser can pick from on the Card Slips 2-per-sheet layout — matches the official SF9's remarks section. Advisers can also always type a custom one instead.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
    <label class="block text-sm font-medium text-slate-600 mb-1">Remark text</label>
    <textarea name="remark_text" required rows="2" placeholder="Always ready, hardworking, and positive in every school task." class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg"><?= h($editing['remark_text'] ?? '') ?></textarea>
    <label class="block text-sm font-medium text-slate-600 mb-1">Category</label>
    <select name="category" required class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
      <?php foreach ($categories as $key => $label): ?>
        <option value="<?= h($key) ?>" <?= ($editing['category'] ?? 'positive') === $key ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
    <div class="flex gap-2">
      <button type="submit" class="bg-accent-600 hover:bg-accent-700 text-white font-medium px-4 py-2 rounded-lg text-sm"><?= $editing ? 'Save Changes' : 'Add' ?></button>
      <?php if ($editing): ?><a href="<?= h(url('/admin/remarks.php')) ?>" class="px-4 py-2 rounded-lg text-sm text-slate-600 hover:bg-slate-100">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
  <table class="w-full text-sm">
    <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
      <tr><th class="text-left px-4 py-3">Remark</th><th class="text-left px-4 py-3">Category</th><th class="text-left px-4 py-3">Status</th><th class="px-4 py-3"></th></tr>
    </thead>
    <tbody class="divide-y divide-slate-100">
      <?php foreach ($remarks as $r): ?>
      <tr>
        <td class="px-4 py-3 font-medium"><?= h($r['remark_text']) ?></td>
        <td class="px-4 py-3 text-slate-600"><?= h($categories[$r['category']] ?? $r['category']) ?></td>
        <td class="px-4 py-3"><?= $r['is_active'] ? '<span class="text-emerald-600">Active</span>' : '<span class="text-slate-400">Inactive</span>' ?></td>
        <td class="px-4 py-3 text-right space-x-2 whitespace-nowrap">
          <a href="<?= h(url('/admin/remarks.php?edit=' . $r['id'])) ?>" class="text-accent-600 hover:underline">Edit</a>
          <form method="post" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_active">
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <button type="submit" class="text-slate-500 hover:underline"><?= $r['is_active'] ? 'Deactivate' : 'Activate' ?></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$remarks): ?><tr><td colspan="4" class="px-4 py-6 text-center text-slate-400">No remarks yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php render_footer(); ?>
