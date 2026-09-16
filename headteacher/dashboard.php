<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';

$user = require_role(['subject_teacher', 'admin']);
$pdo = db();
$year = require_active_school_year();

$teacherId = (int) ($_GET['teacher_id'] ?? 0);
$subjectId = (int) ($_GET['subject_id'] ?? 0);

if ($teacherId && $subjectId) {
    // Detail view: every section a specific teacher covers for one supervised subject.
    $stmt = $pdo->prepare('SELECT sst.id, gl.name AS grade_level, sec.section_name, sub.subject_name, u.full_name AS teacher_name
        FROM section_subject_teachers sst
        JOIN head_teacher_assignments hta ON hta.subject_id = sst.subject_id AND hta.school_year_id = sst.school_year_id
        JOIN sections sec ON sec.id = sst.section_id
        JOIN grade_levels gl ON gl.id = sec.grade_level_id
        JOIN subjects sub ON sub.id = sst.subject_id
        JOIN users u ON u.id = sst.teacher_id
        WHERE hta.head_teacher_id = ? AND hta.is_active = 1 AND sst.school_year_id = ? AND sst.is_active = 1
          AND sst.teacher_id = ? AND sst.subject_id = ?
        ORDER BY gl.sort_order, sec.section_name');
    $stmt->execute([$user['id'], $year['id'], $teacherId, $subjectId]);
    $assignments = $stmt->fetchAll();

    if (!$assignments) {
        forbidden('No sections found for this teacher/subject under your supervision.');
    }

    $statusStmt = $pdo->prepare('SELECT term, status FROM submission_status WHERE section_subject_teacher_id = ?');

    // Which (section, term) cells currently have a pending edit request — overlaid onto the
    // status badge below so a Head Teacher sees "Request for Edit" instead of a plain
    // "Published" that gives no hint anything needs their attention.
    $assignmentIds = array_column($assignments, 'id');
    $pendingEdits = [];
    if ($assignmentIds) {
        $placeholders = implode(',', array_fill(0, count($assignmentIds), '?'));
        $pendingStmt = $pdo->prepare("SELECT section_subject_teacher_id, term FROM grade_edit_requests WHERE status = 'pending' AND section_subject_teacher_id IN ($placeholders)");
        $pendingStmt->execute($assignmentIds);
        foreach ($pendingStmt->fetchAll() as $row) {
            $pendingEdits[$row['section_subject_teacher_id'] . '-' . $row['term']] = true;
        }
    }

    render_header($assignments[0]['teacher_name'] . ' · ' . $assignments[0]['subject_name']);
    ?>
    <a href="<?= h(url('/headteacher/dashboard.php')) ?>" class="inline-block mb-4 text-sm text-accent-600 dark:text-accent-400 hover:underline">&larr; Back to Teachers</a>
    <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 dark:bg-slate-900 text-slate-500 dark:text-slate-400 text-xs uppercase">
          <tr>
            <th class="text-left px-4 py-3">Section</th>
            <th class="text-left px-4 py-3">Term 1</th>
            <th class="text-left px-4 py-3">Term 2</th>
            <th class="text-left px-4 py-3">Term 3</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
          <?php foreach ($assignments as $a):
              $statusStmt->execute([$a['id']]);
              $statuses = array_column($statusStmt->fetchAll(), 'status', 'term');
          ?>
          <tr>
            <td class="px-4 py-3 font-medium"><?= h($a['grade_level'] . ' - ' . $a['section_name']) ?></td>
            <?php for ($t = 1; $t <= 3; $t++):
                $cellStatus = isset($pendingEdits[$a['id'] . '-' . $t]) ? 'edit_requested' : ($statuses[$t] ?? 'not_started');
            ?>
            <td class="px-4 py-3">
              <a href="<?= h(url('/headteacher/review.php?sst_id=' . $a['id'] . '&term=' . $t)) ?>" class="hover:underline">
                <?= status_badge($cellStatus) ?>
              </a>
            </td>
            <?php endfor; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php render_footer(); ?>
    <?php
    exit;
}

// Card view: one card per teacher/subject you supervise, grouping together every section
// that teacher covers for that subject — click through to see just their sections.
$stmt = $pdo->prepare('SELECT sst.teacher_id, u.full_name AS teacher_name, sst.subject_id, sub.subject_name,
        COUNT(DISTINCT sst.id) AS section_count,
        SUM(CASE WHEN ss.status = "submitted_for_review" THEN 1 ELSE 0 END) AS awaiting_review_count,
        SUM(CASE WHEN ss.status = "published" THEN 1 ELSE 0 END) AS published_count,
        MAX(ss.submitted_at) AS latest_submission
    FROM section_subject_teachers sst
    JOIN head_teacher_assignments hta ON hta.subject_id = sst.subject_id AND hta.school_year_id = sst.school_year_id
    JOIN users u ON u.id = sst.teacher_id
    JOIN subjects sub ON sub.id = sst.subject_id
    LEFT JOIN submission_status ss ON ss.section_subject_teacher_id = sst.id
    WHERE hta.head_teacher_id = ? AND hta.is_active = 1 AND sst.school_year_id = ? AND sst.is_active = 1
    GROUP BY sst.teacher_id, sst.subject_id, u.full_name, sub.subject_name
    ORDER BY sub.subject_name, u.full_name');
$stmt->execute([$user['id'], $year['id']]);
$groups = $stmt->fetchAll();

// Kept as its own query rather than another JOIN above — grade_edit_requests and
// submission_status are both one-row-per-term against the same section_subject_teachers row,
// so joining both into one GROUP BY would fan out (each pending request multiplied once per
// submission_status row for that assignment) and inflate every other SUM() in that query.
$pendingEditsByGroup = [];
$editStmt = $pdo->prepare('SELECT sst.teacher_id, sst.subject_id, COUNT(*) AS pending_edit_count
    FROM grade_edit_requests ger
    JOIN section_subject_teachers sst ON sst.id = ger.section_subject_teacher_id
    JOIN head_teacher_assignments hta ON hta.subject_id = sst.subject_id AND hta.school_year_id = sst.school_year_id
    WHERE hta.head_teacher_id = ? AND hta.is_active = 1 AND sst.school_year_id = ? AND sst.is_active = 1 AND ger.status = \'pending\'
    GROUP BY sst.teacher_id, sst.subject_id');
$editStmt->execute([$user['id'], $year['id']]);
foreach ($editStmt->fetchAll() as $row) {
    $pendingEditsByGroup[$row['teacher_id'] . '-' . $row['subject_id']] = (int) $row['pending_edit_count'];
}

$pendingCount = count(array_filter($groups, fn($g) => (int) $g['awaiting_review_count'] > 0));
$reviewedCount = count(array_filter($groups, fn($g) => (int) $g['awaiting_review_count'] === 0 && (int) $g['published_count'] > 0));
$teacherCount = count(array_unique(array_column($groups, 'teacher_id')));
$sectionsForReview = array_sum(array_map(fn($g) => (int) $g['section_count'], $groups));

$subjectOptions = [];
foreach ($groups as $g) {
    $subjectOptions[$g['subject_id']] = $g['subject_name'];
}

render_header('Review Dashboard', 'Monitor and review grade consolidation submissions across all classes.');
echo ht_tab_nav('review');
?>
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
  <?php
  $htStatCard = function (string $label, int $value, string $icon, string $colorClasses): void {
      echo '<div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm p-5 flex items-center gap-4">
        <div class="w-12 h-12 rounded-full flex items-center justify-center flex-shrink-0 ' . $colorClasses . '">' . icon_svg($icon, 'w-6 h-6') . '</div>
        <div><div class="text-2xl font-semibold text-slate-800 dark:text-slate-100">' . $value . '</div><div class="text-xs text-slate-500 dark:text-slate-400">' . h($label) . '</div></div>
      </div>';
  };
  $htStatCard('Pending Review', $pendingCount, 'clipboard', 'bg-amber-100 dark:bg-amber-900/40 text-amber-600 dark:text-amber-400');
  $htStatCard('Reviewed', $reviewedCount, 'check', 'bg-blue-100 dark:bg-blue-900/40 text-blue-600');
  $htStatCard('Teachers Supervised', $teacherCount, 'teachers', 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-600 dark:text-emerald-400');
  $htStatCard('Sections For Review', $sectionsForReview, 'sections', 'bg-violet-100 dark:bg-violet-900/40 text-violet-600');
  ?>
</div>

<div class="flex flex-col sm:flex-row gap-3 mb-6">
  <div class="relative flex-1">
    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500"><?= icon_svg('search', 'w-4 h-4') ?></span>
    <input type="text" id="ht-search" placeholder="Search by teacher or subject…" class="w-full pl-9 pr-3 py-2 text-sm border border-slate-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-accent-500">
  </div>
  <select id="ht-status-filter" class="px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 rounded-lg">
    <option value="">All Statuses</option>
    <option value="edit_requested">Request for Edit</option>
    <option value="submitted_for_review">Awaiting Review</option>
    <option value="published">Published</option>
    <option value="not_started">Not Started</option>
  </select>
  <select id="ht-subject-filter" class="px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 rounded-lg">
    <option value="">All Subjects</option>
    <?php foreach ($subjectOptions as $sid => $sname): ?>
      <option value="<?= (int) $sid ?>"><?= h($sname) ?></option>
    <?php endforeach; ?>
  </select>
</div>

<div class="grid gap-4 md:grid-cols-2">
  <?php foreach ($groups as $g):
      $hasPendingEdit = ($pendingEditsByGroup[$g['teacher_id'] . '-' . $g['subject_id']] ?? 0) > 0;
      $cardStatus = $hasPendingEdit ? 'edit_requested'
          : ((int) $g['awaiting_review_count'] > 0 ? 'submitted_for_review' : ((int) $g['published_count'] > 0 ? 'published' : 'not_started'));
      $teacherIdForCard = (int) $g['teacher_id'];
  ?>
  <a href="<?= h(url('/headteacher/dashboard.php?teacher_id=' . $g['teacher_id'] . '&subject_id=' . $g['subject_id'])) ?>"
     class="ht-card block bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm p-6 hover:border-accent-300 hover:shadow-md transition-shadow"
     data-search="<?= h($g['teacher_name'] . ' ' . $g['subject_name']) ?>" data-status="<?= h($cardStatus) ?>" data-subject-id="<?= (int) $g['subject_id'] ?>">
    <div class="flex items-start justify-between mb-4">
      <div class="flex items-center gap-3">
        <div class="w-11 h-11 rounded-full <?= avatar_color($teacherIdForCard) ?> text-white flex items-center justify-center text-sm font-semibold flex-shrink-0"><?= h(avatar_initials($g['teacher_name'])) ?></div>
        <div>
          <div class="font-semibold text-slate-800 dark:text-slate-100"><?= h($g['teacher_name']) ?></div>
          <div class="text-xs font-medium text-accent-600 dark:text-accent-400"><?= h($g['subject_name']) ?></div>
        </div>
      </div>
      <?= status_badge($cardStatus) ?>
    </div>
    <div class="grid grid-cols-2 gap-3 mb-4">
      <div class="bg-slate-50 dark:bg-slate-900 rounded-lg px-3 py-2 flex items-center gap-2">
        <?= icon_svg('sections', 'w-4 h-4 text-slate-400 dark:text-slate-500 flex-shrink-0') ?>
        <div class="text-xs"><span class="font-semibold text-slate-700 dark:text-slate-200"><?= (int) $g['section_count'] ?></span> <span class="text-slate-500 dark:text-slate-400">Section<?= (int) $g['section_count'] === 1 ? '' : 's' ?></span></div>
      </div>
      <div class="bg-slate-50 dark:bg-slate-900 rounded-lg px-3 py-2 flex items-center gap-2">
        <?= icon_svg('calendar', 'w-4 h-4 text-slate-400 dark:text-slate-500 flex-shrink-0') ?>
        <div class="text-xs text-slate-500 dark:text-slate-400">
          <?php if ($g['latest_submission']): ?>
            Submitted <?= h(date('M j, Y', strtotime($g['latest_submission']))) ?>
          <?php else: ?>
            No submissions yet
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg bg-accent-50 dark:bg-accent-900/30 text-accent-700 dark:text-accent-300 text-sm font-medium">
      View & Review Submission <?= icon_svg('arrow-right', 'w-4 h-4') ?>
    </div>
  </a>
  <?php endforeach; ?>
  <?php if (!$groups): ?>
  <div class="text-slate-400 dark:text-slate-500 text-sm">No subjects assigned to you for supervision yet.</div>
  <?php endif; ?>
</div>

<script>
(function () {
  var searchInput = document.getElementById('ht-search');
  var statusFilter = document.getElementById('ht-status-filter');
  var subjectFilter = document.getElementById('ht-subject-filter');

  function applyFilters() {
    var q = searchInput.value.trim().toLowerCase();
    var status = statusFilter.value;
    var subjectId = subjectFilter.value;
    document.querySelectorAll('.ht-card').forEach(function (card) {
      var matchesSearch = !q || (card.dataset.search || '').toLowerCase().indexOf(q) !== -1;
      var matchesStatus = !status || card.dataset.status === status;
      var matchesSubject = !subjectId || card.dataset.subjectId === subjectId;
      card.classList.toggle('hidden', !(matchesSearch && matchesStatus && matchesSubject));
    });
  }

  [searchInput, statusFilter, subjectFilter].forEach(function (el) {
    el.addEventListener('input', applyFilters);
    el.addEventListener('change', applyFilters);
  });
})();
</script>
<?php render_footer(); ?>
