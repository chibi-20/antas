<?php
declare(strict_types=1);

function h($value): string
{
    return htmlspecialchars((string) $value);
}

/** @param array<int, array<string, mixed>> $rows */
function select_options(array $rows, string $valueKey, string $labelKey, $selected = null): string
{
    $html = '';
    foreach ($rows as $row) {
        $value = (string) $row[$valueKey];
        $isSelected = $selected !== null && (string) $selected === $value;
        $html .= '<option value="' . h($value) . '"' . ($isSelected ? ' selected' : '') . '>' . h($row[$labelKey]) . '</option>';
    }
    return $html;
}

/**
 * Honor classification off the general average alone, per the thresholds the user gave —
 * no additional DepEd-style criteria (e.g. no grade below a floor in any subject, no failing
 * marks in conduct) are applied here since none were specified.
 */
function honor_classification(?float $average): ?string
{
    if ($average === null) {
        return null;
    }
    if ($average >= 98) {
        return 'With Highest Honor';
    }
    if ($average >= 95) {
        return 'With High Honor';
    }
    if ($average >= 90) {
        return 'With Honor';
    }
    return null;
}

/**
 * CSS classes to make a failing grade (below 75, the DepEd passing mark) visually impossible
 * to miss wherever a transmuted grade / final grade / general average is displayed — class
 * record, Head Teacher review, consolidated grades, card slips, ranking. A grade that hasn't
 * been computed yet (null) gets no special styling.
 */
function grade_display_class(?float $grade): string
{
    return $grade !== null && $grade < 75 ? 'text-rose-600 font-semibold' : '';
}

function require_active_school_year(): array
{
    $year = active_school_year();
    if (!$year) {
        forbidden('No active school year is set. Ask an admin to mark one active under School Years.');
    }
    return $year;
}

/**
 * Picks which school year an admin list/filter should default to when there's no explicit
 * choice: the active one if set, otherwise the most recent ($schoolYears sorted DESC by
 * year_label). Falling back to 0/null here instead would silently filter a list against a
 * school year that matches nothing — the dropdown looks like it has a year picked, but the
 * query underneath returns zero rows. New school years aren't active until an admin marks
 * one, so this fallback matters every time a new one is created.
 *
 * @param array<int, array<string, mixed>> $schoolYears
 */
function default_school_year_id(array $schoolYears): ?int
{
    foreach ($schoolYears as $sy) {
        if ($sy['is_active']) {
            return (int) $sy['id'];
        }
    }
    return $schoolYears ? (int) $schoolYears[0]['id'] : null;
}

/**
 * Builds the published-only grade matrix for a section/term: which subjects are taught,
 * which students are enrolled, each student's per-subject transmuted grade for every term up
 * through the one requested (gradesByTerm; grades is a current-term-only alias kept for
 * export_csv.php), each student's per-subject final grade once Term 3 is in view (finalGrades
 * — the average of Terms 1-3, only set once all three exist), and each student's overall
 * general average + rank for the requested term from general_average_view (which applies the
 * same published-only filter).
 *
 * Compound subjects (e.g. MAPEH) are taught/reviewed as separate components (e.g.
 * Music-Arts, PE-Health) but shown here as ONE merged row — published only once every
 * component is, using the parent's own term_grades row (see gradeCalc.php's
 * recompute_compound_term_grade()). The individual components never appear on their own.
 */
