<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

const STATUS_LABELS = [
    'not_started' => 'Not Started',
    'in_progress' => 'In Progress',
    'submitted_for_review' => 'Submitted for Review',
    'returned_for_revision' => 'Returned for Revision',
    'published' => 'Published',
];

const STATUS_CLASSES = [
    'not_started' => 'bg-slate-100 text-slate-600',
    'in_progress' => 'bg-amber-100 text-amber-700',
    'submitted_for_review' => 'bg-blue-100 text-blue-700',
    'returned_for_revision' => 'bg-rose-100 text-rose-700',
    'published' => 'bg-emerald-100 text-emerald-700',
];

function status_badge(?string $status): string
{
    $status = $status ?? 'not_started';
    $label = STATUS_LABELS[$status] ?? $status;
    $classes = STATUS_CLASSES[$status] ?? 'bg-slate-100 text-slate-600';
    return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ' . $classes . '">' . htmlspecialchars($label) . '</span>';
}

/** Shared line-icon set (simple stroke primitives) used across dashboards/quick links/cards. */
function icon_paths(string $name): string
{
    return match ($name) {
        'students' => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.5 3-6 7-6s7 2.5 7 6"/>',
        'sections' => '<line x1="4" y1="7" x2="20" y2="7"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="17" x2="20" y2="17"/>',
        'subjects' => '<path d="M4 5c0-1 .8-1.5 2-1.5h6v15H6c-1.2 0-2 .5-2 1.5V5z"/><path d="M20 5c0-1-.8-1.5-2-1.5h-6v15h6c1.2 0 2 .5 2 1.5V5z"/>',
        'teachers' => '<rect x="3" y="8" width="18" height="11" rx="2"/><path d="M8 8V6c0-1.1.9-2 2-2h4c1.1 0 2 .9 2 2v2"/>',
        'clock' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7v5l3.5 2"/>',
        'check' => '<circle cx="12" cy="12" r="8.5"/><path d="M8.5 12.5l2.5 2.5 5-5.5"/>',
        'search' => '<circle cx="10" cy="10" r="6"/><line x1="16" y1="16" x2="21" y2="21"/>',
        'bell' => '<path d="M12 3a5 5 0 0 0-5 5v3.5c0 .8-.3 1.6-.9 2.2L5 15h14l-1.1-1.3c-.6-.6-.9-1.4-.9-2.2V8a5 5 0 0 0-5-5z"/><path d="M9.5 18a2.5 2.5 0 0 0 5 0"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="3" x2="8" y2="7"/><line x1="16" y1="3" x2="16" y2="7"/>',
        'chevron-right' => '<path d="M9 6l6 6-6 6"/>',
        'map-pin' => '<path d="M12 21s7-6.5 7-11a7 7 0 1 0-14 0c0 4.5 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/>',
        'compass' => '<circle cx="12" cy="12" r="9"/><path d="M15 9l-2 5-5 2 2-5z"/>',
        'bank' => '<path d="M3 10l9-6 9 6"/><line x1="4" y1="10" x2="20" y2="10"/><line x1="5" y1="10" x2="5" y2="19"/><line x1="9" y1="10" x2="9" y2="19"/><line x1="15" y1="10" x2="15" y2="19"/><line x1="19" y1="10" x2="19" y2="19"/><line x1="3" y1="20" x2="21" y2="20"/>',
        'flag' => '<line x1="5" y1="3" x2="5" y2="21"/><path d="M5 4h11l-2.5 4L16 12H5"/>',
        'ship' => '<path d="M4 15l1.5 5h13L20 15"/><path d="M6 15V8h12v7"/><line x1="12" y1="3" x2="12" y2="8"/><path d="M12 3l4 2"/>',
        'building' => '<rect x="6" y="3" width="12" height="18"/><rect x="9" y="7" width="2" height="2"/><rect x="13" y="7" width="2" height="2"/><rect x="9" y="12" width="2" height="2"/><rect x="13" y="12" width="2" height="2"/><rect x="10" y="17" width="4" height="4"/>',
        'star' => '<path d="M12 3l2.6 5.8 6.4.6-4.8 4.3 1.4 6.3L12 16.9 6.4 20l1.4-6.3L3 9.4l6.4-.6z"/>',
        'clipboard' => '<rect x="6" y="4" width="12" height="16" rx="1"/><rect x="9" y="2" width="6" height="4" rx="1"/><line x1="9" y1="10" x2="15" y2="10"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="16" x2="13" y2="16"/>',
        'arrow-right' => '<line x1="4" y1="12" x2="20" y2="12"/><path d="M14 6l6 6-6 6"/>',
        'lock' => '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'shield' => '<path d="M12 3l7 3v6c0 5-3.5 8-7 9-3.5-1-7-4-7-9V6z"/>',
        'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off' => '<path d="M3 3l18 18"/><path d="M10.6 5.2A10.6 10.6 0 0 1 12 5c6.5 0 10 7 10 7a15.6 15.6 0 0 1-3.1 4.1M6.4 6.4A15.6 15.6 0 0 0 2 12s3.5 7 10 7c1.4 0 2.7-.3 3.9-.8"/><path d="M9.5 9.5a3 3 0 0 0 4.2 4.2"/>',
        'graduation-cap' => '<path d="M12 4L3 8l9 4 9-4-9-4z"/><path d="M6 10.5V15c0 1.5 2.7 3 6 3s6-1.5 6-3v-4.5"/><path d="M21 8v5"/>',
        'download' => '<path d="M12 3v12"/><path d="M7 10l5 5 5-5"/><path d="M5 21h14"/>',
        default => '',
    };
}

