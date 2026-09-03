<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';

$user = require_role(['subject_teacher']);
$pdo = db();
$year = require_active_school_year();

$stmt = $pdo->prepare('SELECT sst.id, sst.section_id, sst.subject_id, sst.term_scope, sst.sex_scope,
        gl.name AS grade_level, sec.section_name, sub.subject_name
    FROM section_subject_teachers sst
    JOIN sections sec ON sec.id = sst.section_id
    JOIN grade_levels gl ON gl.id = sec.grade_level_id
    JOIN subjects sub ON sub.id = sst.subject_id
    WHERE sst.teacher_id = ? AND sst.school_year_id = ? AND sst.is_active = 1
    ORDER BY gl.sort_order, sec.section_name, sub.subject_name, sst.sex_scope, sst.term_scope');
$stmt->execute([$user['id'], $year['id']]);
$rows = $stmt->fetchAll();

// A teacher can hold several sst rows for the same section+subject — one per term (TLE-style
// mid-year teacher changes) and/or split by sex. Group into one card per (section, subject,
// sex_scope), and resolve each term's link to whichever of the teacher's own rows in that
// group actually covers it, instead of always linking the first row regardless of term.
$assignments = [];
foreach ($rows as $r) {
    $key = $r['section_id'] . '|' . $r['subject_id'] . '|' . $r['sex_scope'];
    if (!isset($assignments[$key])) {
        $assignments[$key] = [
            'grade_level' => $r['grade_level'],
            'section_name' => $r['section_name'],
            'subject_name' => $r['subject_name'],
            'sex_scope' => $r['sex_scope'],
            'term_sst' => [1 => null, 2 => null, 3 => null],
        ];
    }
    $termScope = (int) $r['term_scope'];
    if ($termScope === 0) {
        for ($t = 1; $t <= 3; $t++) {
            $assignments[$key]['term_sst'][$t] = $r['id'];
        }
    } else {
        $assignments[$key]['term_sst'][$termScope] = $r['id'];
    }
}
$assignments = array_values($assignments);

// Keyed by (sst_id, term) rather than just sst_id — a term-specific sst row still gets
// submission_status seeded for all 3 terms by admin/assignments.php, but only its own term's
// row is meaningful; the others must be ignored, not treated as this card's status for a term
// actually covered by a different sst row.
$statusStmt = $pdo->prepare('SELECT status FROM submission_status WHERE section_subject_teacher_id = ? AND term = ?');

// A MIX card's student count can span more than one sst row (e.g. a different pick per term),
// so it's resolved from the group's own distinct term_sst ids rather than assumed to be one row.
$mixClaimCount = function (array $sstIds) use ($pdo): int {
    $sstIds = array_values(array_unique(array_filter($sstIds)));
    if (!$sstIds) {
        return 0;
    }
    $placeholders = implode(',', array_fill(0, count($sstIds), '?'));
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT student_id) FROM sst_student_claims WHERE section_subject_teacher_id IN ($placeholders)");
    $stmt->execute($sstIds);
    return (int) $stmt->fetchColumn();
};

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
<div class="flex justify-end mb-4">
  <a href="<?= h(url('/change_password.php')) ?>" class="px-3 py-1.5 rounded-lg text-sm bg-slate-100 text-slate-600 hover:bg-slate-200">Change Password</a>
</div>
<div class="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
  <?php foreach ($assignments as $i => $a):
      $statuses = [];
      foreach ($a['term_sst'] as $t => $sstId) {
          if ($sstId === null) {
              continue;
          }
          $statusStmt->execute([$sstId, $t]);
          $status = $statusStmt->fetchColumn();
          if ($status !== false) {
              $statuses[$t] = $status;
          }
      }
      $theme = $cardThemes[$i % count($cardThemes)];
  ?>
  <div class="searchable-item bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden hover:shadow-md transition-shadow" data-search="<?= h($a['subject_name'] . ' ' . $a['grade_level'] . ' ' . $a['section_name']) ?>">
    <div class="h-16 bg-gradient-to-br <?= $theme['gradient'] ?> flex items-center px-5">
      <div class="w-10 h-10 rounded-full bg-white/60 flex items-center justify-center <?= $theme['text'] ?>"><?= icon_svg($theme['icon'], 'w-5 h-5') ?></div>
    </div>
    <div class="p-6">
      <?php
        $scopeLabel = match ($a['sex_scope']) {
            'M' => 'Male only',
            'F' => 'Female only',
            'MIX' => 'Mix — ' . $mixClaimCount($a['term_sst']) . ' students',
            default => null,
        };
      ?>
      <div class="font-semibold text-slate-800 mb-1"><?= h($a['subject_name']) ?><?php if ($scopeLabel !== null): ?> <span class="font-normal text-xs text-slate-400">(<?= h($scopeLabel) ?>)</span><?php endif; ?></div>
      <div class="text-xs font-medium mb-4 <?= $theme['text'] ?>"><?= h($a['grade_level'] . ' - ' . $a['section_name']) ?></div>
      <div class="flex flex-col gap-2">
        <?php for ($t = 1; $t <= 3; $t++): $sstId = $a['term_sst'][$t]; ?>
          <?php if ($sstId === null): ?>
            <div class="flex items-center justify-between px-3 py-2 rounded-lg bg-slate-50 text-sm opacity-50">
              <span class="font-medium text-slate-500">Term <?= $t ?></span>
              <span class="text-xs text-slate-400">Not assigned</span>
            </div>
          <?php else: ?>
            <a href="<?= h(url('/teacher/class_record.php?sst_id=' . $sstId . '&term=' . $t)) ?>" class="flex items-center justify-between px-3 py-2 rounded-lg bg-slate-50 hover:bg-accent-50 text-sm">
              <span class="font-medium text-slate-600">Term <?= $t ?></span>
              <?= status_badge($statuses[$t] ?? 'not_started') ?>
            </a>
          <?php endif; ?>
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
