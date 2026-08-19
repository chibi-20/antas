<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';

$sectionId = (int) ($_GET['section_id'] ?? 0);
$term = (int) ($_GET['term'] ?? 1);
if ($term < 1 || $term > 3) {
    $term = 1;
}

$section = require_own_section($sectionId);
$pdo = db();

$stmt = $pdo->prepare('SELECT gav.average, gav.rank_in_section, st.full_name, st.lrn
    FROM general_average_view gav
    JOIN students st ON st.id = gav.student_id
    WHERE gav.section_id = ? AND gav.term = ? AND gav.school_year_id = ?
    ORDER BY gav.rank_in_section');
$stmt->execute([$sectionId, $term, $section['school_year_id']]);
$ranking = $stmt->fetchAll();

render_header($section['grade_level'] . ' - ' . $section['section_name'] . ' · Ranking');
?>
<form method="get" class="flex gap-1 mb-6">
  <input type="hidden" name="section_id" value="<?= $sectionId ?>">
  <?php for ($t = 1; $t <= 3; $t++): ?>
    <button type="submit" name="term" value="<?= $t ?>" class="px-3 py-1.5 rounded-lg text-sm <?= $t === $term ? 'bg-accent-600 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">Term <?= $t ?></button>
  <?php endfor; ?>
</form>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden max-w-xl">
  <table class="w-full text-sm">
    <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
      <tr><th class="text-left px-4 py-3">Rank</th><th class="text-left px-4 py-3">Student</th><th class="text-left px-4 py-3">General Average</th></tr>
    </thead>
    <tbody class="divide-y divide-slate-100">
      <?php foreach ($ranking as $r): ?>
      <tr>
        <td class="px-4 py-3 font-semibold <?= (int) $r['rank_in_section'] <= 3 ? 'text-accent-700' : 'text-slate-600' ?>">#<?= (int) $r['rank_in_section'] ?></td>
        <td class="px-4 py-3 font-medium"><?= h($r['full_name']) ?></td>
        <td class="px-4 py-3"><?= h($r['average']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$ranking): ?>
      <tr><td colspan="3" class="px-4 py-6 text-center text-slate-400">No published subjects yet for this term — ranking will appear once at least one subject is published.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
<?php render_footer(); ?>