function icon_svg(string $name, string $class = 'w-5 h-5'): string
{
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="' . htmlspecialchars($class) . '">' . icon_paths($name) . '</svg>';
}

/** Deterministic pastel-on-solid color pair for an avatar, based on user id. */
function avatar_color(int $userId): string
{
    $palette = ['bg-indigo-600', 'bg-violet-600', 'bg-blue-600', 'bg-emerald-600', 'bg-amber-600', 'bg-rose-600', 'bg-teal-600'];
    return $palette[$userId % count($palette)];
}

function avatar_initials(string $fullName): string
{
    $words = preg_split('/\s+/', trim($fullName));
    $words = array_values(array_filter($words, fn($w) => $w !== ''));
    if (!$words) {
        return '?';
    }
    $first = mb_strtoupper(mb_substr($words[0], 0, 1));
    $last = count($words) > 1 ? mb_strtoupper(mb_substr($words[count($words) - 1], 0, 1)) : '';
    return $first . $last;
}

/**
 * Every non-admin account is a Subject Teacher (base capability). Adviser and Head
 * Teacher nav sections appear on top of that only when the account actually holds those
 * capabilities right now (sections.adviser_id / head_teacher_assignments) — they are not
 * separate exclusive roles, since a real person is very often both at once.
 */
function nav_items(array $user): array
{
    if ($user['role'] === 'admin') {
        return [
            'Dashboard' => 'admin/dashboard.php',
            'School Years' => 'admin/school_years.php',
            'Grade Levels' => 'admin/grade_levels.php',
            'Sections' => 'admin/sections.php',
            'Subjects' => 'admin/subjects.php',
            'Weight Profiles' => 'admin/weight_profiles.php',
            'Assignments' => 'admin/assignments.php',
            'Head Teachers' => 'admin/head_teachers.php',
            'Students' => 'admin/students.php',
            'Users' => 'admin/users.php',
        ];
    }

    $items = ['My Classes' => 'teacher/index.php'];
    $pdo = db();

    $claimStmt = $pdo->prepare('SELECT 1 FROM claim_eligibility WHERE teacher_id = ? AND is_active = 1 LIMIT 1');
    $claimStmt->execute([$user['id']]);
    if ($claimStmt->fetchColumn()) {
        $items['Claim a Class'] = 'teacher/claim.php';
    }

    $htStmt = $pdo->prepare('SELECT 1 FROM head_teacher_assignments WHERE head_teacher_id = ? AND is_active = 1 LIMIT 1');
    $htStmt->execute([$user['id']]);
    if ($htStmt->fetchColumn()) {
        $items['Review Dashboard'] = 'headteacher/dashboard.php';
    }

    $advStmt = $pdo->prepare('SELECT id FROM sections WHERE adviser_id = ? AND is_active = 1 ORDER BY id LIMIT 2');
    $advStmt->execute([$user['id']]);
    $advisedSections = $advStmt->fetchAll(PDO::FETCH_COLUMN);
    if ($advisedSections) {
        // These pages need a section_id — link straight to it when there's exactly one section
        // to disambiguate; with more than one, send them to the section picker instead of
        // guessing, since a plain link to e.g. consolidated.php with no section_id 403s.
        $items['My Section'] = 'adviser/index.php';
        if (count($advisedSections) === 1) {
            $sid = $advisedSections[0];
            $items['Consolidated Grades'] = 'adviser/consolidated.php?section_id=' . $sid;
            $items['Ranking'] = 'adviser/ranking.php?section_id=' . $sid;
            $items['At Risk'] = 'adviser/at_risk.php?section_id=' . $sid;
            $items['Card Slips'] = 'adviser/card_slips.php?section_id=' . $sid;
        } else {
            $items['Consolidated Grades'] = 'adviser/index.php';
            $items['Ranking'] = 'adviser/index.php';
            $items['At Risk'] = 'adviser/index.php';
            $items['Card Slips'] = 'adviser/index.php';
        }
    }

    return $items;
}

