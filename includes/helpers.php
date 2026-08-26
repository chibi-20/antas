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
 * Honor classification off the general average alone, per DepEd Order No. 15 s. 2026 — a
 * single "With Academic Excellence" tier for 90-100, replacing the old three-tier
 * Highest/High/Honor system this used to have. No additional criteria (e.g. no grade below a
 * floor in any subject, no failing marks in conduct) are applied since none were specified.
 */
function honor_classification(?float $average): ?string
{
    if ($average === null) {
        return null;
    }
    return $average >= 90 ? 'With Academic Excellence' : null;
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

/**
 * Rounds a grade to a whole number for Consolidated Grades/Card Slips — SF9 (the official
 * DepEd report card) only ever records whole-number grades, and that's the number a teacher
 * actually transcribes, so decimals there are just noise. Per-subject transmuted grades are
 * already always whole (see transmutation_table); this mainly matters for the Final Grade
 * (avg of 3 terms) and General Average, which are real averages and can land on a decimal.
 * Callers should round BEFORE passing to grade_display_class()/grade_descriptor_letter() so
 * the displayed number and its color/descriptor always agree with each other.
 *
 * Untyped param deliberately (not ?float): PDO returns DECIMAL columns as strings, and most
 * callers pass a raw DB fetch result straight through — this casts internally so callers
 * don't each need their own (float) cast first.
 */
function grade_whole($grade): ?int
{
    return $grade === null ? null : (int) round((float) $grade);
}

/**
 * DepEd Order No. 15 s. 2026's Qualitative Descriptors of Numeric Grades (Table 11) — shown
 * alongside a grade on Consolidated Grades/Card Slips, per subject and for the General
 * Average, so a reader sees the qualitative meaning without cross-referencing a separate
 * table. A grade that hasn't been computed yet (null) has no descriptor.
 */
function grade_descriptor(?float $grade): ?string
{
    if ($grade === null) {
        return null;
    }
    if ($grade >= 90) {
        return 'Advancing';
    }
    if ($grade >= 80) {
        return 'Benchmarking';
    }
    if ($grade >= 75) {
        return 'Connecting';
    }
    if ($grade >= 65) {
        return 'Developing';
    }
    return 'Emerging';
}

/** Single-letter form of grade_descriptor() (A-E) for tight table cells — pages that show
 * this must also show the legend (GRADE_DESCRIPTOR_LEGEND) somewhere on the page. */
function grade_descriptor_letter(?float $grade): ?string
{
    $descriptor = grade_descriptor($grade);
    return $descriptor === null ? null : $descriptor[0];
}

const GRADE_DESCRIPTOR_LEGEND = 'A = Advancing (90-100)  ·  B = Benchmarking (80-89)  ·  C = Connecting (75-79)  ·  D = Developing (65-74)  ·  E = Emerging (0-64)';

/** Subject ids a Head Teacher supervises this school year (head_teacher_assignments). */
function get_supervised_subject_ids(int $headTeacherId, int $schoolYearId): array
{
    $stmt = db()->prepare('SELECT subject_id FROM head_teacher_assignments WHERE head_teacher_id = ? AND school_year_id = ? AND is_active = 1');
    $stmt->execute([$headTeacherId, $schoolYearId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Every section where at least one of a Head Teacher's supervised subjects is actually
 * taught this school year — the section list behind the Grade Summary/At Risk tabs.
 */
function get_supervised_sections(int $headTeacherId, int $schoolYearId): array
{
    $stmt = db()->prepare('SELECT DISTINCT sec.id, sec.section_name, gl.name AS grade_level, gl.sort_order
        FROM sections sec
        JOIN grade_levels gl ON gl.id = sec.grade_level_id
        JOIN section_subject_teachers sst ON sst.section_id = sec.id AND sst.is_active = 1 AND sst.school_year_id = ?
        JOIN head_teacher_assignments hta ON hta.subject_id = sst.subject_id AND hta.school_year_id = sst.school_year_id
        WHERE hta.head_teacher_id = ? AND hta.is_active = 1 AND sec.is_active = 1
        ORDER BY gl.sort_order, sec.section_name');
    $stmt->execute([$schoolYearId, $headTeacherId]);
    return $stmt->fetchAll();
}

/**
 * Small tab strip shown under render_header() on every Head Teacher dashboard page — these
 * are separate files (not more $_GET branches on headteacher/dashboard.php, which already
 * juggles two views) that read as one dashboard because of this shared strip.
 */
function ht_tab_nav(string $active): string
{
    $tabs = [
        'review' => ['Review Dashboard', 'headteacher/dashboard.php'],
        'grade_summary' => ['Grade Summary', 'headteacher/grade_summary.php'],
        'proficiency' => ['Proficiency Level', 'headteacher/proficiency.php'],
        'at_risk' => ['At Risk', 'headteacher/at_risk.php'],
        'edit_history' => ['Edit History', 'headteacher/edit_history.php'],
    ];
    $html = '<div class="flex gap-1 mb-6 border-b border-slate-200">';
    foreach ($tabs as $key => [$label, $path]) {
        $isActive = $key === $active;
        $classes = $isActive
            ? 'border-accent-600 text-accent-700 font-medium'
            : 'border-transparent text-slate-500 hover:text-slate-700';
        $html .= '<a href="' . h(url('/' . $path)) . '" class="px-3 py-2 text-sm border-b-2 ' . $classes . '">' . h($label) . '</a>';
    }
    $html .= '</div>';
    return $html;
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
 * Music-Arts, PE-Health) — both the merged parent AND its individual components appear here,
 * in that order (parent immediately followed by its own children, each flagged 'is_child' =>
 * true so callers can style them as subordinate rows). Only the merged parent ever counts
 * toward the general average/ranking (see effective_term_grades in
 * db/migrations/0008_compound_subjects.sql, unchanged) — children are display-only, callers
 * that build derived reports (e.g. at-risk lists) must skip 'is_child' entries themselves.
 *
 * $subjects is ordered by subjects.sort_order (admin-configurable curriculum order, see
 * admin/subjects.php), not alphabetically.
 */
function get_consolidated_data(int $sectionId, int $schoolYearId, int $term): array
{
    $pdo = db();

    $rows = $pdo->prepare('SELECT sst.id AS sst_id, sub.id AS subject_id, sub.subject_name, sub.parent_subject_id, sub.sort_order, ss.status
        FROM section_subject_teachers sst
        JOIN subjects sub ON sub.id = sst.subject_id
        LEFT JOIN submission_status ss ON ss.section_subject_teacher_id = sst.id AND ss.term = ?
        WHERE sst.section_id = ? AND sst.school_year_id = ? AND sst.is_active = 1');
    $rows->execute([$term, $sectionId, $schoolYearId]);
    $rows = $rows->fetchAll();

    $subjects = [];
    $childGroups = [];
    foreach ($rows as $row) {
        if ($row['parent_subject_id'] === null) {
            $subjects[(int) $row['subject_id']] = $row + ['is_child' => false];
        } else {
            $childGroups[(int) $row['parent_subject_id']][] = $row + ['is_child' => true];
        }
    }
    if ($childGroups) {
        $placeholders = implode(',', array_fill(0, count($childGroups), '?'));
        $parentStmt = $pdo->prepare("SELECT id, subject_name, sort_order FROM subjects WHERE id IN ($placeholders)");
        $parentStmt->execute(array_keys($childGroups));
        foreach ($parentStmt->fetchAll() as $parent) {
            $children = $childGroups[(int) $parent['id']];
            usort($children, fn($a, $b) => strcmp($a['subject_name'], $b['subject_name'])); // Music-Arts before PE-Health
            $allPublished = count(array_filter($children, fn($c) => $c['status'] !== 'published')) === 0;
            $subjects[(int) $parent['id']] = [
                'sst_id' => null,
                'subject_id' => (int) $parent['id'],
                'subject_name' => $parent['subject_name'],
                'parent_subject_id' => null,
                'sort_order' => (int) $parent['sort_order'],
                'status' => $allPublished ? 'published' : 'pending',
                'is_child' => false,
                'children' => $children,
            ];
        }
    }
    $subjects = array_values($subjects);
    usort($subjects, fn($a, $b) => $a['sort_order'] <=> $b['sort_order'] ?: strcmp($a['subject_name'], $b['subject_name']));

    // Splice each compound parent's children in immediately after it, in curriculum-sorted
    // order — this flat, ordered list is what every caller (Consolidated Grades, Card Slips,
    // At Risk) actually loops over.
    $flatSubjects = [];
    foreach ($subjects as $subject) {
        $children = $subject['children'] ?? [];
        unset($subject['children']);
        $flatSubjects[] = $subject;
        foreach ($children as $child) {
            $flatSubjects[] = $child;
        }
    }
    $subjects = $flatSubjects;

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

    // Compound children (e.g. Music-Arts, PE-Health) are intentionally excluded from
    // effective_term_grades — they never count toward the average. But they still need to be
    // DISPLAYED, so fetch their own published grades separately, same publish-gate logic as
    // that view's plain-subject branch, scoped to just the child subject ids.
    $childSubjectIds = array_values(array_map(fn($s) => (int) $s['subject_id'], array_filter($subjects, fn($s) => $s['is_child'])));
    if ($childSubjectIds) {
        $childPlaceholders = implode(',', array_fill(0, count($childSubjectIds), '?'));
        $stmt = $pdo->prepare("SELECT tg.student_id, tg.term, tg.subject_id, tg.transmuted_grade
            FROM term_grades tg
            JOIN section_subject_teachers sst ON sst.subject_id = tg.subject_id AND sst.school_year_id = tg.school_year_id
            JOIN submission_status ss ON ss.section_subject_teacher_id = sst.id AND ss.term = tg.term
            WHERE ss.status = 'published' AND sst.section_id = ? AND tg.school_year_id = ?
              AND tg.term IN ($termsPlaceholders) AND tg.subject_id IN ($childPlaceholders)");
        $stmt->execute(array_merge([$sectionId, $schoolYearId], range(1, $term), $childSubjectIds));
        foreach ($stmt->fetchAll() as $row) {
            $gradesByTerm[(int) $row['term']][(int) $row['student_id']][(int) $row['subject_id']] = $row['transmuted_grade'];
        }
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
