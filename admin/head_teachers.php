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
    $subjectId = (int) ($_POST['subject_id'] ?? 0);

    if ($action === 'create') {
        $mode = $_POST['mode'] ?? 'existing';
        $err = (!$schoolYearId || !$subjectId) ? 'School year and subject are required.' : null;

        $pdo->beginTransaction();
        try {
            if ($mode === 'new') {
                $fullName = trim((string) ($_POST['full_name'] ?? ''));
                $username = trim((string) ($_POST['username'] ?? ''));
                $password = (string) ($_POST['password'] ?? '');
                $employeeNumber = trim((string) ($_POST['employee_number'] ?? '')) ?: null;
                $email = trim((string) ($_POST['email'] ?? '')) ?: null;
                if (!$err && ($fullName === '' || $username === '' || $password === '')) {
                    $err = 'Full name, username, and password are required for a new teacher account.';
                }
                if ($err) {
                    throw new RuntimeException($err);
                }
                $pdo->prepare('INSERT INTO users (full_name, email, username, password_hash, role, employee_number) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$fullName, $email, $username, password_hash($password, PASSWORD_DEFAULT), 'subject_teacher', $employeeNumber]);
                $headTeacherId = (int) $pdo->lastInsertId();
            } else {
                $headTeacherId = (int) ($_POST['head_teacher_id'] ?? 0);
                if (!$err && !$headTeacherId) {
                    $err = 'Pick a teacher.';
                }
                if ($err) {
                    throw new RuntimeException($err);
                }
            }

            $pdo->prepare('INSERT INTO head_teacher_assignments (head_teacher_id, subject_id, school_year_id) VALUES (?, ?, ?)')
                ->execute([$headTeacherId, $subjectId, $schoolYearId]);
            $pdo->commit();
            flash_set('success', $mode === 'new' ? "Teacher account created and made Head Teacher for this subject." : 'Head Teacher assigned.');
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            flash_set('error', $e->getMessage());
        } catch (PDOException $e) {
            $pdo->rollBack();
            flash_set('error', 'Could not save — this combination may already exist, or the username/email is already taken.');
        }
    } elseif ($action === 'update') {
        $id = (int) $_POST['id'];
        $headTeacherId = (int) ($_POST['head_teacher_id'] ?? 0);
        $err = (!$schoolYearId || !$subjectId || !$headTeacherId) ? 'All fields are required.' : null;
        if ($err) {
            flash_set('error', $err);
        } else {
            $pdo->prepare('UPDATE head_teacher_assignments SET head_teacher_id=?, subject_id=?, school_year_id=? WHERE id=?')
                ->execute([$headTeacherId, $subjectId, $schoolYearId, $id]);
            flash_set('success', 'Assignment updated.');
        }
    } elseif ($action === 'toggle_active') {
        $id = (int) $_POST['id'];
        $pdo->prepare('UPDATE head_teacher_assignments SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
        flash_set('success', 'Status updated.');
    }
    redirect('/admin/head_teachers.php');
}

$schoolYears = $pdo->query('SELECT * FROM school_years ORDER BY year_label DESC')->fetchAll();
// Compound parent subjects (e.g. MAPEH) are never directly supervised — only their
// components (e.g. Music-Arts, PE-Health) are, since those are what actually get
// submitted/reviewed. See admin/subjects.php.
$subjects = $pdo->query("SELECT * FROM subjects WHERE is_active = 1
    AND id NOT IN (SELECT DISTINCT parent_subject_id FROM subjects WHERE parent_subject_id IS NOT NULL)
    ORDER BY subject_name")->fetchAll();
$teachers = $pdo->query("SELECT * FROM users WHERE role = 'subject_teacher' AND is_active = 1 ORDER BY full_name")->fetchAll();

$assignments = $pdo->query('SELECT hta.*, sub.subject_name, u.full_name AS ht_name, u.username AS ht_username, sy.year_label
    FROM head_teacher_assignments hta
    JOIN subjects sub ON sub.id = hta.subject_id
    JOIN users u ON u.id = hta.head_teacher_id
    JOIN school_years sy ON sy.id = hta.school_year_id
    ORDER BY sy.year_label DESC, u.full_name, sub.subject_name')->fetchAll();

$editing = null;
if (isset($_GET['edit'])) {
    $s = $pdo->prepare('SELECT * FROM head_teacher_assignments WHERE id = ?');
    $s->execute([(int) $_GET['edit']]);
    $editing = $s->fetch() ?: null;
}

render_header('Head Teachers');
?>
<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6 max-w-lg">
  <h2 class="text-sm font-semibold text-slate-600 mb-4"><?= $editing ? 'Edit Head Teacher Assignment' : 'Add Head Teacher' ?></h2>
  <?php if (!$editing): ?>
  <p class="text-xs text-slate-400 mb-4">Head Teacher is a capability on top of a Subject Teacher account, not a separate login type — so the same person can still teach classes. Pick an existing teacher, or create a brand-new account right here in one step.</p>
  <?php endif; ?>
  <form method="post" id="ht-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>

    <?php if (!$editing): ?>
    <div class="flex gap-4 mb-4 text-sm">
      <label class="inline-flex items-center gap-1.5"><input type="radio" name="mode" value="existing" checked> Existing teacher</label>
      <label class="inline-flex items-center gap-1.5"><input type="radio" name="mode" value="new"> New teacher account</label>
    </div>

    <div id="ht-mode-existing">
      <label class="block text-sm font-medium text-slate-600 mb-1">Teacher</label>
      <select name="head_teacher_id" class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg js-searchable" data-placeholder="Search teachers…">
        <?= select_options($teachers, 'id', 'full_name', null) ?>
      </select>
    </div>

    <div id="ht-mode-new" class="hidden">
      <label class="block text-sm font-medium text-slate-600 mb-1">Full name</label>
      <input type="text" name="full_name" class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
      <div class="grid grid-cols-2 gap-3 mb-4">
        <div><label class="block text-xs font-medium text-slate-500 mb-1">Username</label><input type="text" name="username" class="w-full px-3 py-2 border border-slate-300 rounded-lg"></div>
        <div><label class="block text-xs font-medium text-slate-500 mb-1">Employee #</label><input type="text" name="employee_number" class="w-full px-3 py-2 border border-slate-300 rounded-lg"></div>
      </div>
      <label class="block text-sm font-medium text-slate-600 mb-1">Email (optional)</label>
      <input type="email" name="email" class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
      <label class="block text-sm font-medium text-slate-600 mb-1">Password</label>
      <input type="password" name="password" class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
    </div>
    <?php else: ?>
      <label class="block text-sm font-medium text-slate-600 mb-1">Teacher</label>
      <select name="head_teacher_id" required class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg js-searchable" data-placeholder="Search teachers…">
        <?= select_options($teachers, 'id', 'full_name', $editing['head_teacher_id'] ?? null) ?>
      </select>
    <?php endif; ?>

    <label class="block text-sm font-medium text-slate-600 mb-1">School year</label>
    <select name="school_year_id" required class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
      <?= select_options($schoolYears, 'id', 'year_label', $editing['school_year_id'] ?? (active_school_year()['id'] ?? null)) ?>
    </select>
    <label class="block text-sm font-medium text-slate-600 mb-1">Subject / Learning Area</label>
    <select name="subject_id" required class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
      <?= select_options($subjects, 'id', 'subject_name', $editing['subject_id'] ?? null) ?>
    </select>
    <div class="flex gap-2">
      <button type="submit" class="bg-accent-600 hover:bg-accent-700 text-white font-medium px-4 py-2 rounded-lg text-sm"><?= $editing ? 'Save Changes' : 'Add Head Teacher' ?></button>
      <?php if ($editing): ?><a href="<?= h(url('/admin/head_teachers.php')) ?>" class="px-4 py-2 rounded-lg text-sm text-slate-600 hover:bg-slate-100">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
  <table class="w-full text-sm">
    <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
      <tr><th class="text-left px-4 py-3">Head Teacher</th><th class="text-left px-4 py-3">Subject</th><th class="text-left px-4 py-3">Year</th><th class="text-left px-4 py-3">Status</th><th class="px-4 py-3"></th></tr>
    </thead>
    <tbody class="divide-y divide-slate-100">
      <?php foreach ($assignments as $a): ?>
      <tr>
        <td class="px-4 py-3 font-medium"><?= h($a['ht_name']) ?> <span class="text-slate-400 font-normal">(<?= h($a['ht_username']) ?>)</span></td>
        <td class="px-4 py-3 text-slate-600"><?= h($a['subject_name']) ?></td>
        <td class="px-4 py-3 text-slate-500"><?= h($a['year_label']) ?></td>
        <td class="px-4 py-3"><?= $a['is_active'] ? '<span class="text-emerald-600">Active</span>' : '<span class="text-slate-400">Inactive</span>' ?></td>
        <td class="px-4 py-3 text-right space-x-2">
          <a href="<?= h(url('/admin/head_teachers.php?edit=' . $a['id'])) ?>" class="text-accent-600 hover:underline">Edit</a>
          <form method="post" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_active">
            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
            <button type="submit" class="text-slate-500 hover:underline"><?= $a['is_active'] ? 'Deactivate' : 'Activate' ?></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$assignments): ?>
      <tr><td colspan="5" class="px-4 py-6 text-center text-slate-400">No Head Teachers yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if (!$editing): ?>
<script>
document.querySelectorAll('#ht-form input[name="mode"]').forEach(function (radio) {
  radio.addEventListener('change', function () {
    var isNew = this.value === 'new';
    document.getElementById('ht-mode-existing').classList.toggle('hidden', isNew);
    document.getElementById('ht-mode-new').classList.toggle('hidden', !isNew);
  });
});
</script>
<?php endif; ?>
<?php render_footer(); ?>