/**
 * How many things need this user's attention right now, shown as the top-bar notification
 * badge — real counts only, never a placeholder number. Admin: sections/subjects still
 * awaiting review school-wide. Anyone else: their own submissions returned for revision
 * (something THEY need to act on) plus, if they supervise anything, submissions awaiting
 * THEIR review.
 */
function notification_count(array $user): int
{
    $pdo = db();
    $year = active_school_year();
    if (!$year) {
        return 0;
    }

    if ($user['role'] === 'admin') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM submission_status ss
            JOIN section_subject_teachers sst ON sst.id = ss.section_subject_teacher_id
            WHERE ss.status = 'submitted_for_review' AND sst.school_year_id = ?");
        $stmt->execute([$year['id']]);
        return (int) $stmt->fetchColumn();
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM submission_status ss
        JOIN section_subject_teachers sst ON sst.id = ss.section_subject_teacher_id
        WHERE ss.status = 'returned_for_revision' AND sst.teacher_id = ? AND sst.school_year_id = ?");
    $stmt->execute([$user['id'], $year['id']]);
    $count = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM submission_status ss
        JOIN section_subject_teachers sst ON sst.id = ss.section_subject_teacher_id
        JOIN head_teacher_assignments hta ON hta.subject_id = sst.subject_id AND hta.school_year_id = sst.school_year_id
        WHERE ss.status = 'submitted_for_review' AND hta.head_teacher_id = ? AND hta.is_active = 1 AND sst.school_year_id = ?");
    $stmt->execute([$user['id'], $year['id']]);
    $count += (int) $stmt->fetchColumn();

    return $count;
}

