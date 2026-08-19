<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role(['admin']);
$pdo = db();
$year = active_school_year();

function count_query(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

$yearId = $year['id'] ?? 0;

$stats = [
    'students' => $yearId ? count_query($pdo, 'SELECT COUNT(*) FROM students WHERE is_active = 1 AND school_year_id = ?', [$yearId]) : 0,
    'sections' => $yearId ? count_query($pdo, 'SELECT COUNT(*) FROM sections WHERE is_active = 1 AND school_year_id = ?', [$yearId]) : 0,
    'subjects' => count_query($pdo, 'SELECT COUNT(*) FROM subjects WHERE is_active = 1'),
    'teachers' => count_query($pdo, "SELECT COUNT(*) FROM users WHERE role = 'subject_teacher' AND is_active = 1"),
    // Adviser/Head Teacher are capabilities, not roles — count distinct holders of each.
    'head_teachers' => count_query($pdo, 'SELECT COUNT(DISTINCT head_teacher_id) FROM head_teacher_assignments WHERE is_active = 1'),
    'advisers' => count_query($pdo, 'SELECT COUNT(DISTINCT adviser_id) FROM sections WHERE adviser_id IS NOT NULL AND is_active = 1'),
    'awaiting_review' => $yearId ? count_query($pdo, "SELECT COUNT(*) FROM submission_status ss JOIN section_subject_teachers sst ON sst.id = ss.section_subject_teacher_id WHERE ss.status = 'submitted_for_review' AND sst.school_year_id = ?", [$yearId]) : 0,
    'published' => $yearId ? count_query($pdo, "SELECT COUNT(*) FROM submission_status ss JOIN section_subject_teachers sst ON sst.id = ss.section_subject_teacher_id WHERE ss.status = 'published' AND sst.school_year_id = ?", [$yearId]) : 0,
];

function stat_card(string $label, int $value, string $icon, string $colorClasses, ?string $href = null): string
{
    $inner = '<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5 flex items-center gap-4 hover:shadow-md transition-shadow">
        <div class="w-12 h-12 rounded-full flex items-center justify-center flex-shrink-0 ' . $colorClasses . '">
          ' . icon_svg($icon, 'w-6 h-6') . '
        </div>
        <div>
          <div class="text-2xl font-semibold text-slate-800">' . $value . '</div>
          <div class="text-xs text-slate-500">' . h($label) . '</div>
        </div>
      </div>';
    return $href ? '<a href="' . h(url($href)) . '" class="block">' . $inner . '</a>' : $inner;
}

$quickLinks = [
    ['label' => 'School Years', 'href' => '/admin/school_years.php', 'icon' => 'calendar'],
    ['label' => 'Sections', 'href' => '/admin/sections.php', 'icon' => 'sections'],
    ['label' => 'Import Sections', 'href' => '/admin/import_sections.php', 'icon' => 'sections'],
    ['label' => 'Subjects', 'href' => '/admin/subjects.php', 'icon' => 'subjects'],
    ['label' => 'Weight Profiles', 'href' => '/admin/weight_profiles.php', 'icon' => 'subjects'],
    ['label' => 'Assignments', 'href' => '/admin/assignments.php', 'icon' => 'teachers'],
    ['label' => 'Bulk Assign', 'href' => '/admin/bulk_assign.php', 'icon' => 'teachers'],
    ['label' => 'Head Teachers', 'href' => '/admin/head_teachers.php', 'icon' => 'star'],
    ['label' => 'Students', 'href' => '/admin/students.php', 'icon' => 'students'],
    ['label' => 'Import Students', 'href' => '/admin/import_students.php', 'icon' => 'students'],
    ['label' => 'Users', 'href' => '/admin/users.php', 'icon' => 'students'],
];

render_header('Dashboard', 'Overview of your school\'s grade consolidation setup.');
?>
<?php if (!$year): ?>
<div class="mb-6 px-4 py-3 rounded-lg text-sm bg-amber-50 text-amber-700 border border-amber-200">
  No active school year is set. <a href="<?= h(url('/admin/school_years.php')) ?>" class="underline font-medium">Set one</a> to see section/student counts here.
</div>
<?php else: ?>
<div class="mb-6 text-sm text-slate-500">Active school year: <span class="font-medium text-slate-700"><?= h($year['year_label']) ?></span></div>
<?php endif; ?>

<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
  <?= stat_card('Students', $stats['students'], 'students', 'bg-violet-100 text-violet-600', '/admin/students.php') ?>
  <?= stat_card('Sections', $stats['sections'], 'sections', 'bg-blue-100 text-blue-600', '/admin/sections.php') ?>
  <?= stat_card('Subjects', $stats['subjects'], 'subjects', 'bg-purple-100 text-purple-600', '/admin/subjects.php') ?>
  <?= stat_card('Teachers', $stats['teachers'], 'teachers', 'bg-emerald-100 text-emerald-600', '/admin/users.php') ?>
  <?= stat_card('Also Head Teachers', $stats['head_teachers'], 'star', 'bg-indigo-100 text-indigo-600', '/admin/head_teachers.php') ?>
  <?= stat_card('Also Advisers', $stats['advisers'], 'teachers', 'bg-sky-100 text-sky-600', '/admin/sections.php') ?>
  <?= stat_card('Awaiting Review', $stats['awaiting_review'], 'clock', 'bg-amber-100 text-amber-600') ?>
  <?= stat_card('Published (this year)', $stats['published'], 'check', 'bg-teal-100 text-teal-600') ?>
</div>

<div class="flex items-center gap-2 mb-3">
  <?= icon_svg('arrow-right', 'w-4 h-4 text-accent-600') ?>
  <h2 class="text-sm font-semibold text-slate-500 uppercase tracking-wide">Quick Links</h2>
</div>
<div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
  <?php foreach ($quickLinks as $link): ?>
    <a href="<?= h(url($link['href'])) ?>" data-search="<?= h($link['label']) ?>" class="searchable-item flex items-center justify-between gap-3 bg-white border border-slate-200 rounded-xl p-4 hover:border-accent-300 hover:bg-accent-50 transition-colors">
      <span class="flex items-center gap-3">
        <span class="w-9 h-9 rounded-lg bg-accent-50 flex items-center justify-center flex-shrink-0"><?= icon_svg($link['icon'], 'w-4 h-4 text-accent-600') ?></span>
        <span class="text-sm font-medium text-slate-700"><?= h($link['label']) ?></span>
      </span>
      <?= icon_svg('chevron-right', 'w-4 h-4 text-slate-300') ?>
    </a>
  <?php endforeach; ?>
</div>
<?php render_footer(); ?>
