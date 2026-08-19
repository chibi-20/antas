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
    $gradeLevelId = (int) ($_POST['grade_level_id'] ?? 0);
    $sectionName = trim((string) ($_POST['section_name'] ?? ''));
    $adviserId = (int) ($_POST['adviser_id'] ?? 0) ?: null;
    $err = ($schoolYearId === 0 || $gradeLevelId === 0 || $sectionName === '') ? 'School year, grade level, and section name are required.' : null;

    if ($action === 'create') {
        if ($err) {
            flash_set('error', $err);
        } else {
            try {
                $pdo->prepare('INSERT INTO sections (school_year_id, grade_level_id, section_name, adviser_id) VALUES (?, ?, ?, ?)')
                    ->execute([$schoolYearId, $gradeLevelId, $sectionName, $adviserId]);
                flash_set('success', "Section \"$sectionName\" created.");
            } catch (PDOException $e) {
                flash_set('error', 'Could not create section — this school year/grade level/name combination may already exist.');
            }
        }
    } elseif ($action === 'update') {
        $id = (int) $_POST['id'];
        if ($err) {
            flash_set('error', $err);
        } else {
            try {
                $pdo->prepare('UPDATE sections SET school_year_id=?, grade_level_id=?, section_name=?, adviser_id=? WHERE id=?')
                    ->execute([$schoolYearId, $gradeLevelId, $sectionName, $adviserId, $id]);
                flash_set('success', 'Section updated.');
            } catch (PDOException $e) {
                flash_set('error', 'Could not update section — this school year/grade level/name combination may already exist.');
            }
        }
    } elseif ($action === 'toggle_active') {
        $id = (int) $_POST['id'];
        $pdo->prepare('UPDATE sections SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
        flash_set('success', 'Status updated.');
    } elseif ($action === 'set_adviser') {
        $id = (int) $_POST['id'];
        $pdo->prepare('UPDATE sections SET adviser_id = ? WHERE id = ?')->execute([$adviserId, $id]);
        flash_set('success', 'Adviser updated.');
        redirect('/admin/sections.php?school_year_id=' . $schoolYearId);
    }
    redirect('/admin/sections.php');
}

