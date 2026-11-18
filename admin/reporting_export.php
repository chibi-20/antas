<?php
declare(strict_types=1);

// CSV download of admin/reporting.php — a frozen snapshot (?snapshot=ID) or the live numbers
// (?term=N). Counts only, never student names, so it is safe to attach to a research paper.

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/reporting.php';

require_role(['admin']);
$pdo = db();
$year = require_active_school_year();

$snapshotId = (int) ($_GET['snapshot'] ?? 0);
if ($snapshotId > 0) {
    $snapshot = report_snapshot_load($pdo, $snapshotId);
    if (!$snapshot) {
        http_response_code(404);
        exit('Snapshot not found.');
    }
    $report = $snapshot['report'];
    $term = (int) $snapshot['term'];
    $yStmt = $pdo->prepare('SELECT year_label FROM school_years WHERE id = ?');
    $yStmt->execute([$snapshot['school_year_id']]);
    $yearLabel = (string) $yStmt->fetchColumn();
    $source = 'Frozen ' . ($snapshot['trigger_type'] === 'auto' ? 'automatically' : 'manually') . ' on ' . $snapshot['created_at']
        . ($snapshot['note'] && $snapshot['trigger_type'] === 'manual' ? ' — ' . $snapshot['note'] : '')
        . '; includes grade changes up to ' . ($snapshot['data_as_of'] ?? '—');
    $fileTag = 'frozen-' . substr($snapshot['created_at'], 0, 10);
} else {
    $term = max(1, min(3, (int) ($_GET['term'] ?? 1)));
    $report = report_build($pdo, (int) $year['id'], $term);
    $yearLabel = $year['year_label'];
    $now = (string) $pdo->query('SELECT NOW()')->fetchColumn(); // database clock, same as grade timestamps
    $source = 'Live numbers as of ' . $now . ' (not frozen — can still change)';
    $fileTag = 'live-' . substr($now, 0, 10);
}

$filename = 'tapat-report-' . preg_replace('/[^0-9A-Za-z-]+/', '-', $yearLabel) . '-term' . $term . '-' . $fileTag . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel opens UTF-8 (—, ñ) correctly
$row = fn(array $cells) => fputcsv($out, $cells);
$pct = fn(int $a, int $b) => ($v = report_pct($a, $b)) === null ? '' : $v;

$row(['TAPAT Reporting', 'School year ' . $yearLabel, 'Term ' . $term]);
$row([$source]);
$row(['Failing = published grade below 75 (rounded). MAPEH counts as one subject. Counts only, no student names.']);
$row([]);

$row(['STUDENTS FAILING 2 OR MORE SUBJECTS']);
$row(['Grade level', 'Enrolled male', 'Failing male', '% male', 'Enrolled female', 'Failing female', '% female', 'Enrolled total', 'Failing total', '% total', 'With published grades']);
$tot = ['pm' => 0, 'fm' => 0, 'pf' => 0, 'ff' => 0, 'g' => 0];
foreach ($report['rateRows'] as $r) {
    $p = $r['pop']['M'] + $r['pop']['F'];
    $f = $r['fail']['M'] + $r['fail']['F'];
    $row([$r['name'], $r['pop']['M'], $r['fail']['M'], $pct($r['fail']['M'], $r['pop']['M']), $r['pop']['F'], $r['fail']['F'], $pct($r['fail']['F'], $r['pop']['F']), $p, $f, $pct($f, $p), $r['graded']]);
    $tot['pm'] += $r['pop']['M']; $tot['fm'] += $r['fail']['M']; $tot['pf'] += $r['pop']['F']; $tot['ff'] += $r['fail']['F']; $tot['g'] += $r['graded'];
}
$row(['Total', $tot['pm'], $tot['fm'], $pct($tot['fm'], $tot['pm']), $tot['pf'], $tot['ff'], $pct($tot['ff'], $tot['pf']), $tot['pm'] + $tot['pf'], $tot['fm'] + $tot['ff'], $pct($tot['fm'] + $tot['ff'], $tot['pm'] + $tot['pf']), $tot['g']]);
$row([]);

$multiTotal = $report['multiFailTotals']['M'] + $report['multiFailTotals']['F'];
$row(['WHICH SUBJECTS ARE THEY FAILING (among learners failing 2+)']);
$row(array_merge(['Subject'], array_column($report['rateRows'], 'name'), ['Total', '% of these learners']));
foreach ($report['drivers'] as $d) {
    $cells = [$d['name']];
    foreach (array_keys($report['rateRows']) as $glId) {
        $cells[] = $d['byGl'][$glId] ?? 0;
    }
    $row(array_merge($cells, [$d['total'], $pct($d['total'], $multiTotal)]));
}
$row([]);

$row(['FAILING RATE BY SECTION']);
$row(['Grade level', 'Section', 'Adviser', 'Enrolled', 'Failing 2+', '%']);
foreach ($report['sections'] as $s) {
    $row([$s['grade_level'], $s['name'], $s['adviser'] ?? '', $s['pop'], $s['fail'], $s['rate']]);
}
$row([]);

$bandKeys = array_column(PL_SECTION_BANDS, 'key');
$bandHeads = array_map(fn($b) => $b['label'] . ' (' . $b['range'] . ')', PL_SECTION_BANDS);
$row(['PROFICIENCY LEVEL BY SUBJECT']);
$row(array_merge(['Grade level', 'Subject'], $bandHeads, ['Total']));
foreach ($report['pl'] as $gl) {
    foreach ($gl['subjects'] as $sub) {
        $row(array_merge([$gl['name'], $sub['name']], array_map(fn($k) => $sub['bands'][$k] ?? 0, $bandKeys), [array_sum($sub['bands'])]));
    }
}
fclose($out);
