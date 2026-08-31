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

    // A subject can now have more than one section_subject_teachers row per section (a
    // different teacher each term, or split by sex — see
    // db/migrations/0011_scoped_teacher_assignments.sql) — filtered here to just the rows
    // relevant to the requested term (term_scope=0 means "every term"). Every matching row is
    // accumulated into that subject's 'assignments' list rather than the old one-row-per-
    // subject assumption, which used to silently let a later row clobber an earlier one.
    $rows = $pdo->prepare('SELECT sst.id AS sst_id, sst.sex_scope, sub.id AS subject_id, sub.subject_name, sub.parent_subject_id, sub.sort_order, ss.status
        FROM section_subject_teachers sst
        JOIN subjects sub ON sub.id = sst.subject_id
        LEFT JOIN submission_status ss ON ss.section_subject_teacher_id = sst.id AND ss.term = ?
        WHERE sst.section_id = ? AND sst.school_year_id = ? AND sst.is_active = 1
          AND (sst.term_scope = 0 OR sst.term_scope = ?)');
    $rows->execute([$term, $sectionId, $schoolYearId, $term]);
    $rows = $rows->fetchAll();

    $subjectMeta = [];
    $assignmentsBySubject = [];
    foreach ($rows as $row) {
        $sid = (int) $row['subject_id'];
        $subjectMeta[$sid] = ['subject_name' => $row['subject_name'], 'parent_subject_id' => $row['parent_subject_id'], 'sort_order' => (int) $row['sort_order']];
        $assignmentsBySubject[$sid][] = ['sst_id' => (int) $row['sst_id'], 'sex_scope' => $row['sex_scope'], 'status' => $row['status'] ?? 'not_started'];
    }

    $subjects = [];
    $childGroups = [];
    foreach ($subjectMeta as $sid => $meta) {
        $assignments = $assignmentsBySubject[$sid];
        // Published only once EVERY assignment covering this subject/term is published — a
        // sex-split subject (e.g. boys' TLE published, girls' not) is "pending" as a whole
        // for this aggregate; per-student resolution (subject_assignment_for_student()) is
        // what callers actually need for the Pending badge/review link on a specific row.
        $status = count(array_filter($assignments, fn($a) => $a['status'] !== 'published')) === 0 ? 'published' : 'pending';
        $entry = [
            'sst_id' => count($assignments) === 1 ? $assignments[0]['sst_id'] : null,
            'subject_id' => $sid,
            'subject_name' => $meta['subject_name'],
            'parent_subject_id' => $meta['parent_subject_id'],
            'sort_order' => $meta['sort_order'],
            'status' => $status,
            'assignments' => $assignments,
            'is_child' => $meta['parent_subject_id'] !== null,
        ];
        if ($meta['parent_subject_id'] === null) {
            $subjects[$sid] = $entry;
        } else {
            $childGroups[(int) $meta['parent_subject_id']][] = $entry;
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
                // No real assignments of its own — its grade is the merged average of its
                // children (recompute_compound_term_grade()); subject_assignment_for_student()
                // falls back to this entry's own 'status' aggregate for a compound parent.
                'assignments' => [],
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
    // that view's plain-subject branch (including the term_scope/sex_scope/is_active
    // predicates — without them, a sex-split child subject would double-count or leak here
    // exactly like effective_term_grades used to), scoped to just the child subject ids.
    $childSubjectIds = array_values(array_map(fn($s) => (int) $s['subject_id'], array_filter($subjects, fn($s) => $s['is_child'])));
    if ($childSubjectIds) {
        $childPlaceholders = implode(',', array_fill(0, count($childSubjectIds), '?'));
        // UNION ALL of two plain queries rather than "(sst.sex_scope = 'ALL' OR sst.sex_scope
        // = st.sex)" — that shape (a literal-string comparison OR'd with a column comparison
        // in one expression) can throw "Illegal mix of collations" on some MySQL versions even
        // when every column's stored collation genuinely matches (see
        // db/fix_sex_scope_collation.php). The two branches are mutually exclusive by
        // definition (sex_scope can't be both 'ALL' and a specific sex on the same row), so
        // UNION ALL needs no de-duplication.
        $stmt = $pdo->prepare("SELECT tg.student_id, tg.term, tg.subject_id, tg.transmuted_grade
            FROM term_grades tg
            JOIN section_subject_teachers sst ON sst.subject_id = tg.subject_id AND sst.school_year_id = tg.school_year_id
            JOIN submission_status ss ON ss.section_subject_teacher_id = sst.id AND ss.term = tg.term
            WHERE ss.status = 'published' AND sst.section_id = ? AND tg.school_year_id = ?
              AND sst.is_active = 1
              AND (sst.term_scope = 0 OR sst.term_scope = tg.term)
              AND sst.sex_scope = 'ALL'
              AND tg.term IN ($termsPlaceholders) AND tg.subject_id IN ($childPlaceholders)
            UNION ALL
            SELECT tg.student_id, tg.term, tg.subject_id, tg.transmuted_grade
            FROM term_grades tg
            JOIN students st ON st.id = tg.student_id
            JOIN section_subject_teachers sst ON sst.subject_id = tg.subject_id AND sst.school_year_id = tg.school_year_id
            JOIN submission_status ss ON ss.section_subject_teacher_id = sst.id AND ss.term = tg.term
            WHERE ss.status = 'published' AND sst.section_id = ? AND tg.school_year_id = ?
              AND sst.is_active = 1
              AND (sst.term_scope = 0 OR sst.term_scope = tg.term)
              AND sst.sex_scope = st.sex
              AND tg.term IN ($termsPlaceholders) AND tg.subject_id IN ($childPlaceholders)");
        $branchParams = array_merge([$sectionId, $schoolYearId], range(1, $term), $childSubjectIds);
        $stmt->execute(array_merge($branchParams, $branchParams));
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

/**
 * Resolves which of a subject's (possibly several, per
 * db/migrations/0011_scoped_teacher_assignments.sql) assignments actually covers a given
 * student, by sex — an 'ALL' assignment as fallback, or null if the subject has no real
 * assignments of its own (a compound parent like MAPEH; callers should fall back to that
 * subject entry's own 'status', already correctly aggregated from its children).
 *
 * Needed because a sex-split subject (e.g. one teacher for boys' TLE, another for girls') can
 * be published for one half and still pending for the other — "is this subject published"
 * stopped being a single fact per subject the moment more than one assignment can cover it.
 *
 * @param array{assignments: array<int, array{sst_id:int, sex_scope:string, status:string}>} $subject
 * @param array{sex: string} $student
 */
function subject_assignment_for_student(array $subject, array $student): ?array
{
    foreach ($subject['assignments'] as $assignment) {
        if ($assignment['sex_scope'] === $student['sex']) {
            return $assignment;
        }
    }
    foreach ($subject['assignments'] as $assignment) {
        if ($assignment['sex_scope'] === 'ALL') {
            return $assignment;
        }
    }
    return null;
}

/**
 * Checks whether inserting/updating a section_subject_teachers row with the given
 * (term_scope, sex_scope) would violate the TLE scoping business rule: active rows for a
 * (section, subject, school year) must be either one term_scope=0/sex_scope=ALL row, or 1-3
 * rows with distinct specific term_scope values, each covered term being either one ALL row
 * or an M+F pair. The DB unique key only rejects exact-duplicate tuples — it can't catch a
 * term_scope=0 row coexisting with a specific-term row, or an ALL row coexisting with an M/F
 * row for the same term. Returns an error message if there's a conflict, null if clear.
 */
function sst_scope_conflict(PDO $pdo, int $sectionId, int $subjectId, int $schoolYearId, int $termScope, string $sexScope, ?int $excludeId = null): ?string
{
    $sql = 'SELECT term_scope, sex_scope FROM section_subject_teachers
        WHERE section_id = ? AND subject_id = ? AND school_year_id = ? AND is_active = 1';
    $params = [$sectionId, $subjectId, $schoolYearId];
    if ($excludeId !== null) {
        $sql .= ' AND id != ?';
        $params[] = $excludeId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $existing = $stmt->fetchAll();

    foreach ($existing as $row) {
        $existingTerm = (int) $row['term_scope'];
        if (($termScope === 0) !== ($existingTerm === 0)) {
            return 'A whole-year assignment cannot coexist with a term-specific assignment for the same section and subject — deactivate the other one first.';
        }
    }

    foreach ($existing as $row) {
        if ((int) $row['term_scope'] !== $termScope) {
            continue;
        }
        $existingSex = $row['sex_scope'];
        if ($sexScope === 'ALL' || $existingSex === 'ALL') {
            return 'This term already has an assignment for this section/subject that covers all students — deactivate it first.';
        }
        if ($existingSex === $sexScope) {
            return 'This term already has a ' . ($sexScope === 'M' ? 'Male-only' : 'Female-only') . ' assignment for this section/subject.';
        }
    }

    return null;
}

/**
 * Refuses to narrow an existing assignment's coverage (whole-year -> term-specific, or
 * all-students -> one sex) once it already has real grading activity — assessment items or
 * any submission_status past not_started. Narrowing would silently orphan scores/status rows
 * that belong to terms/students the row no longer covers; that's a data-integrity decision
 * for the admin to resolve manually (e.g. deactivate and recreate), not something to patch up.
 */
function sst_narrowing_blocked(PDO $pdo, array $oldRow, int $newTermScope, string $newSexScope): ?string
{
    $isNarrowing = ((int) $oldRow['term_scope'] === 0 && $newTermScope !== 0)
        || ($oldRow['sex_scope'] === 'ALL' && $newSexScope !== 'ALL');
    if (!$isNarrowing) {
        return null;
    }

    $sstId = (int) $oldRow['id'];
    $itemsStmt = $pdo->prepare('SELECT COUNT(*) FROM assessment_items WHERE section_subject_teacher_id = ?');
    $itemsStmt->execute([$sstId]);
    if ((int) $itemsStmt->fetchColumn() > 0) {
        return 'This assignment already has assessment items — narrowing its Term or Applies To scope is refused. Deactivate it and create a new assignment instead.';
    }

    $statusStmt = $pdo->prepare("SELECT COUNT(*) FROM submission_status WHERE section_subject_teacher_id = ? AND status <> 'not_started'");
    $statusStmt->execute([$sstId]);
    if ((int) $statusStmt->fetchColumn() > 0) {
        return 'This assignment already has submitted grading activity — narrowing its Term or Applies To scope is refused. Deactivate it and create a new assignment instead.';
    }

    return null;
}

/**
 * Read-only display helper for the self-claim UI (teacher/claim.php): reports which parts
 * of a (section, subject, school year) are still open to claim vs. already taken, and by
 * whom. This is NEVER the write-time authority — sst_scope_conflict() re-validates at
 * submit time regardless of what this returns, since a slot shown open here can be claimed
 * by someone else microseconds later.
 *
 * Returns ['whole_year_open' => bool, 'whole_year_taken_by' => ?string,
 *          'terms' => [1 => ['open' => [...subset of ALL/M/F...], 'taken' => ['M'=>name,...]], 2 => [...], 3 => [...]]]
 * — 'terms' is empty when the whole year is either open or taken as one unit, since the
 * business rule (see sst_scope_conflict()) never lets term_scope=0 coexist with per-term rows.
 */
function claim_availability(PDO $pdo, int $sectionId, int $subjectId, int $schoolYearId): array
{
    $stmt = $pdo->prepare('SELECT sst.term_scope, sst.sex_scope, u.full_name AS teacher_name
        FROM section_subject_teachers sst
        JOIN users u ON u.id = sst.teacher_id
        WHERE sst.section_id = ? AND sst.subject_id = ? AND sst.school_year_id = ? AND sst.is_active = 1');
    $stmt->execute([$sectionId, $subjectId, $schoolYearId]);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        return ['whole_year_open' => true, 'whole_year_taken_by' => null, 'terms' => []];
    }

    foreach ($rows as $row) {
        if ((int) $row['term_scope'] === 0) {
            return ['whole_year_open' => false, 'whole_year_taken_by' => $row['teacher_name'], 'terms' => []];
        }
    }

    $terms = [];
    for ($t = 1; $t <= 3; $t++) {
        $taken = [];
        foreach ($rows as $row) {
            if ((int) $row['term_scope'] === $t) {
                $taken[$row['sex_scope']] = $row['teacher_name'];
            }
        }
        $open = isset($taken['ALL']) ? [] : array_values(array_diff(['ALL', 'M', 'F'], array_keys($taken)));
        if ($taken && !isset($taken['ALL'])) {
            // Once one sex is claimed, "All Students" is no longer offered for this term —
            // it would conflict with the half already taken.
            $open = array_values(array_diff($open, ['ALL']));
        }
        $terms[$t] = ['open' => $open, 'taken' => $taken];
    }

    return ['whole_year_open' => false, 'whole_year_taken_by' => null, 'terms' => $terms];
}

/**
 * Sections a teacher can browse to self-claim, resolved from their active claim_eligibility
 * rows (subject + grade level) joined to that grade level's active sections for the school
 * year, each annotated with claim_availability(). Returned grouped by (subject, grade level)
 * — one group per eligibility row, since a teacher can hold several (e.g. TLE Grade 10 +
 * TLE Grade 7 + Araling Panlipunan Grade 8 all at once).
 */
function get_claimable_sections(int $teacherId, int $schoolYearId): array
{
    $pdo = db();
    $eligStmt = $pdo->prepare('SELECT ce.subject_id, ce.grade_level_id, sub.subject_name, gl.name AS grade_level, gl.sort_order
        FROM claim_eligibility ce
        JOIN subjects sub ON sub.id = ce.subject_id
        JOIN grade_levels gl ON gl.id = ce.grade_level_id
        WHERE ce.teacher_id = ? AND ce.school_year_id = ? AND ce.is_active = 1
        ORDER BY gl.sort_order, sub.subject_name');
    $eligStmt->execute([$teacherId, $schoolYearId]);
    $eligibility = $eligStmt->fetchAll();

    $sectionStmt = $pdo->prepare('SELECT id, section_name FROM sections WHERE grade_level_id = ? AND school_year_id = ? AND is_active = 1 ORDER BY section_name');

    $groups = [];
    foreach ($eligibility as $elig) {
        $sectionStmt->execute([$elig['grade_level_id'], $schoolYearId]);
        $sectionRows = [];
        foreach ($sectionStmt->fetchAll() as $sec) {
            $sectionRows[] = [
                'section_id' => (int) $sec['id'],
                'section_name' => $sec['section_name'],
                'availability' => claim_availability($pdo, (int) $sec['id'], (int) $elig['subject_id'], $schoolYearId),
            ];
        }
        $groups[] = [
            'subject_id' => (int) $elig['subject_id'],
            'subject_name' => $elig['subject_name'],
            'grade_level' => $elig['grade_level'],
            'sections' => $sectionRows,
        ];
    }

    return $groups;
}

/**
 * Turns a full name into a username for admin/import_teachers.php: last word = surname,
 * everything before it (minus single-letter initials like "V.") = given names, concatenated
 * + lowercased, joined to the lowercased surname with "_". Falls back to just the first
 * given-name word if the combined candidate would exceed 20 characters.
 *
 * Known, deliberate simplification: a multi-word surname particle ("Dela Cruz", "De Guzman",
 * "Del Rosario") gets partially absorbed into the given-name blob, since only the literal
 * last word is treated as the surname. Still produces a unique, working username — just not
 * a perfectly "correct"-looking surname split. Not worth a fuzzy particle whitelist here.
 */
function generate_username(string $fullName): string
{
    $words = preg_split('/\s+/u', trim($fullName));
    $words = array_values(array_filter($words, fn($w) => $w !== ''));
    if (!$words) {
        return 'teacher';
    }

    // Drop trailing generational suffixes (Jr., Sr., II-V) before picking the surname —
    // otherwise "Pedro Santos Jr." would surname-ify as "jr".
    $suffixes = ['jr', 'jr.', 'sr', 'sr.', 'ii', 'iii', 'iv', 'v'];
    while (count($words) > 1 && in_array(mb_strtolower(rtrim(end($words), '.')), $suffixes, true)) {
        array_pop($words);
    }

    $clean = fn($w) => mb_strtolower(preg_replace('/[^\p{L}\p{N}]/u', '', $w));

    if (count($words) === 1) {
        return $clean($words[0]) ?: 'teacher';
    }

    $surname = $clean(array_pop($words));
    // Drop single-letter "initial" tokens (e.g. "V." -> "v", length 1 after cleaning).
    $givenWords = array_values(array_filter($words, fn($w) => mb_strlen($clean($w)) > 1));
    if (!$givenWords) {
        // Everything before the surname was initials only (e.g. "J. Reyes") — surname alone,
        // no leading underscore.
        return $surname !== '' ? $surname : 'teacher';
    }

    $allGiven = implode('', array_map($clean, $givenWords));
    $candidate = $allGiven . '_' . $surname;
    if (mb_strlen($candidate) > 20) {
        $candidate = $clean($givenWords[0]) . '_' . $surname;
    }
    return $candidate;
}

/** Appends 2, 3, 4... until unique against both the DB and the rest of this import batch. */
function resolve_unique_username(PDO $pdo, string $base, array &$usedInBatch): string
{
    $existsStmt = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
    $candidate = $base;
    $n = 2;
    while (true) {
        if (!isset($usedInBatch[$candidate])) {
            $existsStmt->execute([$candidate]);
            if (!$existsStmt->fetchColumn()) {
                break;
            }
        }
        $candidate = $base . $n;
        $n++;
    }
    $usedInBatch[$candidate] = true;
    return $candidate;
}
