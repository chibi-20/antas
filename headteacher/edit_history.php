<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';

$user = require_role(['subject_teacher', 'admin']);
$pdo = db();
$year = require_active_school_year();

// Finalized (republished) edit-request diffs for subjects this Head Teacher supervises —
// only rows where the grade actually changed, skipping no-op entries.
$stmt = $pdo->prepare("SELECT geh.old_transmuted_grade, geh.new_transmuted_grade, ger.term, ger.reason, ger.reviewed_at,
        st.full_name AS student_name, sub.subject_name, gl.name AS grade_level, sec.section_name,
        req_u.full_name AS requested_by_name
    FROM grade_edit_history geh
    JOIN grade_edit_requests ger ON ger.id = geh.edit_request_id
    JOIN section_subject_teachers sst ON sst.id = ger.section_subject_teacher_id
    JOIN subjects sub ON sub.id = sst.subject_id
    JOIN sections sec ON sec.id = sst.section_id
    JOIN grade_levels gl ON gl.id = sec.grade_level_id
    JOIN students st ON st.id = geh.student_id
    JOIN users req_u ON req_u.id = ger.requested_by
    JOIN head_teacher_assignments hta ON hta.subject_id = sst.subject_id AND hta.school_year_id = sst.school_year_id
    WHERE hta.head_teacher_id = ? AND hta.is_active = 1 AND sst.school_year_id = ?
      AND ger.finalized_at IS NOT NULL
      AND (geh.old_transmuted_grade IS NULL OR geh.new_transmuted_grade IS NULL OR geh.old_transmuted_grade <> geh.new_transmuted_grade)
    ORDER BY ger.reviewed_at DESC");
$stmt->execute([$user['id'], $year['id']]);
$history = $stmt->fetchAll();

render_header('Edit History', 'Grades changed as a result of an approved post-publish edit request.');
echo ht_tab_nav('edit_history');
?>
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
      <tr><td colspan="8" class="px-4 py-6 text-center text-slate-400">No grade changes from approved edit requests yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
<?php render_footer(); ?>
