<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/reporting.php';

require_role(['admin']);
$pdo = db();
$year = require_active_school_year();

$term = (int) ($_GET['term'] ?? 1);
if ($term < 1 || $term > 3) {
    $term = 1;
}

$selfPath = fn(array $q = []): string => '/admin/reporting.php?' . http_build_query(['term' => $term] + $q);
$selfUrl = fn(array $q = []): string => url($selfPath($q));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (($_POST['action'] ?? '') === 'freeze') {
        $note = trim((string) ($_POST['note'] ?? ''));
        $note = $note === '' ? null : mb_substr($note, 0, 255);
        $id = report_save_snapshot($pdo, (int) $year['id'], $term, 'manual', (int) current_user()['id'], $note, report_last_change($pdo, (int) $year['id'], $term));
        flash_set('success', 'Term ' . $term . ' report frozen. This copy will not change, even if grades are corrected later.');
        redirect($selfPath(['snapshot' => $id]));
    }
    redirect($selfPath());
}

// Automatic freezing happens here, the first time an admin opens Reporting after a term's
// grades have gone REPORT_AUTO_FREEZE_DAYS without a change.
$justFrozen = report_auto_freeze($pdo, (int) $year['id']);

$live = report_build($pdo, (int) $year['id'], $term);
$snapshots = report_snapshot_list($pdo, (int) $year['id'], $term);
$snapshot = null;
if ($snapshots && ($_GET['view'] ?? '') !== 'live') {
    $wanted = (int) ($_GET['snapshot'] ?? 0);
    $ids = array_map('intval', array_column($snapshots, 'id'));
    $snapshot = report_snapshot_load($pdo, in_array($wanted, $ids, true) ? $wanted : $ids[0]);
}
$R = $snapshot['report'] ?? $live;

$rateRows = $R['rateRows'];
$multiFailTotals = $R['multiFailTotals'];
$driverSubjects = $R['drivers'];
$sectionRates = $R['sections'];
$plByGradeLevel = $R['pl'];
// Student names are never stored in a snapshot, so the expandable lists are always live.
$multiFail = $live['students'];

$popTotals = ['M' => 0, 'F' => 0];
$gradedTotal = 0;
foreach ($rateRows as $r) {
    $popTotals['M'] += $r['pop']['M'];
    $popTotals['F'] += $r['pop']['F'];
    $gradedTotal += $r['graded'];
}
$pct = fn(int $part, int $whole): ?float => report_pct($part, $whole);
$fmtPct = fn(?float $v): string => report_pct_label($v);
$pctLabel = fn(float $v): string => report_pct_label($v);
$maxRate = 0.0;
foreach ($rateRows as $r) {
    $maxRate = max($maxRate, (float) $pct($r['fail']['M'] + $r['fail']['F'], $r['pop']['M'] + $r['pop']['F']));
}
// Chart scale rounded up to a tidy 10% step.
$chartMax = max(10, (int) ceil($maxRate / 10) * 10);
$driverMax = $driverSubjects ? max(array_column($driverSubjects, 'total')) : 0;
$sectionMax = $sectionRates ? max(10, (int) ceil(max(array_column($sectionRates, 'rate')) / 10) * 10) : 10;