function get_consolidated_data(int $sectionId, int $schoolYearId, int $term): array
{
    $pdo = db();

    $rows = $pdo->prepare('SELECT sst.id AS sst_id, sub.id AS subject_id, sub.subject_name, sub.parent_subject_id, ss.status
        FROM section_subject_teachers sst
        JOIN subjects sub ON sub.id = sst.subject_id
        LEFT JOIN submission_status ss ON ss.section_subject_teacher_id = sst.id AND ss.term = ?
        WHERE sst.section_id = ? AND sst.school_year_id = ? AND sst.is_active = 1
        ORDER BY sub.subject_name');
    $rows->execute([$term, $sectionId, $schoolYearId]);
    $rows = $rows->fetchAll();

    $subjects = [];
    $childGroups = [];
    foreach ($rows as $row) {
        if ($row['parent_subject_id'] === null) {
            $subjects[(int) $row['subject_id']] = $row;
        } else {
            $childGroups[(int) $row['parent_subject_id']][] = $row;
        }
    }
    if ($childGroups) {
        $placeholders = implode(',', array_fill(0, count($childGroups), '?'));
        $parentStmt = $pdo->prepare("SELECT id, subject_name FROM subjects WHERE id IN ($placeholders)");
        $parentStmt->execute(array_keys($childGroups));
        foreach ($parentStmt->fetchAll() as $parent) {
            $children = $childGroups[(int) $parent['id']];
            $allPublished = count(array_filter($children, fn($c) => $c['status'] !== 'published')) === 0;
            $subjects[(int) $parent['id']] = [
                'sst_id' => null,
                'subject_id' => (int) $parent['id'],
                'subject_name' => $parent['subject_name'],
                'parent_subject_id' => null,
                'status' => $allPublished ? 'published' : 'pending',
            ];
        }
    }
    $subjects = array_values($subjects);
    usort($subjects, fn($a, $b) => strcmp($a['subject_name'], $b['subject_name']));

    // Male-then-Female, alphabetical within each — the standard class record roster order.
    $students = $pdo->prepare("SELECT * FROM students WHERE section_id = ? AND is_active = 1 ORDER BY FIELD(sex, 'M', 'F'), full_name");
    $students->execute([$sectionId]);
    $students = $students->fetchAll();

    // Published transmuted grades for every term up through the one being viewed — Term 2/3
    // views show earlier terms alongside the current one (effective_term_grades already
    // resolves compound-subject merging and the publish gate, same as a single-term lookup).
    $gradesByTerm = [];
    for ($t = 1; $t <= $term; $t++) {
        $gradesByTerm[$t] = [];
    }
    $termsPlaceholders = implode(',', array_fill(0, $term, '?'));
    $stmt = $pdo->prepare("SELECT student_id, term, subject_id, transmuted_grade FROM effective_term_grades
        WHERE section_id = ? AND school_year_id = ? AND term IN ($termsPlaceholders)");
    $stmt->execute(array_merge([$sectionId, $schoolYearId], range(1, $term)));
    foreach ($stmt->fetchAll() as $row) {
        $gradesByTerm[(int) $row['term']][(int) $row['student_id']][(int) $row['subject_id']] = $row['transmuted_grade'];
    }
    $grades = $gradesByTerm[$term]; // back-compat: current-term-only view (export_csv.php)

    // Per-subject final grade — average of Term 1-3 — only computable once Term 3 is being
    // viewed, since that's the earliest point all three terms could exist.
    $finalGrades = [];
    if ($term === 3) {
        foreach ($students as $student) {
            foreach ($subjects as $subject) {
                $vals = [];
                for ($t = 1; $t <= 3; $t++) {
                    $g = $gradesByTerm[$t][$student['id']][$subject['subject_id']] ?? null;
                    if ($g === null) {
                        $vals = null;
                        break;
                    }
                    $vals[] = (float) $g;
                }
                if ($vals) {
                    $finalGrades[$student['id']][$subject['subject_id']] = round(array_sum($vals) / 3, 2);
                }
            }
        }
    }

    $averages = [];
    $stmt = $pdo->prepare('SELECT student_id, average, rank_in_section FROM general_average_view WHERE section_id = ? AND term = ? AND school_year_id = ?');
    $stmt->execute([$sectionId, $term, $schoolYearId]);
    foreach ($stmt->fetchAll() as $row) {
        $averages[$row['student_id']] = $row;
    }

    return [
        'subjects' => $subjects,
        'students' => $students,
        'grades' => $grades,
        'gradesByTerm' => $gradesByTerm,
        'finalGrades' => $finalGrades,
        'averages' => $averages,
    ];
}
