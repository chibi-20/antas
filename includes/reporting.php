<?php
declare(strict_types=1);

/**
 * Data side of admin/reporting.php, plus frozen snapshots of it (report_snapshots, migration
 * 0025). The page, the auto-freeze and the CSV download all build from report_build(), so a
 * snapshot is exactly what the live page showed at that moment.
 *
 * Everything reads effective_term_grades: published grades only, one row per student per
 * top-level subject (MAPEH counted once as its merged grade). Grades compare as the rounded
 * whole number printed on Card Slips, so a 74.5 that prints as 75 is not failing.
 */

require_once __DIR__ . '/helpers.php';

/** A term's report freezes automatically once its grades have gone this long without a change. */
const REPORT_AUTO_FREEZE_DAYS = 10;
/** Bumped if the shape of report_build()'s counts ever changes, so old snapshots can be told apart. */
const REPORT_DATA_VERSION = 1;

function report_pct(int $part, int $whole): ?float
{
    return $whole > 0 ? round($part / $whole * 100, 1) : null;
}

function report_pct_label(?float $v): string
{
    return $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.') . '%';
}

/**
 * The whole report for one term. Every key except 'students' is counts only and is what a
 * snapshot stores; 'students' (names of learners failing 2+ subjects) is live-only.
 */
function report_build(PDO $pdo, int $yearId, int $term): array
{
    $failStmt = $pdo->prepare('SELECT etg.student_id, etg.subject_id, st.full_name, st.sex,
            sec.id AS section_id, sec.section_name, gl.id AS grade_level_id, gl.name AS grade_level, gl.sort_order AS gl_sort,
            sub.subject_name, sub.sort_order AS sub_sort, ROUND(etg.transmuted_grade) AS grade
        FROM effective_term_grades etg
        JOIN students st ON st.id = etg.student_id AND st.is_active = 1
        JOIN sections sec ON sec.id = etg.section_id
        JOIN grade_levels gl ON gl.id = sec.grade_level_id
        JOIN subjects sub ON sub.id = etg.subject_id
        WHERE etg.term = ? AND etg.school_year_id = ? AND etg.transmuted_grade IS NOT NULL AND ROUND(etg.transmuted_grade) < 75');
    $failStmt->execute([$term, $yearId]);

    $failingByStudent = [];
    foreach ($failStmt->fetchAll() as $row) {
        $sid = (int) $row['student_id'];
        $failingByStudent[$sid] ??= [
            'name' => $row['full_name'],
            'sex' => $row['sex'],
            'section_id' => (int) $row['section_id'],
            'section_name' => $row['section_name'],
            'grade_level_id' => (int) $row['grade_level_id'],
            'grade_level' => $row['grade_level'],
            'gl_sort' => (int) $row['gl_sort'],
            'subjects' => [],
        ];
        $failingByStudent[$sid]['subjects'][(int) $row['subject_id']] = [
            'name' => $row['subject_name'],
            'sort' => (int) $row['sub_sort'],
            'grade' => (int) $row['grade'],
        ];
    }

    $multiFail = [];
    $multiFailTotals = ['M' => 0, 'F' => 0];
    foreach ($failingByStudent as $student) {
        if (count($student['subjects']) < 2) {
            continue;
        }
        uasort($student['subjects'], fn($a, $b) => $a['sort'] <=> $b['sort'] ?: strcmp($a['name'], $b['name']));
        $glId = $student['grade_level_id'];
        $multiFail[$glId] ??= ['name' => $student['grade_level'], 'sort' => $student['gl_sort'], 'M' => 0, 'F' => 0, 'sections' => []];
        if (isset($multiFailTotals[$student['sex']])) {
            $multiFail[$glId][$student['sex']]++;
            $multiFailTotals[$student['sex']]++;
        }
        $multiFail[$glId]['sections'][$student['section_id']]['name'] = $student['section_name'];
        $multiFail[$glId]['sections'][$student['section_id']]['students'][] = $student;
    }
    uasort($multiFail, fn($a, $b) => $a['sort'] <=> $b['sort']);
    foreach ($multiFail as &$gl) {
        uasort($gl['sections'], fn($a, $b) => strcmp($a['name'], $b['name']));
        foreach ($gl['sections'] as &$sec) {
            usort($sec['students'], fn($a, $b) => strcmp($b['sex'], $a['sex']) ?: strcmp($a['name'], $b['name']));
        }
        unset($sec);
    }
    unset($gl);

    // Population: every active learner enrolled in a section of this school year, per grade
    // level and sex. 'graded' = learners with at least one published grade this term.
    $popStmt = $pdo->prepare('SELECT gl.id AS grade_level_id, gl.name AS grade_level, gl.sort_order AS gl_sort, st.sex, COUNT(*) AS n
        FROM students st
        JOIN sections sec ON sec.id = st.section_id AND sec.school_year_id = ?
        JOIN grade_levels gl ON gl.id = sec.grade_level_id
        WHERE st.is_active = 1
        GROUP BY gl.id, gl.name, gl.sort_order, st.sex');
    $popStmt->execute([$yearId]);
    $gradedStmt = $pdo->prepare('SELECT sec.grade_level_id, COUNT(DISTINCT etg.student_id) AS n
        FROM effective_term_grades etg
        JOIN students st ON st.id = etg.student_id AND st.is_active = 1
        JOIN sections sec ON sec.id = etg.section_id
        WHERE etg.term = ? AND etg.school_year_id = ? AND etg.transmuted_grade IS NOT NULL
        GROUP BY sec.grade_level_id');
    $gradedStmt->execute([$term, $yearId]);
    $gradedByGl = array_map('intval', array_column($gradedStmt->fetchAll(), 'n', 'grade_level_id'));

    $blankRate = fn(string $name, int $sort, int $glId) => ['name' => $name, 'sort' => $sort, 'pop' => ['M' => 0, 'F' => 0], 'fail' => ['M' => 0, 'F' => 0], 'graded' => $gradedByGl[$glId] ?? 0];
    $rateRows = [];
    foreach ($popStmt->fetchAll() as $row) {
        $glId = (int) $row['grade_level_id'];
        $rateRows[$glId] ??= $blankRate($row['grade_level'], (int) $row['gl_sort'], $glId);
        if (isset($rateRows[$glId]['pop'][$row['sex']])) {
            $rateRows[$glId]['pop'][$row['sex']] += (int) $row['n'];
        }
    }
    foreach ($multiFail as $glId => $gl) {
        $rateRows[$glId] ??= $blankRate($gl['name'], $gl['sort'], $glId);
        $rateRows[$glId]['fail'] = ['M' => $gl['M'], 'F' => $gl['F']];
    }
    uasort($rateRows, fn($a, $b) => $a['sort'] <=> $b['sort']);

    // Which subjects drive it: among learners failing 2+, how many fail each subject.
    $drivers = [];
    foreach ($multiFail as $glId => $gl) {
        foreach ($gl['sections'] as $sec) {
            foreach ($sec['students'] as $student) {
                foreach ($student['subjects'] as $subId => $sub) {
                    $drivers[$subId] ??= ['name' => $sub['name'], 'sort' => $sub['sort'], 'byGl' => [], 'total' => 0];
                    $drivers[$subId]['byGl'][$glId] = ($drivers[$subId]['byGl'][$glId] ?? 0) + 1;
                    $drivers[$subId]['total']++;
                }
            }
        }
    }
    uasort($drivers, fn($a, $b) => $b['total'] <=> $a['total'] ?: $a['sort'] <=> $b['sort']);

    // Failing-2+ rate per section.
    $secPopStmt = $pdo->prepare('SELECT sec.id, sec.section_name, gl.name AS grade_level, gl.sort_order AS gl_sort, u.full_name AS adviser, COUNT(st.id) AS n
        FROM sections sec
        JOIN grade_levels gl ON gl.id = sec.grade_level_id
        JOIN students st ON st.section_id = sec.id AND st.is_active = 1
        LEFT JOIN users u ON u.id = sec.adviser_id
        WHERE sec.school_year_id = ?
        GROUP BY sec.id, sec.section_name, gl.name, gl.sort_order, u.full_name');
    $secPopStmt->execute([$yearId]);
    $sections = [];
    foreach ($secPopStmt->fetchAll() as $row) {
        $fails = 0;
        foreach ($multiFail as $gl) {
            $fails += count($gl['sections'][(int) $row['id']]['students'] ?? []);
        }
        $sections[] = [
            'name' => $row['section_name'],
            'grade_level' => $row['grade_level'],
            'gl_sort' => (int) $row['gl_sort'],
            'adviser' => $row['adviser'],
            'pop' => (int) $row['n'],
            'fail' => $fails,
            'rate' => (float) report_pct($fails, (int) $row['n']),
        ];
    }
    usort($sections, fn($a, $b) => $b['rate'] <=> $a['rate'] ?: $b['fail'] <=> $a['fail'] ?: $a['gl_sort'] <=> $b['gl_sort'] ?: strcmp($a['name'], $b['name']));

    // Proficiency Level by subject, per grade level.
    $plStmt = $pdo->prepare('SELECT gl.id AS grade_level_id, gl.name AS grade_level, gl.sort_order AS gl_sort,
            sub.id AS subject_id, sub.subject_name, sub.sort_order AS sub_sort,
            ROUND(etg.transmuted_grade) AS grade, COUNT(*) AS n
        FROM effective_term_grades etg
        JOIN students st ON st.id = etg.student_id AND st.is_active = 1
        JOIN sections sec ON sec.id = etg.section_id
        JOIN grade_levels gl ON gl.id = sec.grade_level_id
        JOIN subjects sub ON sub.id = etg.subject_id
        WHERE etg.term = ? AND etg.school_year_id = ? AND etg.transmuted_grade IS NOT NULL
        GROUP BY gl.id, gl.name, gl.sort_order, sub.id, sub.subject_name, sub.sort_order, ROUND(etg.transmuted_grade)');
    $plStmt->execute([$term, $yearId]);
    $bandKeys = array_column(PL_SECTION_BANDS, 'key');
    $pl = [];
    foreach ($plStmt->fetchAll() as $row) {
        $glId = (int) $row['grade_level_id'];
        $subId = (int) $row['subject_id'];
        $pl[$glId] ??= ['name' => $row['grade_level'], 'sort' => (int) $row['gl_sort'], 'subjects' => []];
        $pl[$glId]['subjects'][$subId] ??= ['name' => $row['subject_name'], 'sort' => (int) $row['sub_sort'], 'bands' => array_fill_keys($bandKeys, 0)];
        $pl[$glId]['subjects'][$subId]['bands'][pl_section_band((float) $row['grade'])] += (int) $row['n'];
    }
    uasort($pl, fn($a, $b) => $a['sort'] <=> $b['sort']);
    foreach ($pl as &$gl) {
        uasort($gl['subjects'], fn($a, $b) => $a['sort'] <=> $b['sort'] ?: strcmp($a['name'], $b['name']));
    }
    unset($gl);

    return [
        'version' => REPORT_DATA_VERSION,
        'rateRows' => $rateRows,
        'multiFailTotals' => $multiFailTotals,
        'drivers' => $drivers,
        'sections' => $sections,
        'pl' => $pl,
        'students' => $multiFail,
    ];
}

/** Headline totals of a report, used to tell whether live data has drifted from a snapshot. */
function report_totals(array $report): array
{
    $t = ['pop' => 0, 'graded' => 0, 'fail' => 0];
    foreach ($report['rateRows'] as $r) {
        $t['pop'] += $r['pop']['M'] + $r['pop']['F'];
        $t['graded'] += $r['graded'];
        $t['fail'] += $r['fail']['M'] + $r['fail']['F'];
    }
    return $t;
}

/**
 * The most recent moment anything feeding a term's report changed: a publish (or re-publish
 * after an edit request) or any grade recalculation. NULL when the term has no grades at all.
 */
function report_last_change(PDO $pdo, int $yearId, int $term): ?string
{
    $stmt = $pdo->prepare("SELECT GREATEST(
            COALESCE((SELECT MAX(ss.reviewed_at) FROM submission_status ss
                JOIN section_subject_teachers sst ON sst.id = ss.section_subject_teacher_id
                WHERE ss.term = ? AND ss.status = 'published' AND sst.school_year_id = ?), '1970-01-02'),
            COALESCE((SELECT MAX(ger.finalized_at) FROM grade_edit_requests ger
                JOIN section_subject_teachers sst ON sst.id = ger.section_subject_teacher_id
                WHERE ger.term = ? AND sst.school_year_id = ?), '1970-01-02'),
            COALESCE((SELECT MAX(tg.updated_at) FROM term_grades tg WHERE tg.term = ? AND tg.school_year_id = ?), '1970-01-02')
        ) AS last_change,
        EXISTS(SELECT 1 FROM effective_term_grades etg WHERE etg.term = ? AND etg.school_year_id = ? AND etg.transmuted_grade IS NOT NULL) AS has_grades");
    $stmt->execute([$term, $yearId, $term, $yearId, $term, $yearId, $term, $yearId]);
    $row = $stmt->fetch();
    return $row && (int) $row['has_grades'] === 1 ? $row['last_change'] : null;
}

/** Strip the live-only student names before anything is stored. */
function report_counts_only(array $report): array
{
    unset($report['students']);
    return $report;
}

function report_save_snapshot(PDO $pdo, int $yearId, int $term, string $trigger, ?int $userId, ?string $note, ?string $dataAsOf, ?array $report = null): int
{
    $report ??= report_build($pdo, $yearId, $term);
    $pdo->prepare('INSERT INTO report_snapshots (school_year_id, term, trigger_type, note, data_as_of, created_by, data) VALUES (?,?,?,?,?,?,?)')
        ->execute([$yearId, $term, $trigger, $note, $dataAsOf, $userId, json_encode(report_counts_only($report), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    return (int) $pdo->lastInsertId();
}

/**
 * Takes the automatic snapshot for any term of this year that has none yet and whose grades
 * have gone REPORT_AUTO_FREEZE_DAYS without a change. Runs when an admin opens Reporting (no
 * scheduled job needed). Only the first automatic snapshot per term is ever taken; later
 * corrections leave it alone. A named lock stops two admins opening the page at the same
 * moment from both taking one.
 *
 * @return int[] terms that were frozen just now
 */
function report_auto_freeze(PDO $pdo, int $yearId): array
{
    if ((int) $pdo->query("SELECT GET_LOCK('tapat_report_autofreeze', 5)")->fetchColumn() !== 1) {
        return [];
    }
    $frozen = [];
    try {
        $has = $pdo->prepare("SELECT 1 FROM report_snapshots WHERE school_year_id = ? AND term = ? AND trigger_type = 'auto' LIMIT 1");
        for ($term = 1; $term <= 3; $term++) {
            $has->execute([$yearId, $term]);
            if ($has->fetchColumn()) {
                continue;
            }
            $last = report_last_change($pdo, $yearId, $term);
            if ($last === null) {
                continue;
            }
            $quietSince = (int) $pdo->query('SELECT TIMESTAMPDIFF(SECOND, ' . $pdo->quote($last) . ', NOW())')->fetchColumn();
            if ($quietSince < REPORT_AUTO_FREEZE_DAYS * 86400) {
                continue;
            }
            report_save_snapshot($pdo, $yearId, $term, 'auto', null, REPORT_AUTO_FREEZE_DAYS . ' days without grade changes', $last);
            $frozen[] = $term;
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('tapat_report_autofreeze')");
    }
    return $frozen;
}

/** @return array<int, array> this term's snapshots, newest first, without their data */
function report_snapshot_list(PDO $pdo, int $yearId, int $term): array
{
    $stmt = $pdo->prepare('SELECT rs.id, rs.trigger_type, rs.note, rs.data_as_of, rs.created_at, u.full_name AS created_by_name
        FROM report_snapshots rs LEFT JOIN users u ON u.id = rs.created_by
        WHERE rs.school_year_id = ? AND rs.term = ? ORDER BY rs.created_at DESC, rs.id DESC');
    $stmt->execute([$yearId, $term]);
    return $stmt->fetchAll();
}

function report_snapshot_load(PDO $pdo, int $snapshotId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM report_snapshots WHERE id = ?');
    $stmt->execute([$snapshotId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $row['report'] = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
    $row['report']['students'] = null;
    unset($row['data']);
    return $row;
}

/** Latest snapshot per term for this year: [term => ['id' => .., 'report' => ..]]. */
function report_latest_snapshots(PDO $pdo, int $yearId): array
{
    $stmt = $pdo->prepare('SELECT rs.id, rs.term FROM report_snapshots rs
        WHERE rs.school_year_id = ? AND rs.id = (SELECT r2.id FROM report_snapshots r2
            WHERE r2.school_year_id = rs.school_year_id AND r2.term = rs.term ORDER BY r2.created_at DESC, r2.id DESC LIMIT 1)');
    $stmt->execute([$yearId]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(int) $row['term']] = report_snapshot_load($pdo, (int) $row['id']);
    }
    return $out;
}

/**
 * Failing-2+ count per grade level for every term, computed live in one pass, plus which
 * terms have any published grade per grade level.
 *
 * @return array{fail: array<int, array<int,int>>, has: array<int, array<int,bool>>}
 */
function report_live_trend(PDO $pdo, int $yearId): array
{
    $stmt = $pdo->prepare('SELECT f.term, sec.grade_level_id, COUNT(*) AS n
        FROM (SELECT etg.term, etg.student_id, MIN(etg.section_id) AS section_id
              FROM effective_term_grades etg
              JOIN students st ON st.id = etg.student_id AND st.is_active = 1
              WHERE etg.school_year_id = ? AND etg.transmuted_grade IS NOT NULL AND ROUND(etg.transmuted_grade) < 75
              GROUP BY etg.term, etg.student_id
              HAVING COUNT(*) >= 2) f
        JOIN sections sec ON sec.id = f.section_id
        GROUP BY f.term, sec.grade_level_id');
    $stmt->execute([$yearId]);
    $fail = [];
    foreach ($stmt->fetchAll() as $row) {
        $fail[(int) $row['grade_level_id']][(int) $row['term']] = (int) $row['n'];
    }
    $termsStmt = $pdo->prepare('SELECT DISTINCT sec.grade_level_id, etg.term FROM effective_term_grades etg
        JOIN sections sec ON sec.id = etg.section_id
        WHERE etg.school_year_id = ? AND etg.transmuted_grade IS NOT NULL');
    $termsStmt->execute([$yearId]);
    $has = [];
    foreach ($termsStmt->fetchAll() as $row) {
        $has[(int) $row['grade_level_id']][(int) $row['term']] = true;
    }
    return ['fail' => $fail, 'has' => $has];
}
