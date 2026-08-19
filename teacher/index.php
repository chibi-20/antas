<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';

$user = require_role(['subject_teacher']);
$pdo = db();
$year = require_active_school_year();

$stmt = $pdo->prepare('SELECT sst.id, gl.name AS grade_level, sec.section_name, sub.subject_name
    FROM section_subject_teachers sst
    JOIN sections sec ON sec.id = sst.section_id
    JOIN grade_levels gl ON gl.id = sec.grade_level_id
    JOIN subjects sub ON sub.id = sst.subject_id
    WHERE sst.teacher_id = ? AND sst.school_year_id = ? AND sst.is_active = 1
    ORDER BY gl.sort_order, sec.section_name, sub.subject_name');
$stmt->execute([$user['id'], $year['id']]);
$assignments = $stmt->fetchAll();

$statusStmt = $pdo->prepare('SELECT term, status FROM submission_status WHERE section_subject_teacher_id = ?');

// Cycled per card, purely decorative — gives each class its own visual identity at a glance.
$cardThemes = [
    ['gradient' => 'from-violet-300 to-purple-200', 'icon' => 'map-pin', 'text' => 'text-violet-700'],
    ['gradient' => 'from-blue-300 to-sky-200', 'icon' => 'compass', 'text' => 'text-blue-700'],
    ['gradient' => 'from-teal-300 to-cyan-200', 'icon' => 'bank', 'text' => 'text-teal-700'],
    ['gradient' => 'from-emerald-300 to-green-200', 'icon' => 'flag', 'text' => 'text-emerald-700'],
    ['gradient' => 'from-amber-300 to-orange-200', 'icon' => 'ship', 'text' => 'text-amber-700'],
    ['gradient' => 'from-pink-300 to-rose-200', 'icon' => 'building', 'text' => 'text-pink-700'],
];

render_header('My Classes', 'Manage and monitor the progress of your classes.');
?>
<div class="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
  <?php foreach ($assignments as $i => $a):
      $statusStmt->execute([$a['id']]);
      $statuses = array_column($statusStmt->fetchAll(), 'status', 'term');
      $theme = $cardThemes[$i % count($cardThemes)];
  ?>
  <div class="searchable-item bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden hover:shadow-md transition-shadow" data-search="<?= h($a['subject_name'] . ' ' . $a['grade_level'] . ' ' . $a['section_name']) ?>">
    <div class="h-16 bg-gradient-to-br <?= $theme['gradient'] ?> flex items-center px-5">
      <div class="w-10 h-10 rounded-full bg-white/60 flex items-center justify-center <?= $theme['text'] ?>"><?= icon_svg($theme['icon'], 'w-5 h-5') ?></div>
    </div>
    <div class="p-6">
      <div class="font-semibold text-slate-800 mb-1"><?= h($a['subject_name']) ?></div>
      <div class="text-xs font-medium mb-4 <?= $theme['text'] ?>"><?= h($a['grade_level'] . ' - ' . $a['section_name']) ?></div>
      <div class="flex flex-col gap-2">
        <?php for ($t = 1; $t <= 3; $t++): ?>
          <a href="<?= h(url('/teacher/class_record.php?sst_id=' . $a['id'] . '&term=' . $t)) ?>" class="flex items-center justify-between px-3 py-2 rounded-lg bg-slate-50 hover:bg-accent-50 text-sm">
            <span class="font-medium text-slate-600">Term <?= $t ?></span>
            <?= status_badge($statuses[$t] ?? 'not_started') ?>
          </a>
        <?php endfor; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (!$assignments): ?>
  <div class="text-slate-400 text-sm">No classes assigned yet — ask an admin to set up your subject assignment.</div>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
