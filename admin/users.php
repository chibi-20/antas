<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role(['admin']);
$pdo = db();

// Adviser and Head Teacher are capabilities (sections.adviser_id / head_teacher_assignments),
// not account roles — see admin/sections.php and admin/head_teachers.php.
$roles = ['subject_teacher', 'admin'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? '')) ?: null;
    $username = trim((string) ($_POST['username'] ?? ''));
    $role = (string) ($_POST['role'] ?? '');
    $employeeNumber = trim((string) ($_POST['employee_number'] ?? '')) ?: null;
    $password = (string) ($_POST['password'] ?? '');
    $err = ($fullName === '' || $username === '' || !in_array($role, $roles, true)) ? 'Full name, username, and role are required.' : null;

    if ($action === 'create') {
        if (!$err && $password === '') {
            $err = 'Password is required when creating a user.';
        }
        if ($err) {
            flash_set('error', $err);
        } else {
            try {
                $pdo->prepare('INSERT INTO users (full_name, email, username, password_hash, role, employee_number) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$fullName, $email, $username, password_hash($password, PASSWORD_DEFAULT), $role, $employeeNumber]);
                flash_set('success', "User \"$fullName\" created.");
            } catch (PDOException $e) {
                flash_set('error', 'Could not create user — username or email may already be taken.');
            }
        }
    } elseif ($action === 'update') {
        $id = (int) $_POST['id'];
        if ($err) {
            flash_set('error', $err);
        } else {
            try {
                if ($password !== '') {
                    $pdo->prepare('UPDATE users SET full_name=?, email=?, username=?, role=?, employee_number=?, password_hash=? WHERE id=?')
                        ->execute([$fullName, $email, $username, $role, $employeeNumber, password_hash($password, PASSWORD_DEFAULT), $id]);
                } else {
                    $pdo->prepare('UPDATE users SET full_name=?, email=?, username=?, role=?, employee_number=? WHERE id=?')
                        ->execute([$fullName, $email, $username, $role, $employeeNumber, $id]);
                }
                flash_set('success', 'User updated.');
            } catch (PDOException $e) {
                flash_set('error', 'Could not update user — username or email may already be taken.');
            }
        }
    } elseif ($action === 'toggle_active') {
        $id = (int) $_POST['id'];
        if ($id === current_user()['id']) {
            flash_set('error', 'You cannot deactivate your own account.');
        } else {
            $pdo->prepare('UPDATE users SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
            flash_set('success', 'Status updated.');
        }
    }
    redirect('/admin/users.php');
}

$users = $pdo->query('SELECT * FROM users ORDER BY role, full_name')->fetchAll();
$adviserIds = $pdo->query('SELECT DISTINCT adviser_id FROM sections WHERE adviser_id IS NOT NULL AND is_active = 1')->fetchAll(PDO::FETCH_COLUMN);
$headTeacherIds = $pdo->query('SELECT DISTINCT head_teacher_id FROM head_teacher_assignments WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN);
$editing = null;
if (isset($_GET['edit'])) {
    $s = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $s->execute([(int) $_GET['edit']]);
    $editing = $s->fetch() ?: null;
}

render_header('Users');
?>
<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6 max-w-lg">
  <div class="flex items-center justify-between mb-4">
    <h2 class="text-sm font-semibold text-slate-600"><?= $editing ? 'Edit User' : 'Add User' ?></h2>
    <a href="<?= h(url('/admin/import_teachers.php')) ?>" class="px-3 py-1.5 rounded-lg text-sm bg-slate-100 text-slate-600 hover:bg-slate-200">Import Teachers</a>
  </div>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
    <label class="block text-sm font-medium text-slate-600 mb-1">Full name</label>
    <input type="text" name="full_name" required value="<?= h($editing['full_name'] ?? '') ?>" class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
    <div class="grid grid-cols-2 gap-3 mb-4">
      <div><label class="block text-xs font-medium text-slate-500 mb-1">Username</label><input type="text" name="username" required value="<?= h($editing['username'] ?? '') ?>" class="w-full px-3 py-2 border border-slate-300 rounded-lg"></div>
      <div><label class="block text-xs font-medium text-slate-500 mb-1">Employee #</label><input type="text" name="employee_number" value="<?= h($editing['employee_number'] ?? '') ?>" class="w-full px-3 py-2 border border-slate-300 rounded-lg"></div>
    </div>
    <label class="block text-sm font-medium text-slate-600 mb-1">Email (optional)</label>
    <input type="email" name="email" value="<?= h($editing['email'] ?? '') ?>" class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
    <label class="block text-sm font-medium text-slate-600 mb-1">Role</label>
    <select name="role" required class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
      <?php foreach ($roles as $r): ?>
        <option value="<?= h($r) ?>" <?= ($editing['role'] ?? '') === $r ? 'selected' : '' ?>><?= h(ucwords(str_replace('_', ' ', $r))) ?></option>
      <?php endforeach; ?>
    </select>
    <label class="block text-sm font-medium text-slate-600 mb-1"><?= $editing ? 'New password (leave blank to keep current)' : 'Password' ?></label>
    <input type="password" name="password" <?= $editing ? '' : 'required' ?> class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
    <div class="flex gap-2">
      <button type="submit" class="bg-accent-600 hover:bg-accent-700 text-white font-medium px-4 py-2 rounded-lg text-sm"><?= $editing ? 'Save Changes' : 'Add' ?></button>
      <?php if ($editing): ?><a href="<?= h(url('/admin/users.php')) ?>" class="px-4 py-2 rounded-lg text-sm text-slate-600 hover:bg-slate-100">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
  <table class="w-full text-sm">
    <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
      <tr><th class="text-left px-4 py-3">Name</th><th class="text-left px-4 py-3">Username</th><th class="text-left px-4 py-3">Role</th><th class="text-left px-4 py-3">Also</th><th class="text-left px-4 py-3">Status</th><th class="px-4 py-3"></th></tr>
    </thead>
    <tbody class="divide-y divide-slate-100">
      <?php foreach ($users as $u): ?>
      <tr>
        <td class="px-4 py-3 font-medium"><?= h($u['full_name']) ?></td>
        <td class="px-4 py-3 text-slate-600"><?= h($u['username']) ?></td>
        <td class="px-4 py-3 text-slate-600"><?= h(ucwords(str_replace('_', ' ', $u['role']))) ?></td>
        <td class="px-4 py-3 space-x-1">
          <?php if (in_array($u['id'], $adviserIds)): ?><span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700">Adviser</span><?php endif; ?>
          <?php if (in_array($u['id'], $headTeacherIds)): ?><span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-cyan-100 text-cyan-700">Head Teacher</span><?php endif; ?>
        </td>
        <td class="px-4 py-3"><?= $u['is_active'] ? '<span class="text-emerald-600">Active</span>' : '<span class="text-slate-400">Inactive</span>' ?></td>
        <td class="px-4 py-3 text-right space-x-2">
          <a href="<?= h(url('/admin/users.php?edit=' . $u['id'])) ?>" class="text-accent-600 hover:underline">Edit</a>
          <form method="post" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_active">
            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
            <button type="submit" class="text-slate-500 hover:underline"><?= $u['is_active'] ? 'Deactivate' : 'Activate' ?></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php render_footer(); ?>
