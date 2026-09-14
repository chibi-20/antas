<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';

$user = require_role(['subject_teacher', 'admin']);
$pdo = db();
$year = require_active_school_year();

$sectionId = (int) ($_GET['section_id'] ?? 0);
$subjectId = (int) ($_GET['subject_id'] ?? 0);
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$perPage = 25;
$page = max(1, (int) ($_GET['page'] ?? 1));

$where = "WHERE hta.head_teacher_id = ? AND hta.is_active = 1 AND sst.school_year_id = ?
      AND ger.finalized_at IS NOT NULL
      AND (geh.old_transmuted_grade IS NULL OR geh.new_transmuted_grade IS NULL OR geh.old_transmuted_grade <> geh.new_transmuted_grade)";
$params = [$user['id'], $year['id']];

if ($sectionId) {
    $where .= ' AND sec.id = ?';
    $params[] = $sectionId;
}
if ($subjectId) {
    $where .= ' AND sub.id = ?';
    $params[] = $subjectId;
}
if ($dateFrom !== '') {
    $where .= ' AND ger.reviewed_at >= ?';
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where .= ' AND ger.reviewed_at <= ?';
    $params[] = $dateTo . ' 23:59:59';
}

$baseFrom = 'FROM grade_edit_history geh
    JOIN grade_edit_requests ger ON ger.id = geh.edit_request_id
    JOIN section_subject_teachers sst ON sst.id = ger.section_subject_teacher_id
    JOIN subjects sub ON sub.id = sst.subject_id
    JOIN sections sec ON sec.id = sst.section_id
    JOIN grade_levels gl ON gl.id = sec.grade_level_id
    JOIN students st ON st.id = geh.student_id
    JOIN users req_u ON req_u.id = ger.requested_by
    JOIN head_teacher_assignments hta ON hta.subject_id = sst.subject_id AND hta.school_year_id = sst.school_year_id';

