<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role(['admin']);
$pdo = db();

$results = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $schoolYearId = (int) ($_POST['school_year_id'] ?? 0);
    $gradeLevelId = (int) ($_POST['grade_level_id'] ?? 0);
    $adviserId = (int) ($_POST['adviser_id'] ?? 0) ?: null;
    $namesRaw = (string) ($_POST['section_names'] ?? '');

    if (!$schoolYearId || !$gradeLevelId) {
        flash_set('error', 'School year and grade level are required.');
        redirect('/admin/import_sections.php');
    }

    $names = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $namesRaw))));
    $created = 0;
    $skipped = [];
    $insert = $pdo->prepare('INSERT INTO sections (school_year_id, grade_level_id, section_name, adviser_id) VALUES (?, ?, ?, ?)');

    foreach ($names as $name) {
        if ($name === '') {
            continue;
        }
        try {
            $insert->execute([$schoolYearId, $gradeLevelId, $name, $adviserId]);
            $created++;
        } catch (PDOException $e) {
            $skipped[] = "\"$name\" — already exists for this school year and grade level.";
        }
    }

    $results = ['created' => $created, 'skipped' => $skipped];
    flash_set('success', "$created section(s) created." . ($skipped ? ' ' . count($skipped) . ' skipped as duplicates — see details below.' : ''));
}

$schoolYears = $pdo->query('SELECT * FROM school_years ORDER BY year_label DESC')->fetchAll();
$gradeLevels = $pdo->query('SELECT * FROM grade_levels WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll();
$advisers = $pdo->query("SELECT * FROM users WHERE role = 'subject_teacher' AND is_active = 1 ORDER BY full_name")->fetchAll();

render_header('Import Sections');
?>
<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6 max-w-xl">
  <h2 class="text-sm font-semibold text-slate-600 mb-2">Bulk-create sections for a grade level</h2>
  <p class="text-xs text-slate-400 mb-4">One section name per line (e.g. "Diamond", "Emerald", "Ruby"). All of them will be created under the same school year and grade level. Adviser is optional and, if set, applies to every section created here — you can reassign individual sections afterward under Sections.</p>
  <form method="post">
    <?= csrf_field() ?>
    <label class="block text-sm font-medium text-slate-600 mb-1">School year</label>
    <select name="school_year_id" required class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
      <?= select_options($schoolYears, 'id', 'year_label', active_school_year()['id'] ?? null) ?>
    </select>
    <label class="block text-sm font-medium text-slate-600 mb-1">Grade level</label>
    <select name="grade_level_id" required class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
      <option value="">Select…</option>
      <?= select_options($gradeLevels, 'id', 'name', null) ?>
    </select>
    <label class="block text-sm font-medium text-slate-600 mb-1">Adviser (optional, applies to all)</label>
    <select name="adviser_id" class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg js-searchable" data-placeholder="Search teachers…">
      <option value="">Unassigned</option>
      <?= select_options($advisers, 'id', 'full_name', null) ?>
    </select>
    <label class="block text-sm font-medium text-slate-600 mb-1">Section names (one per line)</label>
    <textarea name="section_names" required rows="6" placeholder="Diamond&#10;Emerald&#10;Ruby" class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg font-mono text-sm"></textarea>
    <button type="submit" class="bg-accent-600 hover:bg-accent-700 text-white font-medium px-4 py-2 rounded-lg text-sm">Create Sections</button>
    <a href="<?= h(url('/admin/sections.php')) ?>" class="px-4 py-2 rounded-lg text-sm text-slate-600 hover:bg-slate-100">Back to Sections</a>
  </form>
</div>

<?php if ($results && $results['skipped']): ?>
<div class="bg-white border border-amber-200 rounded-xl shadow-sm p-6 max-w-xl">
  <h2 class="text-sm font-semibold text-amber-700 mb-3">Skipped (<?= count($results['skipped']) ?>)</h2>
  <ul class="text-sm text-slate-600 space-y-1 list-disc list-inside">
    <?php foreach ($results['skipped'] as $reason): ?>
      <li><?= h($reason) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>
<?php render_footer(); ?>