// Drift: how the live numbers differ from the snapshot being viewed.
$drift = [];
$correctionsSince = 0;
if ($snapshot) {
    $was = report_totals($R);
    $now = report_totals($live);
    $labels = ['fail' => 'failing 2+ subjects', 'pop' => 'enrolled', 'graded' => 'learners with published grades'];
    foreach ($labels as $k => $label) {
        if ($was[$k] !== $now[$k]) {
            $drift[] = $label . ': ' . number_format($now[$k]) . ' now (was ' . number_format($was[$k]) . ')';
        }
    }
    $cStmt = $pdo->prepare('SELECT COUNT(*) FROM grade_edit_requests ger
        JOIN section_subject_teachers sst ON sst.id = ger.section_subject_teacher_id
        WHERE ger.term = ? AND sst.school_year_id = ? AND ger.finalized_at > ?');
    $cStmt->execute([$term, $year['id'], $snapshot['created_at']]);
    $correctionsSince = (int) $cStmt->fetchColumn();
}
$lastChange = report_last_change($pdo, (int) $year['id'], $term);
// Days are counted in MySQL: PHP and MySQL can run on different time zones.
$quietDays = $lastChange !== null ? (int) $pdo->query('SELECT FLOOR(TIMESTAMPDIFF(SECOND, ' . $pdo->quote($lastChange) . ', NOW()) / 86400)')->fetchColumn() : null;

// --- Term trend. In the frozen view, every term that has a snapshot uses its latest one (with
// that snapshot's own enrollment); other terms, and everything in the live view, are live
// against current enrollment. A term with no published grades shows "—", not 0%.
$liveTrend = report_live_trend($pdo, (int) $year['id']);
$frozenTerms = $snapshot ? report_latest_snapshots($pdo, (int) $year['id']) : [];
if ($snapshot) {
    $frozenTerms[$term] = $snapshot; // the snapshot on screen, even if an older one was picked
}
$trendRows = [];
$trendMaxRate = 0.0;
foreach ($rateRows as $glId => $r) {
    $livePop = (int) ($live['rateRows'][$glId]['pop']['M'] ?? 0) + (int) ($live['rateRows'][$glId]['pop']['F'] ?? 0);
    $rates = [];
    $fails = [];
    $isFrozen = [];
    for ($t = 1; $t <= 3; $t++) {
        if (isset($frozenTerms[$t])) {
            $fr = $frozenTerms[$t]['report']['rateRows'][$glId] ?? null;
            $f = $fr ? $fr['fail']['M'] + $fr['fail']['F'] : 0;
            $rates[$t] = $fr && $fr['graded'] > 0 ? $pct($f, $fr['pop']['M'] + $fr['pop']['F']) : null;
            $isFrozen[$t] = true;
        } else {
            $f = $liveTrend['fail'][$glId][$t] ?? 0;
            $rates[$t] = isset($liveTrend['has'][$glId][$t]) ? $pct($f, $livePop) : null;
            $isFrozen[$t] = false;
        }
        $fails[$t] = $f;
        $trendMaxRate = max($trendMaxRate, (float) $rates[$t]);
    }
    $trendRows[$glId] = ['name' => $r['name'], 'rates' => $rates, 'fail' => $fails, 'frozen' => $isFrozen];
}
$trendMax = max(10, (int) ceil($trendMaxRate / 10) * 10);
// Three ordered shades of one hue: later terms are darker.
$trendShades = [1 => 'bg-rose-200 dark:bg-rose-900', 2 => 'bg-rose-400 dark:bg-rose-600', 3 => 'bg-rose-600 dark:bg-rose-400'];

$bandKeys = array_column(PL_SECTION_BANDS, 'key');
$bandLabels = array_combine($bandKeys, array_column(PL_SECTION_BANDS, 'label'));
$bandRanges = array_combine($bandKeys, array_column(PL_SECTION_BANDS, 'range'));

$multiFailTotal = $multiFailTotals['M'] + $multiFailTotals['F'];
$fmtWhen = fn(?string $ts): string => $ts ? date('M j, Y g:i A', strtotime($ts)) : '—';

render_header('Reporting', 'School-wide reports from published grades · ' . $year['year_label'] . ' · Term ' . $term . '.');
?>
<form method="get" class="flex gap-1 mb-6">
  <?php for ($t = 1; $t <= 3; $t++): ?>
    <button type="submit" name="term" value="<?= $t ?>" class="px-3 py-1.5 rounded-lg text-sm <?= $t === $term ? 'bg-accent-600 text-white' : 'bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700' ?>">Term <?= $t ?></button>
  <?php endfor; ?>
</form>

<?php if ($justFrozen): ?>
<div class="mb-4 px-4 py-3 rounded-lg text-sm bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
  Automatically froze the report for Term <?= h(implode(', Term ', $justFrozen)) ?>: its grades went <?= REPORT_AUTO_FREEZE_DAYS ?> days without any change.
</div>
<?php endif; ?>

<div class="mb-8 rounded-xl border <?= $snapshot ? 'border-indigo-200 dark:border-indigo-800 bg-indigo-50 dark:bg-indigo-950/40' : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800' ?> p-4 shadow-sm">
  <div class="flex flex-wrap items-start gap-4 justify-between">
    <div class="min-w-0 flex-1">
      <?php if ($snapshot): ?>
        <div class="flex items-center gap-2 font-semibold text-indigo-800 dark:text-indigo-200 text-sm">
          <?= icon_svg('lock', 'w-4 h-4') ?> Frozen report · <?= h($fmtWhen($snapshot['created_at'])) ?>
        </div>
        <div class="text-xs text-indigo-700/80 dark:text-indigo-300/80 mt-1">
          <?php if ($snapshot['trigger_type'] === 'auto'): ?>
            Frozen automatically after <?= REPORT_AUTO_FREEZE_DAYS ?> days without grade changes.
          <?php else: ?>
            Frozen manually<?php $by = current(array_filter($snapshots, fn($s) => (int) $s['id'] === (int) $snapshot['id'])); ?><?= $by && $by['created_by_name'] ? ' by ' . h($by['created_by_name']) : '' ?>.
          <?php endif; ?>
          <?php if ($snapshot['note'] && $snapshot['trigger_type'] === 'manual'): ?> Note: “<?= h($snapshot['note']) ?>”.<?php endif; ?>
          Includes grade changes up to <?= h($fmtWhen($snapshot['data_as_of'])) ?>. These numbers will not change.
        </div>
        <?php if ($drift || $correctionsSince): ?>
        <div class="mt-2 text-xs text-amber-700 dark:text-amber-300">
          Live data has changed since this snapshot<?= $correctionsSince ? ' — ' . $correctionsSince . ' grade correction' . ($correctionsSince === 1 ? '' : 's') . ' republished' : '' ?><?= $drift ? ($correctionsSince ? '; ' : ' — ') . h(implode('; ', $drift)) : '' ?>.
        </div>
        <?php else: ?>
        <div class="mt-2 text-xs text-slate-500 dark:text-slate-400">Live numbers still match this snapshot.</div>
        <?php endif; ?>
      <?php else: ?>
        <div class="font-semibold text-slate-700 dark:text-slate-200 text-sm">Live report</div>
        <div class="text-xs text-slate-500 dark:text-slate-400 mt-1">
          <?php if ($snapshots): ?>
            Showing current numbers. Term <?= $term ?> has <?= count($snapshots) ?> frozen snapshot<?= count($snapshots) === 1 ? '' : 's' ?>.
          <?php elseif ($lastChange === null): ?>
            No published grades for Term <?= $term ?> yet.
          <?php else: ?>
            Not frozen yet. This term freezes automatically once its grades go <?= REPORT_AUTO_FREEZE_DAYS ?> days without any change
            — last change <?= h($fmtWhen($lastChange)) ?> (<?= $quietDays === 0 ? 'today' : $quietDays . ' day' . ($quietDays === 1 ? '' : 's') . ' ago' ?>).
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
    <div class="flex flex-wrap items-center gap-2">
      <?php if ($snapshots): ?>
        <div class="inline-flex rounded-lg border border-slate-200 dark:border-slate-600 overflow-hidden text-sm">
          <a href="<?= h($selfUrl()) ?>" class="px-3 py-1.5 <?= $snapshot ? 'bg-indigo-600 text-white' : 'bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700' ?>">Frozen</a>
          <a href="<?= h($selfUrl(['view' => 'live'])) ?>" class="px-3 py-1.5 <?= $snapshot ? 'bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700' : 'bg-indigo-600 text-white' ?>">Live</a>
        </div>
      <?php endif; ?>
      <?php if ($snapshot && count($snapshots) > 1): ?>
        <form method="get" class="inline">
          <input type="hidden" name="term" value="<?= $term ?>">
          <select name="snapshot" onchange="this.form.submit()" class="text-sm rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 px-2 py-1.5">
            <?php foreach ($snapshots as $s): ?>
              <option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === (int) $snapshot['id'] ? 'selected' : '' ?>><?= h($fmtWhen($s['created_at'])) ?> · <?= $s['trigger_type'] === 'auto' ? 'Auto' : 'Manual' ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      <?php endif; ?>
      <a href="<?= h(url('/admin/reporting_export.php') . '?' . http_build_query($snapshot ? ['snapshot' => $snapshot['id']] : ['term' => $term])) ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-600 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700"><?= icon_svg('download', 'w-4 h-4') ?> Download CSV</a>
    </div>
  </div>
  <?php if ($lastChange !== null): ?>
  <details class="mt-3">
    <summary class="cursor-pointer text-xs font-medium text-accent-700 dark:text-indigo-300">Freeze this report now…</summary>
    <form method="post" class="mt-2 flex flex-wrap gap-2 items-center" onsubmit="return confirm('Freeze the current live numbers for Term <?= $term ?>? The saved copy can\'t be changed afterwards.')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="freeze">
      <input type="text" name="note" maxlength="255" placeholder="Optional note, e.g. Before reading intervention" class="flex-1 min-w-[16rem] text-sm rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-1.5">
      <button type="submit" class="px-3 py-1.5 rounded-lg text-sm bg-accent-600 hover:bg-accent-700 text-white">Freeze live numbers</button>
    </form>
    <p class="mt-1 text-[11px] text-slate-400 dark:text-slate-500">Saves today's live numbers as a new snapshot. Older snapshots are kept. Student names are never saved — counts only.</p>
  </details>
  <?php endif; ?>
</div>

<h2 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-1">Students Failing 2 or More Subjects</h2>
<p class="text-xs text-slate-500 dark:text-slate-400 mb-4">Learners with a published grade below 75 in at least two subjects this term. MAPEH counts as one subject.</p>

<?php if (!$rateRows): ?>
<div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm px-4 py-8 text-center text-slate-400 dark:text-slate-500 text-sm mb-10">No enrolled students for this school year yet.</div>
<?php else:
    $allPop = $popTotals['M'] + $popTotals['F'];
?>
<div class="grid gap-4 xl:grid-cols-2 mb-4 items-start">
  <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm p-5 overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="text-slate-500 dark:text-slate-400 text-xs uppercase">
        <tr>
          <th class="text-left py-2 align-bottom" rowspan="2">Grade Level</th>
          <th class="text-center py-1 px-2 border-b border-slate-100 dark:border-slate-700" colspan="2">Male</th>
          <th class="text-center py-1 px-2 border-b border-slate-100 dark:border-slate-700" colspan="2">Female</th>
          <th class="text-center py-1 pl-2 border-b border-slate-100 dark:border-slate-700" colspan="2">Total</th>
        </tr>
        <tr class="text-[10px]">
          <th class="text-right px-2 py-1 font-medium">No.</th><th class="text-right px-2 py-1 font-medium">%</th>
          <th class="text-right px-2 py-1 font-medium">No.</th><th class="text-right px-2 py-1 font-medium">%</th>
          <th class="text-right px-2 py-1 font-medium">No.</th><th class="text-right pl-2 py-1 font-medium">%</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
        <?php foreach ($rateRows as $r): $f = $r['fail']['M'] + $r['fail']['F']; $pop = $r['pop']['M'] + $r['pop']['F']; ?>
        <tr>
          <td class="py-2 font-medium whitespace-nowrap"><?= h($r['name']) ?><div class="text-[11px] font-normal text-slate-400 dark:text-slate-500"><?= number_format($pop) ?> enrolled</div></td>
          <td class="py-2 px-2 text-right whitespace-nowrap"><?= $r['fail']['M'] ?><span class="text-slate-400 dark:text-slate-500 text-xs"> / <?= $r['pop']['M'] ?></span></td>
          <td class="py-2 px-2 text-right text-slate-500 dark:text-slate-400"><?= $fmtPct($pct($r['fail']['M'], $r['pop']['M'])) ?></td>
          <td class="py-2 px-2 text-right whitespace-nowrap"><?= $r['fail']['F'] ?><span class="text-slate-400 dark:text-slate-500 text-xs"> / <?= $r['pop']['F'] ?></span></td>
          <td class="py-2 px-2 text-right text-slate-500 dark:text-slate-400"><?= $fmtPct($pct($r['fail']['F'], $r['pop']['F'])) ?></td>
          <td class="py-2 px-2 text-right font-semibold <?= $f > 0 ? 'text-rose-600 dark:text-rose-400' : '' ?>"><?= $f ?></td>
          <td class="py-2 pl-2 text-right font-semibold <?= $f > 0 ? 'text-rose-600 dark:text-rose-400' : '' ?>"><?= $fmtPct($pct($f, $pop)) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="border-t-2 border-slate-200 dark:border-slate-600 font-semibold">
          <td class="py-2">Total<div class="text-[11px] font-normal text-slate-400 dark:text-slate-500"><?= number_format($allPop) ?> enrolled</div></td>
          <td class="py-2 px-2 text-right"><?= $multiFailTotals['M'] ?></td>
          <td class="py-2 px-2 text-right text-slate-500 dark:text-slate-400"><?= $fmtPct($pct($multiFailTotals['M'], $popTotals['M'])) ?></td>
          <td class="py-2 px-2 text-right"><?= $multiFailTotals['F'] ?></td>
          <td class="py-2 px-2 text-right text-slate-500 dark:text-slate-400"><?= $fmtPct($pct($multiFailTotals['F'], $popTotals['F'])) ?></td>
          <td class="py-2 px-2 text-right <?= $multiFailTotal > 0 ? 'text-rose-600 dark:text-rose-400' : '' ?>"><?= $multiFailTotal ?></td>
          <td class="py-2 pl-2 text-right <?= $multiFailTotal > 0 ? 'text-rose-600 dark:text-rose-400' : '' ?>"><?= $fmtPct($pct($multiFailTotal, $allPop)) ?></td>
        </tr>
      </tfoot>
    </table>
    <p class="mt-3 text-[11px] text-slate-400 dark:text-slate-500">% = students failing 2+ subjects ÷ students enrolled (per grade level and sex). <?= number_format($gradedTotal) ?> of <?= number_format($allPop) ?> enrolled learners have at least one published grade for Term <?= $term ?><?= $gradedTotal < $allPop ? ' — rates can still rise as more grades are published' : '' ?>.</p>
  </div>

  <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm p-5">
    <div class="text-sm font-semibold text-slate-700 dark:text-slate-200">Failing rate by grade level</div>
    <div class="text-xs text-slate-500 dark:text-slate-400 mb-5">Share of enrolled learners failing 2 or more subjects · Term <?= $term ?></div>
    <div class="space-y-3" role="img" aria-label="Bar chart of the failing rate per grade level; the same numbers are in the table.">
      <?php foreach ($rateRows as $r):
          $f = $r['fail']['M'] + $r['fail']['F']; $pop = $r['pop']['M'] + $r['pop']['F'];
          $rate = (float) $pct($f, $pop);
      ?>
      <div class="group relative grid grid-cols-[5.5rem_1fr_3.5rem] items-center gap-3 text-sm outline-none" tabindex="0">
        <div class="font-medium text-slate-600 dark:text-slate-300 whitespace-nowrap"><?= h($r['name']) ?></div>
        <div class="relative h-7">
          <?php for ($g = 0; $g <= 4; $g++): ?><div class="absolute top-0 bottom-0 border-l <?= $g === 0 ? 'border-slate-300 dark:border-slate-600' : 'border-dashed border-slate-100 dark:border-slate-700' ?>" style="left: <?= $g * 25 ?>%"></div><?php endfor; ?>
          <div class="absolute left-0 top-1 bottom-1 rounded-r bg-rose-500 dark:bg-rose-400 group-hover:bg-rose-600 dark:group-hover:bg-rose-300 group-focus:bg-rose-600 transition-colors" style="width: <?= min(100, $rate / $chartMax * 100) ?>%"></div>
        </div>
        <div class="text-right font-semibold text-slate-700 dark:text-slate-200 tabular-nums"><?= $fmtPct($rate) ?></div>
        <div class="pointer-events-none absolute z-10 left-24 top-0 -translate-y-full hidden group-hover:block group-focus:block bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg shadow-lg px-3 py-2 text-xs whitespace-nowrap">
          <div class="font-semibold text-slate-700 dark:text-slate-200 mb-0.5"><?= h($r['name']) ?> · <?= $fmtPct($rate) ?></div>
          <div class="text-slate-600 dark:text-slate-300"><?= $f ?> of <?= number_format($pop) ?> learners failing 2+ subjects</div>
          <div class="text-slate-500 dark:text-slate-400">Male <?= $r['fail']['M'] ?> / <?= $r['pop']['M'] ?> (<?= $fmtPct($pct($r['fail']['M'], $r['pop']['M'])) ?>) · Female <?= $r['fail']['F'] ?> / <?= $r['pop']['F'] ?> (<?= $fmtPct($pct($r['fail']['F'], $r['pop']['F'])) ?>)</div>
        </div>
      </div>
      <?php endforeach; ?>
      <div class="grid grid-cols-[5.5rem_1fr_3.5rem] gap-3 text-[10px] text-slate-400 dark:text-slate-500">
        <div></div>
        <div class="relative h-3">
          <?php for ($g = 0; $g <= 4; $g++): ?><span class="absolute <?= $g === 0 ? '' : ($g === 4 ? '-translate-x-full' : '-translate-x-1/2') ?>" style="left: <?= $g * 25 ?>%"><?= rtrim(rtrim(number_format($chartMax * $g / 4, 1), '0'), '.') ?>%</span><?php endfor; ?>
        </div>
        <div></div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($multiFail): ?>
<?php if ($snapshot): ?>
<p class="text-[11px] text-slate-400 dark:text-slate-500 mb-2">Student lists below are always live — names are never saved in a frozen report — so their counts can differ from the frozen numbers above.</p>
<?php endif; ?>
<div class="space-y-3 mb-10">
  <?php foreach ($multiFail as $gl): ?>
  <details class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm">
    <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-700 dark:text-slate-200"><?= h($gl['name']) ?> — <?= $gl['M'] + $gl['F'] ?> student<?= $gl['M'] + $gl['F'] === 1 ? '' : 's' ?> (view list)</summary>
    <div class="overflow-x-auto border-t border-slate-100 dark:border-slate-700">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 dark:bg-slate-900 text-slate-500 dark:text-slate-400 text-xs uppercase">
          <tr><th class="text-left px-4 py-2">Section</th><th class="text-left px-4 py-2">Student</th><th class="text-center px-4 py-2">Subjects</th><th class="text-left px-4 py-2">Subjects Below 75</th></tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
          <?php foreach ($gl['sections'] as $sec): ?>
            <?php foreach ($sec['students'] as $i => $student): ?>
            <tr>
              <td class="px-4 py-2 whitespace-nowrap <?= $i === 0 ? 'font-medium' : 'text-slate-300 dark:text-slate-600' ?>"><?= $i === 0 ? h($sec['name']) : '' ?></td>
              <td class="px-4 py-2 whitespace-nowrap"><?= h($student['name']) ?></td>
              <td class="px-4 py-2 text-center font-semibold text-rose-600 dark:text-rose-400"><?= count($student['subjects']) ?></td>
              <td class="px-4 py-2 text-slate-600 dark:text-slate-300">
                <?= h(implode(' · ', array_map(fn($s) => $s['name'] . ' (' . $s['grade'] . ')', $student['subjects']))) ?>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </details>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($rateRows): ?>
<!-- Which subjects drive it -->
<h2 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-1">Which Subjects Are They Failing?</h2>
<p class="text-xs text-slate-500 dark:text-slate-400 mb-4">Among the <?= number_format($multiFailTotal) ?> learners failing 2 or more subjects in Term <?= $term ?>, how many are failing each subject. A learner counts once in every subject they fail, so the column adds up to more than the number of learners.</p>
<?php if (!$driverSubjects): ?>
<div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm px-4 py-8 text-center text-slate-400 dark:text-slate-500 text-sm mb-10">No learners are failing 2 or more subjects in Term <?= $term ?>.</div>
<?php else: ?>
<div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm p-5 mb-10 overflow-x-auto">
  <table class="w-full text-sm">
    <thead class="text-slate-500 dark:text-slate-400 text-xs uppercase">
      <tr>
        <th class="text-left py-2 pr-3">Subject</th>
        <?php foreach ($rateRows as $glId => $r): ?><th class="text-right py-2 px-2 whitespace-nowrap"><?= h($r['name']) ?></th><?php endforeach; ?>
        <th class="text-right py-2 px-2">Total</th>
        <th class="text-left py-2 pl-4 w-1/3">Share of these learners</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
      <?php foreach ($driverSubjects as $sub): $share = (float) $pct($sub['total'], $multiFailTotal); ?>
      <tr>
        <td class="py-2 pr-3 font-medium whitespace-nowrap"><?= h($sub['name']) ?></td>
        <?php foreach ($rateRows as $glId => $r): $n = $sub['byGl'][$glId] ?? 0; ?>
          <td class="py-2 px-2 text-right tabular-nums <?= $n ? '' : 'text-slate-300 dark:text-slate-600' ?>"><?= $n ?></td>
        <?php endforeach; ?>
        <td class="py-2 px-2 text-right font-semibold tabular-nums"><?= $sub['total'] ?></td>
        <td class="py-2 pl-4">
          <div class="flex items-center gap-2" title="<?= h($sub['name']) ?>: <?= $sub['total'] ?> of <?= $multiFailTotal ?> learners failing 2+ subjects (<?= $pctLabel($share) ?>)">
            <div class="flex-1 h-4 relative"><div class="absolute left-0 top-0 bottom-0 rounded-r bg-rose-500 dark:bg-rose-400" style="width: <?= $driverMax ? $sub['total'] / $driverMax * 100 : 0 ?>%"></div></div>
            <span class="w-12 text-right text-xs font-semibold text-slate-600 dark:text-slate-300 tabular-nums"><?= $pctLabel($share) ?></span>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- By section -->
<h2 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-1">Failing Rate by Section</h2>
<p class="text-xs text-slate-500 dark:text-slate-400 mb-4">Share of each section's enrolled learners failing 2 or more subjects in Term <?= $term ?>, highest first.</p>
<details class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm mb-10" <?= count($sectionRates) <= 12 ? 'open' : '' ?>>
  <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-700 dark:text-slate-200"><?= count($sectionRates) ?> section<?= count($sectionRates) === 1 ? '' : 's' ?><?= $sectionRates && $sectionRates[0]['fail'] > 0 ? ' — highest: ' . h($sectionRates[0]['grade_level'] . ' - ' . $sectionRates[0]['name']) . ' (' . $pctLabel($sectionRates[0]['rate']) . ')' : '' ?></summary>
  <div class="overflow-x-auto border-t border-slate-100 dark:border-slate-700">
    <table class="w-full text-sm">
      <thead class="bg-slate-50 dark:bg-slate-900 text-slate-500 dark:text-slate-400 text-xs uppercase">
        <tr>
          <th class="text-left px-4 py-2">Section</th>
          <th class="text-left px-4 py-2">Adviser</th>
          <th class="text-right px-4 py-2">Enrolled</th>
          <th class="text-right px-4 py-2">Failing 2+</th>
          <th class="text-left px-4 py-2 w-1/3">Rate</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
        <?php foreach ($sectionRates as $s): ?>
        <tr>
          <td class="px-4 py-2 whitespace-nowrap"><span class="text-slate-400 dark:text-slate-500"><?= h($s['grade_level']) ?> -</span> <span class="font-medium"><?= h($s['name']) ?></span></td>
          <td class="px-4 py-2 whitespace-nowrap text-slate-600 dark:text-slate-300"><?= $s['adviser'] !== null ? h($s['adviser']) : '<span class="text-slate-300 dark:text-slate-600">—</span>' ?></td>
          <td class="px-4 py-2 text-right tabular-nums"><?= $s['pop'] ?></td>
          <td class="px-4 py-2 text-right tabular-nums font-semibold <?= $s['fail'] ? 'text-rose-600 dark:text-rose-400' : 'text-slate-300 dark:text-slate-600' ?>"><?= $s['fail'] ?></td>
          <td class="px-4 py-2">
            <div class="flex items-center gap-2">
              <div class="flex-1 h-4 relative"><div class="absolute left-0 top-0 bottom-0 rounded-r bg-rose-500 dark:bg-rose-400" style="width: <?= min(100, $s['rate'] / $sectionMax * 100) ?>%"></div></div>
              <span class="w-12 text-right text-xs font-semibold text-slate-600 dark:text-slate-300 tabular-nums"><?= $pctLabel($s['rate']) ?></span>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</details>

<!-- Term trend -->
<h2 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-1">Term-over-Term Trend</h2>
<p class="text-xs text-slate-500 dark:text-slate-400 mb-4">Failing-2+ rate per grade level across the three terms. <?= $snapshot ? 'Terms marked 🔒 use their latest frozen report (and that report’s enrollment); the rest are live against current enrollment.' : 'Live numbers against this year’s current enrollment.' ?> "—" means no grades are published for that term yet. A falling rate suggests interventions are working.</p>
<div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm p-5 mb-10">
  <div class="flex flex-wrap gap-4 mb-4 text-xs text-slate-600 dark:text-slate-300">
    <?php for ($t = 1; $t <= 3; $t++): ?><span class="flex items-center gap-1.5"><span class="inline-block w-3 h-3 rounded-sm <?= $trendShades[$t] ?>"></span>Term <?= $t ?></span><?php endfor; ?>
  </div>
  <div class="space-y-4">
    <?php foreach ($trendRows as $tr): ?>
    <div class="grid grid-cols-[5.5rem_1fr] gap-3 items-center">
      <div class="text-sm font-medium text-slate-600 dark:text-slate-300 whitespace-nowrap"><?= h($tr['name']) ?></div>
      <div class="space-y-0.5">
        <?php for ($t = 1; $t <= 3; $t++): $v = $tr['rates'][$t]; ?>
        <div class="grid grid-cols-[1fr_7.5rem] gap-2 items-center" title="<?= h($tr['name']) ?> · Term <?= $t ?><?= $tr['frozen'][$t] ? ' (frozen)' : ' (live)' ?>: <?= $v === null ? 'no published grades yet' : ($tr['fail'][$t] ?? 0) . ' learners (' . $pctLabel($v) . ')' ?>">
          <div class="relative h-3.5">
            <div class="absolute top-0 bottom-0 left-0 border-l border-slate-300 dark:border-slate-600"></div>
            <?php if ($v !== null): ?><div class="absolute left-0 top-0 bottom-0 rounded-r <?= $trendShades[$t] ?>" style="width: <?= min(100, $v / $trendMax * 100) ?>%"></div><?php endif; ?>
          </div>
          <div class="text-xs tabular-nums text-slate-500 dark:text-slate-400 whitespace-nowrap"><span class="text-slate-400 dark:text-slate-500">T<?= $t ?><?= $tr['frozen'][$t] ? ' 🔒' : '' ?></span> <span class="font-semibold text-slate-700 dark:text-slate-200"><?= $v === null ? '—' : $pctLabel($v) ?></span><?php
            if ($t > 1 && $v !== null && $tr['rates'][$t - 1] !== null):
                $d = round($v - $tr['rates'][$t - 1], 1);
                if ($d != 0): ?> <span class="<?= $d > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400' ?>"><?= $d > 0 ? '▲' : '▼' ?><?= $pctLabel(abs($d)) ?></span><?php endif;
            endif; ?></div>
        </div>
        <?php endfor; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <p class="mt-4 text-[11px] text-slate-400 dark:text-slate-500">▲ red = rate went up from the previous term, ▼ green = went down (percentage points). Scale 0–<?= $trendMax ?>%.</p>
</div>
<?php endif; ?>

<h2 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-1">Proficiency Level by Subject</h2>
<p class="text-xs text-slate-500 dark:text-slate-400 mb-4">Number of learners per proficiency band, per subject and grade level, from published grades this term.</p>

<?php if (!$plByGradeLevel): ?>
<div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm px-4 py-8 text-center text-slate-400 dark:text-slate-500 text-sm">No published grades yet for Term <?= $term ?>.</div>
<?php else: ?>
<?php foreach ($plByGradeLevel as $gl):
    $glTotals = array_fill_keys($bandKeys, 0);
    foreach ($gl['subjects'] as $sub) {
        foreach ($sub['bands'] as $k => $v) {
            $glTotals[$k] += $v;
        }
    }
?>
<div class="font-semibold text-slate-800 dark:text-slate-100 mb-3"><?= h($gl['name']) ?></div>
<div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm p-5 mb-6">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="text-slate-500 dark:text-slate-400 text-xs uppercase">
        <tr>
          <th class="text-left py-2 pr-3">Subject</th>
          <?php foreach ($bandKeys as $k): ?>
            <th class="text-center py-2 px-2 whitespace-nowrap"><?= h($bandLabels[$k]) ?><div class="text-[10px] font-normal normal-case text-slate-400 dark:text-slate-500"><?= h($bandRanges[$k]) ?></div></th>
          <?php endforeach; ?>
          <th class="text-right py-2 pl-3">Total</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
        <?php foreach ($gl['subjects'] as $sub): ?>
        <tr>
          <td class="py-2 pr-3 font-medium whitespace-nowrap"><?= h($sub['name']) ?></td>
          <?php foreach ($bandKeys as $k): ?>
            <td class="text-center py-2 px-2 <?= $k === 'at_risk' && $sub['bands'][$k] > 0 ? 'text-rose-600 dark:text-rose-400 font-semibold' : '' ?>"><?= $sub['bands'][$k] ?></td>
          <?php endforeach; ?>
          <td class="text-right py-2 pl-3 font-semibold"><?= array_sum($sub['bands']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="border-t-2 border-slate-200 dark:border-slate-600 font-semibold">
          <td class="py-2 pr-3">All Subjects</td>
          <?php foreach ($bandKeys as $k): ?>
            <td class="text-center py-2 px-2 <?= $k === 'at_risk' && $glTotals[$k] > 0 ? 'text-rose-600 dark:text-rose-400' : '' ?>"><?= $glTotals[$k] ?></td>
          <?php endforeach; ?>
          <td class="text-right py-2 pl-3"><?= array_sum($glTotals) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
<?php render_footer(); ?>
