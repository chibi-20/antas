<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';

$user = require_role(['subject_teacher', 'admin']);
$pdo = db();
$year = require_active_school_year();

$stmt = $pdo->prepare('SELECT sec.*, gl.name AS grade_level FROM sections sec
    JOIN grade_levels gl ON gl.id = sec.grade_level_id
    WHERE sec.adviser_id = ? AND sec.school_year_id = ? AND sec.is_active = 1 ORDER BY gl.sort_order, sec.section_name');
$stmt->execute([$user['id'], $year['id']]);
$sections = $stmt->fetchAll();

render_header('My Section');
?>
<div class="grid gap-4 md:grid-cols-2">
  <?php foreach ($sections as $sec): ?>
  <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm p-6">
    <div class="font-semibold text-slate-800 dark:text-slate-100 mb-1"><?= h($sec['grade_level'] . ' - ' . $sec['section_name']) ?></div>
    <div class="text-xs text-slate-400 dark:text-slate-500 mb-4"><?= h($year['year_label']) ?></div>
    <div class="flex flex-wrap gap-2 text-sm">
      <a href="<?= h(url('/adviser/consolidated.php?section_id=' . $sec['id'])) ?>" class="px-3 py-1.5 rounded-lg bg-accent-50 dark:bg-accent-900/30 text-accent-700 dark:text-accent-300 hover:bg-accent-100 dark:hover:bg-accent-900/40">Consolidated Grades</a>
      <a href="<?= h(url('/adviser/ranking.php?section_id=' . $sec['id'])) ?>" class="px-3 py-1.5 rounded-lg bg-accent-50 dark:bg-accent-900/30 text-accent-700 dark:text-accent-300 hover:bg-accent-100 dark:hover:bg-accent-900/40">Ranking</a>
      <a href="<?= h(url('/adviser/at_risk.php?section_id=' . $sec['id'])) ?>" class="px-3 py-1.5 rounded-lg bg-rose-50 dark:bg-rose-900/30 text-rose-700 dark:text-rose-300 hover:bg-rose-100 dark:hover:bg-rose-900/40">At Risk</a>
      <a href="<?= h(url('/adviser/card_slips.php?section_id=' . $sec['id'])) ?>" class="px-3 py-1.5 rounded-lg bg-accent-50 dark:bg-accent-900/30 text-accent-700 dark:text-accent-300 hover:bg-accent-100 dark:hover:bg-accent-900/40">Card Slips</a>
      <a href="<?= h(url('/adviser/remarks.php?section_id=' . $sec['id'])) ?>" class="px-3 py-1.5 rounded-lg bg-accent-50 dark:bg-accent-900/30 text-accent-700 dark:text-accent-300 hover:bg-accent-100 dark:hover:bg-accent-900/40">Remarks</a>
      <a href="<?= h(url('/adviser/export_csv.php?section_id=' . $sec['id'])) ?>" class="px-3 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-600">Export CSV</a>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (!$sections): ?>
  <div class="text-slate-400 dark:text-slate-500 text-sm">No section assigned to you for the active school year yet — ask an admin to set you as adviser under Sections.</div>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
