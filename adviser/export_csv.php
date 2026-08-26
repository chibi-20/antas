<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$sectionId = (int) ($_GET['section_id'] ?? 0);
$term = (int) ($_GET['term'] ?? 1);
if ($term < 1 || $term > 3) {
    $term = 1;
}

$section = require_own_section($sectionId);
$data = get_consolidated_data($sectionId, (int) $section['school_year_id'], $term);
// MAPEH's individual components (Music-Arts, PE-Health) are display-only on Consolidated
// Grades/Card Slips — kept out of this export so PLS's expected column format (one merged
// MAPEH column) doesn't change.
$data['subjects'] = array_values(array_filter($data['subjects'], fn($s) => !$s['is_child']));

// Column order/headers are a reasonable default, unverified against PLS's actual import
// screen (pls.jzgmnhsportal.com is login-gated) — see README "CSV export column order".
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_-]/', '_', $section['grade_level'] . '_' . $section['section_name']) . "_Term{$term}.csv\"");

$out = fopen('php://output', 'w');
$header = ['LRN', 'Full Name'];
foreach ($data['subjects'] as $subject) {
    $header[] = $subject['subject_name'];
}
$header[] = 'General Average';
fputcsv($out, $header);

foreach ($data['students'] as $student) {
    $row = [$student['lrn'], $student['full_name']];
    foreach ($data['subjects'] as $subject) {
        if ($subject['status'] !== 'published') {
            $row[] = 'Pending';
        } else {
            $row[] = $data['grades'][$student['id']][$subject['subject_id']] ?? '';
        }
    }
    $row[] = $data['averages'][$student['id']]['average'] ?? '';
    fputcsv($out, $row);
}
fclose($out);