$schoolYears = $pdo->query('SELECT * FROM school_years ORDER BY year_label DESC')->fetchAll();
$gradeLevels = $pdo->query('SELECT * FROM grade_levels WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll();
// Every adviser is a subject teacher first — see includes/layout.php nav_items() note on capabilities.
$advisers = $pdo->query("SELECT * FROM users WHERE role = 'subject_teacher' AND is_active = 1 ORDER BY full_name")->fetchAll();
$activeYear = active_school_year();
$filterYearId = (int) ($_GET['school_year_id'] ?? default_school_year_id($schoolYears) ?? 0);

$stmt = $pdo->prepare('SELECT sec.*, gl.name AS grade_level, sy.year_label, u.full_name AS adviser_name
    FROM sections sec
    JOIN grade_levels gl ON gl.id = sec.grade_level_id
    JOIN school_years sy ON sy.id = sec.school_year_id
    LEFT JOIN users u ON u.id = sec.adviser_id
    WHERE sec.school_year_id = ?
    ORDER BY gl.sort_order, sec.section_name');
$stmt->execute([$filterYearId]);
$sections = $stmt->fetchAll();

$editing = null;
if (isset($_GET['edit'])) {
    $s = $pdo->prepare('SELECT * FROM sections WHERE id = ?');
    $s->execute([(int) $_GET['edit']]);
    $editing = $s->fetch() ?: null;
}

// One adviser per active section for this school year — once a teacher is tagged as adviser
// somewhere, they drop out of every OTHER section's dropdown so the same person can't
// accidentally be assigned twice. A section's own current adviser still appears in its own
// dropdown (and in the edit form, if that's the section being edited) so re-saving without
// changing it still works.
$takenAdviserIds = [];
foreach ($sections as $sec) {
    if ($sec['is_active'] && $sec['adviser_id']) {
        $takenAdviserIds[(int) $sec['adviser_id']] = true;
    }
}
function available_advisers(array $advisers, array $takenAdviserIds, ?int $currentAdviserId): array
{
    return array_values(array_filter($advisers, function ($a) use ($takenAdviserIds, $currentAdviserId) {
        $id = (int) $a['id'];
        return $id === $currentAdviserId || !isset($takenAdviserIds[$id]);
    }));
}

// Grouped by grade level (grade_levels.sort_order, already the query's primary sort) so the
// list reads as a collapsible outline instead of one long flat table — easier to scan a
// specific grade level and spot which of its sections still need an adviser assigned.
$grouped = [];
foreach ($sections as $sec) {
    $glId = (int) $sec['grade_level_id'];
    if (!isset($grouped[$glId])) {
        $grouped[$glId] = ['name' => $sec['grade_level'], 'sections' => []];
    }
    $grouped[$glId]['sections'][] = $sec;
}

render_header('Sections');
?>
<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6 max-w-lg">
  <h2 class="text-sm font-semibold text-slate-600 mb-4"><?= $editing ? 'Edit Section' : 'Add Section' ?></h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
    <label class="block text-sm font-medium text-slate-600 mb-1">School year</label>
    <select name="school_year_id" required class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
      <?= select_options($schoolYears, 'id', 'year_label', $editing['school_year_id'] ?? $activeYear['id'] ?? null) ?>
    </select>
    <div class="grid grid-cols-2 gap-3 mb-4">
      <div>
        <label class="block text-xs font-medium text-slate-500 mb-1">Grade level</label>
        <select name="grade_level_id" required class="w-full px-3 py-2 border border-slate-300 rounded-lg">
          <option value="">Select…</option>
          <?= select_options($gradeLevels, 'id', 'name', $editing['grade_level_id'] ?? null) ?>
        </select>
      </div>
      <div><label class="block text-xs font-medium text-slate-500 mb-1">Section name</label><input type="text" name="section_name" required placeholder="Diamond" value="<?= h($editing['section_name'] ?? '') ?>" class="w-full px-3 py-2 border border-slate-300 rounded-lg"></div>
    </div>
    <label class="block text-sm font-medium text-slate-600 mb-1">Adviser</label>
    <select name="adviser_id" class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg js-searchable" data-placeholder="Search teachers…">
      <option value="">Unassigned</option>
      <?= select_options(available_advisers($advisers, $takenAdviserIds, $editing['adviser_id'] ?? null), 'id', 'full_name', $editing['adviser_id'] ?? null) ?>
    </select>
    <p class="text-xs text-slate-400 -mt-3 mb-4">Teachers already advising another section this year aren't listed.</p>
    <div class="flex gap-2">
      <button type="submit" class="bg-accent-600 hover:bg-accent-700 text-white font-medium px-4 py-2 rounded-lg text-sm"><?= $editing ? 'Save Changes' : 'Add' ?></button>
      <?php if ($editing): ?><a href="<?= h(url('/admin/sections.php')) ?>" class="px-4 py-2 rounded-lg text-sm text-slate-600 hover:bg-slate-100">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="flex items-center justify-between mb-4">
  <form method="get" class="flex items-center gap-2 text-sm">
    <label class="text-slate-500">School year:</label>
    <select name="school_year_id" onchange="this.form.submit()" class="px-3 py-2 border border-slate-300 rounded-lg">
      <?= select_options($schoolYears, 'id', 'year_label', $filterYearId) ?>
    </select>
  </form>
  <a href="<?= h(url('/admin/import_sections.php')) ?>" class="px-3 py-1.5 rounded-lg text-sm bg-slate-100 text-slate-600 hover:bg-slate-200">Import Sections</a>
</div>

<?php foreach ($grouped as $glId => $group):
  $unassigned = count(array_filter($group['sections'], fn($s) => !$s['adviser_id']));
?>
<details class="group bg-white border border-slate-200 rounded-xl shadow-sm mb-3 overflow-hidden searchable-item" data-search="<?= h($group['name']) ?>" open>
  <summary class="cursor-pointer select-none list-none px-4 py-3 flex items-center justify-between bg-slate-50 hover:bg-slate-100">
    <span class="flex items-center gap-2">
      <span class="text-slate-400 transition-transform group-open:rotate-90"><?= icon_svg('chevron-right', 'w-4 h-4') ?></span>
      <span class="font-medium text-slate-700"><?= h($group['name']) ?></span>
    </span>
    <span class="text-xs text-slate-400">
      <?= count($group['sections']) ?> section<?= count($group['sections']) === 1 ? '' : 's' ?>
      <?php if ($unassigned > 0): ?><span class="text-amber-600 font-medium">· <?= $unassigned ?> without adviser</span><?php endif; ?>
    </span>
  </summary>
  <table class="w-full text-sm">
    <thead class="bg-slate-50 text-slate-500 text-xs uppercase border-t border-slate-200">
      <tr><th class="text-left px-4 py-2">Section</th><th class="text-left px-4 py-2">Adviser</th><th class="text-left px-4 py-2">Status</th><th class="px-4 py-2"></th></tr>
    </thead>
    <tbody class="divide-y divide-slate-100">
      <?php foreach ($group['sections'] as $sec): ?>
      <tr>
        <td class="px-4 py-3 font-medium"><?= h($sec['section_name']) ?></td>
        <td class="px-4 py-3">
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set_adviser">
            <input type="hidden" name="id" value="<?= (int) $sec['id'] ?>">
            <input type="hidden" name="school_year_id" value="<?= $filterYearId ?>">
            <select name="adviser_id" onchange="this.form.submit()" class="px-2 py-1.5 border border-slate-300 rounded-lg text-sm max-w-[220px] <?= !$sec['adviser_id'] ? 'text-amber-600 border-amber-300' : '' ?>">
              <option value="">Unassigned</option>
              <?= select_options(available_advisers($advisers, $takenAdviserIds, $sec['adviser_id'] ? (int) $sec['adviser_id'] : null), 'id', 'full_name', $sec['adviser_id']) ?>
            </select>
          </form>
        </td>
        <td class="px-4 py-3"><?= $sec['is_active'] ? '<span class="text-emerald-600">Active</span>' : '<span class="text-slate-400">Inactive</span>' ?></td>
        <td class="px-4 py-3 text-right space-x-2 whitespace-nowrap">
          <a href="<?= h(url('/admin/sections.php?edit=' . $sec['id'])) ?>" class="text-accent-600 hover:underline">Edit</a>
          <form method="post" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_active">
            <input type="hidden" name="id" value="<?= (int) $sec['id'] ?>">
            <button type="submit" class="text-slate-500 hover:underline"><?= $sec['is_active'] ? 'Deactivate' : 'Activate' ?></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</details>
<?php endforeach; ?>
<?php if (!$sections): ?>
<div class="bg-white border border-slate-200 rounded-xl shadow-sm px-4 py-6 text-center text-slate-400 text-sm">No sections for this school year yet.</div>
<?php endif; ?>
<?php render_footer(); ?>
