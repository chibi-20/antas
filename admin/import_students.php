<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role(['admin']);
$pdo = db();

function normalize_sex(string $value): ?string
{
    $value = strtoupper(trim($value));
    if (in_array($value, ['M', 'MALE'], true)) return 'M';
    if (in_array($value, ['F', 'FEMALE'], true)) return 'F';
    return null;
}

$results = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $sectionId = (int) ($_POST['section_id'] ?? 0);
    $schoolYearId = (int) ($_POST['school_year_id'] ?? 0);

    if (!$sectionId || !$schoolYearId) {
        flash_set('error', 'School year and section are required.');
        redirect('/admin/import_students.php');
    }

    if (empty($_FILES['csv_file']['tmp_name']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
        flash_set('error', 'Please choose a CSV file to upload.');
        redirect('/admin/import_students.php');
    }

    $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) === 1 && trim((string) $row[0]) === '') {
            continue; // blank line
        }
        $rows[] = $row;
    }
    fclose($handle);

    // Skip a header row if its second column isn't a recognizable sex value.
    if ($rows && normalize_sex((string) ($rows[0][1] ?? '')) === null) {
        array_shift($rows);
    }

    $imported = 0;
    $skipped = [];
    $insert = $pdo->prepare('INSERT INTO students (full_name, sex, section_id, school_year_id) VALUES (?, ?, ?, ?)');

    foreach ($rows as $i => $row) {
        $lineNum = $i + 1;
        $name = trim((string) ($row[0] ?? ''));
        $sex = normalize_sex((string) ($row[1] ?? ''));

        if ($name === '') {
            $skipped[] = "Row $lineNum: missing name.";
            continue;
        }
        if ($sex === null) {
            $skipped[] = "Row $lineNum (\"$name\"): sex must be M/F (or Male/Female).";
            continue;
        }

        $insert->execute([$name, $sex, $sectionId, $schoolYearId]);
        $imported++;
    }

    $results = ['imported' => $imported, 'skipped' => $skipped];
    flash_set('success', "$imported student(s) imported." . ($skipped ? ' ' . count($skipped) . ' row(s) skipped — see details below.' : ''));
}

$schoolYears = $pdo->query('SELECT * FROM school_years ORDER BY year_label DESC')->fetchAll();
$sections = $pdo->query('SELECT sec.*, gl.name AS grade_level, sy.year_label FROM sections sec
    JOIN grade_levels gl ON gl.id = sec.grade_level_id
    JOIN school_years sy ON sy.id = sec.school_year_id
    ORDER BY sy.year_label DESC, gl.sort_order, sec.section_name')->fetchAll();
$sectionLabels = array_map(fn($s) => ['id' => $s['id'], 'label' => $s['grade_level'] . ' - ' . $s['section_name'] . ' (' . $s['year_label'] . ')'], $sections);

render_header('Import Students');
?>
<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6 max-w-xl">
  <h2 class="text-sm font-semibold text-slate-600 mb-2">Import from CSV</h2>
  <p class="text-xs text-slate-400 mb-4">Two columns only: <strong>Full Name, Sex</strong> (M/F or Male/Female). A header row is fine — it's detected automatically. LRN and birthdate are intentionally left out of bulk import to avoid moving sensitive student data through a spreadsheet; add those later per student if needed under Students.</p>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <label class="block text-sm font-medium text-slate-600 mb-1">School year</label>
    <select name="school_year_id" required class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
      <?= select_options($schoolYears, 'id', 'year_label', active_school_year()['id'] ?? null) ?>
    </select>
    <label class="block text-sm font-medium text-slate-600 mb-1">Section</label>
    <select name="section_id" required class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
      <option value="">Select a section…</option>
      <?= select_options($sectionLabels, 'id', 'label', null) ?>
    </select>
    <label class="block text-sm font-medium text-slate-600 mb-1">CSV file</label>
    <input type="file" name="csv_file" accept=".csv,text/csv" required class="w-full mb-4 text-sm">
    <button type="submit" class="bg-accent-600 hover:bg-accent-700 text-white font-medium px-4 py-2 rounded-lg text-sm">Import</button>
    <a href="<?= h(url('/admin/students.php')) ?>" class="px-4 py-2 rounded-lg text-sm text-slate-600 hover:bg-slate-100">Back to Students</a>
  </form>
</div>

<?php if ($results && $results['skipped']): ?>
<div class="bg-white border border-amber-200 rounded-xl shadow-sm p-6 max-w-xl">
  <h2 class="text-sm font-semibold text-amber-700 mb-3">Skipped rows (<?= count($results['skipped']) ?>)</h2>
  <ul class="text-sm text-slate-600 space-y-1 list-disc list-inside">
    <?php foreach ($results['skipped'] as $reason): ?>
      <li><?= h($reason) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>
<?php render_footer(); ?>