$countStmt = $pdo->prepare("SELECT COUNT(*) $baseFrom $where");
$countStmt->execute($params);
$totalCount = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("SELECT geh.old_transmuted_grade, geh.new_transmuted_grade, ger.term, ger.reason, ger.reviewed_at,
        st.full_name AS student_name, sub.subject_name, gl.name AS grade_level, sec.section_name,
        req_u.full_name AS requested_by_name
    $baseFrom
    $where
    ORDER BY ger.reviewed_at DESC
    LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$history = $stmt->fetchAll();

$sections = get_supervised_sections($user['id'], (int) $year['id']);
$supervisedSubjectIds = get_supervised_subject_ids($user['id'], (int) $year['id']);
$subjects = [];
if ($supervisedSubjectIds) {
    $placeholders = implode(',', array_fill(0, count($supervisedSubjectIds), '?'));
    $subjStmt = $pdo->prepare("SELECT id, subject_name FROM subjects WHERE id IN ($placeholders) ORDER BY subject_name");
    $subjStmt->execute($supervisedSubjectIds);
    $subjects = $subjStmt->fetchAll();
}

$hasFilters = $sectionId || $subjectId || $dateFrom !== '' || $dateTo !== '';

// Preserves the current filters (but not the page number) on the pagination links below.
$filterQuery = array_filter([
    'section_id' => $sectionId ?: null,
    'subject_id' => $subjectId ?: null,
    'date_from' => $dateFrom !== '' ? $dateFrom : null,
    'date_to' => $dateTo !== '' ? $dateTo : null,
], fn($v) => $v !== null);

render_header('Edit History', 'Grades changed as a result of an approved post-publish edit request.');
echo ht_tab_nav('edit_history');
?>
<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 mb-6">
  <form method="get" class="flex flex-wrap items-end gap-3">
    <div>
      <label class="block text-xs font-medium text-slate-500 mb-1">Section</label>
      <select name="section_id" class="px-3 py-1.5 border border-slate-300 rounded-lg text-sm">
        <option value="0">All sections</option>
        <?= select_options(array_map(fn($s) => ['id' => $s['id'], 'label' => $s['grade_level'] . ' - ' . $s['section_name']], $sections), 'id', 'label', $sectionId ?: null) ?>
      </select>
    </div>
    <div>
      <label class="block text-xs font-medium text-slate-500 mb-1">Subject</label>
      <select name="subject_id" class="px-3 py-1.5 border border-slate-300 rounded-lg text-sm">
        <option value="0">All subjects</option>
        <?= select_options($subjects, 'id', 'subject_name', $subjectId ?: null) ?>
      </select>
    </div>
    <div>
      <label class="block text-xs font-medium text-slate-500 mb-1">From</label>
      <input type="date" name="date_from" value="<?= h($dateFrom) ?>" class="px-3 py-1.5 border border-slate-300 rounded-lg text-sm">
    </div>
    <div>
      <label class="block text-xs font-medium text-slate-500 mb-1">To</label>
      <input type="date" name="date_to" value="<?= h($dateTo) ?>" class="px-3 py-1.5 border border-slate-300 rounded-lg text-sm">
    </div>
    <button type="submit" class="px-4 py-1.5 rounded-lg text-sm bg-accent-600 hover:bg-accent-700 text-white font-medium">Filter</button>
    <?php if ($hasFilters): ?>
      <a href="<?= h(url('/headteacher/edit_history.php')) ?>" class="px-4 py-1.5 rounded-lg text-sm text-slate-500 hover:bg-slate-100">Clear</a>
    <?php endif; ?>
  </form>
</div>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
  <table class="w-full text-sm">
    <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
      <tr>
        <th class="text-left px-4 py-3">Student</th>
        <th class="text-left px-4 py-3">Subject</th>
        <th class="text-left px-4 py-3">Section</th>
        <th class="text-left px-4 py-3">Term</th>
        <th class="text-left px-4 py-3">Old &rarr; New</th>
        <th class="text-left px-4 py-3">Requested By</th>
        <th class="text-left px-4 py-3">Reason</th>
        <th class="text-left px-4 py-3">Changed</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-slate-100">
      <?php foreach ($history as $h): ?>
      <tr>
        <td class="px-4 py-3 font-medium"><?= h($h['student_name']) ?></td>
        <td class="px-4 py-3 text-slate-600"><?= h($h['subject_name']) ?></td>
        <td class="px-4 py-3 text-slate-600"><?= h($h['grade_level'] . ' - ' . $h['section_name']) ?></td>
        <td class="px-4 py-3 text-slate-600">Term <?= (int) $h['term'] ?></td>
        <td class="px-4 py-3">
          <span class="<?= $h['old_transmuted_grade'] !== null ? grade_display_class((float) $h['old_transmuted_grade']) : 'text-slate-400' ?>"><?= $h['old_transmuted_grade'] !== null ? h($h['old_transmuted_grade']) : '—' ?></span>
          <?= icon_svg('arrow-right', 'w-3 h-3 inline text-slate-400') ?>
          <span class="font-semibold <?= $h['new_transmuted_grade'] !== null ? (grade_display_class((float) $h['new_transmuted_grade']) ?: 'text-accent-700') : 'text-slate-400' ?>"><?= $h['new_transmuted_grade'] !== null ? h($h['new_transmuted_grade']) : '—' ?></span>
        </td>
        <td class="px-4 py-3 text-slate-600"><?= h($h['requested_by_name']) ?></td>
        <td class="px-4 py-3 text-slate-500 max-w-xs"><?= h($h['reason']) ?></td>
        <td class="px-4 py-3 text-slate-400 whitespace-nowrap"><?= h($h['reviewed_at'] ? date('M j, Y', strtotime($h['reviewed_at'])) : '—') ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$history): ?>
      <tr><td colspan="8" class="px-4 py-6 text-center text-slate-400"><?= $hasFilters ? 'No grade changes match these filters.' : 'No grade changes from approved edit requests yet.' ?></td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if ($totalCount > 0): ?>
<div class="flex items-center justify-between mt-4 text-sm text-slate-500">
  <div>Showing <?= $offset + 1 ?>&ndash;<?= min($offset + $perPage, $totalCount) ?> of <?= $totalCount ?></div>
  <div class="flex gap-1">
    <?php if ($page > 1): ?>
      <a href="<?= h(url('/headteacher/edit_history.php?' . http_build_query($filterQuery + ['page' => $page - 1]))) ?>" class="px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50">Previous</a>
    <?php endif; ?>
    <span class="px-3 py-1.5">Page <?= $page ?> of <?= $totalPages ?></span>
    <?php if ($page < $totalPages): ?>
      <a href="<?= h(url('/headteacher/edit_history.php?' . http_build_query($filterQuery + ['page' => $page + 1]))) ?>" class="px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50">Next</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php render_footer(); ?>