function render_header(string $title, ?string $subtitle = null): void
{
    $user = current_user();
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title) ?> · TAPAT</title>
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = {
  theme: {
    extend: {
      colors: { accent: { 50:'#eef2ff',100:'#e0e7ff',500:'#6366f1',600:'#4f46e5',700:'#4338ca' } }
    }
  }
}
</script>
<link rel="stylesheet" href="<?= htmlspecialchars(url('/assets/css/app.css')) ?>">
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen no-print">
<?php if ($user): ?>
<?php $notifCount = notification_count($user); ?>
<div class="flex min-h-screen">
  <aside class="w-64 bg-white border-r border-slate-200 flex-shrink-0 no-print flex flex-col sticky top-0 h-screen">
    <div class="px-5 py-4 border-b border-slate-200">
      <div class="font-semibold text-accent-700 text-lg">TAPAT</div>
      <div class="text-xs text-slate-500">Grade Consolidation System</div>
    </div>
    <nav class="px-3 py-4 space-y-1 flex-1 overflow-y-auto">
      <?php foreach (nav_items($user) as $label => $path): ?>
        <a href="<?= htmlspecialchars(url($path)) ?>" class="block px-3 py-2 rounded-lg text-sm font-medium text-slate-600 hover:bg-accent-50 hover:text-accent-700"><?= htmlspecialchars($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="px-3 py-4 border-t border-slate-200">
      <div class="px-3 text-sm text-slate-700 font-medium"><?= htmlspecialchars($user['full_name']) ?></div>
      <div class="px-3 text-xs text-slate-400 mb-2"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $user['role']))) ?></div>
      <a href="<?= htmlspecialchars(url('/logout.php')) ?>" class="block px-3 py-2 rounded-lg text-sm text-rose-600 hover:bg-rose-50">Log out</a>
    </div>
  </aside>
  <div class="flex-1 flex flex-col min-w-0">
    <header class="flex items-center gap-4 px-8 py-3 bg-white border-b border-slate-200 no-print">
      <div class="relative flex-1 max-w-md">
        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"><?= icon_svg('search', 'w-4 h-4') ?></span>
        <input type="text" id="page-search" placeholder="Search this page…" class="w-full pl-9 pr-3 py-2 text-sm bg-slate-50 border border-slate-200 rounded-full focus:outline-none focus:ring-2 focus:ring-accent-500 focus:bg-white">
      </div>
      <div class="flex items-center gap-3 ml-auto">
        <div class="relative text-slate-400">
          <?= icon_svg('bell', 'w-5 h-5') ?>
          <?php if ($notifCount > 0): ?>
          <span class="absolute -top-1.5 -right-1.5 min-w-[16px] h-4 px-1 flex items-center justify-center rounded-full bg-rose-500 text-white text-[10px] font-semibold"><?= $notifCount > 9 ? '9+' : $notifCount ?></span>
          <?php endif; ?>
        </div>
        <div class="hidden sm:flex items-center gap-1.5 px-3 py-1.5 bg-slate-50 border border-slate-200 rounded-full text-xs text-slate-500">
          <?= icon_svg('calendar', 'w-3.5 h-3.5') ?>
          <?= htmlspecialchars(date('F j, Y')) ?>
        </div>
        <div class="relative">
          <div class="w-9 h-9 rounded-full <?= avatar_color((int) $user['id']) ?> text-white flex items-center justify-center text-xs font-semibold"><?= htmlspecialchars(avatar_initials($user['full_name'])) ?></div>
          <span class="absolute bottom-0 right-0 w-2.5 h-2.5 rounded-full bg-emerald-500 border-2 border-white"></span>
        </div>
      </div>
    </header>
    <main class="flex-1 p-8 relative overflow-hidden">
      <div class="deco-blob" aria-hidden="true"></div>
      <div class="relative">
        <h1 class="text-2xl font-semibold text-slate-800"><?= htmlspecialchars($title) ?></h1>
        <?php if ($subtitle): ?><p class="text-sm text-slate-500 mt-1 mb-6"><?= htmlspecialchars($subtitle) ?></p><?php else: ?><div class="mb-6"></div><?php endif; ?>
<?php else: ?>
  <main class="flex-1">
<?php endif; ?>
    <?php foreach (flash_take() as $flash): ?>
      <div class="mb-4 mx-auto max-w-md px-4 py-3 rounded-lg text-sm <?= $flash['type'] === 'error' ? 'bg-rose-50 text-rose-700 border border-rose-200' : 'bg-emerald-50 text-emerald-700 border border-emerald-200' ?>">
        <?= htmlspecialchars($flash['message']) ?>
      </div>
    <?php endforeach; ?>
    <?php
}

function render_footer(): void
{
    $user = current_user();
    ?>
    <?php if ($user): ?>
        <footer class="mt-10 pt-4 border-t border-slate-200 text-center text-[11px] text-slate-400 leading-relaxed no-print">
          <div class="font-semibold text-slate-500">PROJECT TAPAT</div>
          <div>Teacher Assessment, Performance Aggregation and Tracking System</div>
          <div class="italic">&ldquo;Tapat na Marka, Maayos na Proseso, Maaasahang Resulta.&rdquo;</div>
          <div class="mt-1">Created by: Jay Mar V. Canturia, Teacher I</div>
        </footer>
      </div>
    <?php endif; ?>
  </main>
<?php if ($user): ?>
  </div>
</div>
<?php endif; ?>
<script src="<?= htmlspecialchars(url('/assets/js/app.js')) ?>"></script>
</body>
</html>
    <?php
}
