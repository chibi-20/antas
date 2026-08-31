<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function compute_percentage(float $rawTotal, float $highestTotal): ?float
{
    if ($highestTotal <= 0) {
        return null;
    }
    return round(($rawTotal / $highestTotal) * 100, 2);
}

/** @param array{written_work_pct:int, performance_task_pct:int, examination_pct:int} $weights */
function compute_initial_grade(?float $wwPct, ?float $ptPct, ?float $exPct, array $weights): ?float
{
    if ($wwPct === null || $ptPct === null || $exPct === null) {
        return null;
    }
    $initial = ($wwPct * $weights['written_work_pct']
        + $ptPct * $weights['performance_task_pct']
        + $exPct * $weights['examination_pct']) / 100;
    return round($initial, 2);
}

/**
 * DepEd Order No. 15 s. 2026 — SY 2026-2027 Adjusted Transmutation Table (zero-based
 * grading transition). transmutation_table holds precise 2-decimal boundaries (e.g.
 * 70.00-71.17 -> 75), so the raw initial grade is looked up directly — it must NOT be
 * rounded to a whole number first, or values near a boundary can land in the wrong row.
 */
function compute_transmuted_grade(?float $initialGrade): ?float
{
    if ($initialGrade === null) {
        return null;
    }
    $clamped = max(0.0, min(100.0, $initialGrade));
    $stmt = db()->prepare('SELECT transmuted FROM transmutation_table WHERE ? BETWEEN min_initial AND max_initial LIMIT 1');
    $stmt->execute([$clamped]);
    $value = $stmt->fetchColumn();
    return $value !== false ? (float) $value : null;
}

// DepEd Order No. 15 s. 2026's breakdown of the Examinations component: Summative Test 1 =
// 30%, Summative Test 2 = 30%, Term Exam = 40% — of the EX component itself, not of the
// subject's overall grade (that's still the weight profile's examination_pct). Applied by
// POSITION (sort_order), since teacher/class_record.php locks EX to exactly this 3-item
// structure — no adding/removing/reordering, so the 1st/2nd/3rd item is always ST1/ST2/TE.
const EX_WEIGHTS = [0.30, 0.30, 0.40];

/**
 * Examinations gets its own weighted formula instead of WW/PT's plain sum-of-points (see
 * EX_WEIGHTS above). Follows the same "running grade from whatever's entered" rule the other
 * components use — an item not yet scored simply doesn't contribute, and the remaining
 * weights are renormalized so the result still lands on a 0-100 scale.
 */
function compute_ex_percentage(int $studentId, int $sstId, int $term): ?float
{
    $stmt = db()->prepare('SELECT ai.highest_possible_score, ss.raw_score
        FROM assessment_items ai
        LEFT JOIN student_scores ss ON ss.assessment_item_id = ai.id AND ss.student_id = ?
        WHERE ai.section_subject_teacher_id = ? AND ai.term = ? AND ai.component_type = \'EX\'
        ORDER BY ai.sort_order, ai.id');
    $stmt->execute([$studentId, $sstId, $term]);
    $rows = $stmt->fetchAll();

    if (count($rows) !== 3) {
        // Legacy/non-standard data (e.g. a term created before EX was locked to exactly 3
        // items) — fall back to the plain sum-of-points method every other component uses.
        $rawTotal = 0.0;
        $highestTotal = 0.0;
        $entered = 0;
        foreach ($rows as $row) {
            if ($row['raw_score'] === null) {
                continue;
            }
            $rawTotal += (float) $row['raw_score'];
            $highestTotal += (float) $row['highest_possible_score'];
            $entered++;
        }
        return $entered === 0 ? null : compute_percentage($rawTotal, $highestTotal);
    }

    $weightedSum = 0.0;
    $weightEntered = 0.0;
    foreach ($rows as $i => $row) {
        if ($row['raw_score'] === null || (float) $row['highest_possible_score'] <= 0) {
            continue;
        }
        $itemPct = (float) $row['raw_score'] / (float) $row['highest_possible_score'] * 100;
        $weightedSum += $itemPct * EX_WEIGHTS[$i];
        $weightEntered += EX_WEIGHTS[$i];
    }
    return $weightEntered > 0 ? round($weightedSum / $weightEntered, 2) : null;
}

/** Recomputes and persists term_grades for one student/subject/term from student_scores. */
function recompute_term_grade(int $studentId, int $subjectId, int $term, int $schoolYearId): void
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT wp.written_work_pct, wp.performance_task_pct, wp.examination_pct
        FROM subjects s JOIN grade_weight_profiles wp ON wp.id = s.weight_profile_id WHERE s.id = ?');
    $stmt->execute([$subjectId]);
    $weights = $stmt->fetch();
    if (!$weights) {
        return;
    }

    // Resolves the ONE section_subject_teachers row that actually covers this specific
    // student for this specific term (a subject can have more than one row per section now —
    // e.g. a different teacher each term, or split by sex — see
    // db/migrations/0011_scoped_teacher_assignments.sql). term_scope=0/sex_scope='ALL' means
    // "covers everyone/every term". Split into two queries (ALL first, specific sex as
    // fallback) rather than "(sst.sex_scope = 'ALL' OR sst.sex_scope = st.sex)" — that shape
    // (a literal-string comparison OR'd with a column comparison) can throw "Illegal mix of
    // collations" on some MySQL versions even when every column's stored collation genuinely
    // matches (see db/fix_sex_scope_collation.php). term_scope is numeric, so it's unaffected
    // and stays a plain OR in both queries. The business rule guarantees these two cases never
    // both match for the same student/term, so trying ALL first and falling back to the
    // student's actual sex is equivalent to the original single-query resolution — the
    // original's ORDER BY was already just a tiebreaker for a state that shouldn't occur, not
    // the real safety net.
    $stmt = $pdo->prepare("SELECT sst.id FROM section_subject_teachers sst
        JOIN students st ON st.section_id = sst.section_id
        WHERE st.id = ? AND sst.subject_id = ? AND sst.school_year_id = ?
          AND sst.is_active = 1
          AND (sst.term_scope = 0 OR sst.term_scope = ?)
          AND sst.sex_scope = 'ALL'
        ORDER BY (sst.term_scope <> 0) DESC, sst.id DESC
        LIMIT 1");
    $stmt->execute([$studentId, $subjectId, $schoolYearId, $term]);
    $sstId = $stmt->fetchColumn();

    if (!$sstId) {
        $sexStmt = $pdo->prepare('SELECT sex FROM students WHERE id = ?');
        $sexStmt->execute([$studentId]);
        $studentSex = $sexStmt->fetchColumn();
        if ($studentSex) {
            $stmt = $pdo->prepare("SELECT sst.id FROM section_subject_teachers sst
                WHERE sst.section_id = (SELECT section_id FROM students WHERE id = ?)
                  AND sst.subject_id = ? AND sst.school_year_id = ?
                  AND sst.is_active = 1
                  AND (sst.term_scope = 0 OR sst.term_scope = ?)
                  AND sst.sex_scope = ?
                ORDER BY (sst.term_scope <> 0) DESC, sst.id DESC
                LIMIT 1");
            $stmt->execute([$studentId, $subjectId, $schoolYearId, $term, $studentSex]);
            $sstId = $stmt->fetchColumn();
        }
    }

    if (!$sstId) {
        return;
    }
    $sstId = (int) $sstId;

    // A component (WW/PT) contributes a running percentage as soon as ANY of its items has a
    // score — computed only from the items actually entered so far, not penalized for ones
    // still blank. This is deliberate: requiring every single item before showing anything
    // meant one forgotten/absent score could hide a student's entire grade (and any failing
    // grade with it) behind a blank "—" all the way through to the printed card slip. The
    // number here is a running grade that updates as more scores come in, same as a normal
    // gradebook — not a claim that the term is complete. EX uses its own weighted formula
    // (compute_ex_percentage(), same running-grade philosophy) instead of this plain sum.
    $componentPct = [];
    $itemStmt = $pdo->prepare('SELECT
            COALESCE(SUM(CASE WHEN ss.raw_score IS NOT NULL THEN ss.raw_score ELSE 0 END), 0) AS raw_total,
            COALESCE(SUM(CASE WHEN ss.raw_score IS NOT NULL THEN ai.highest_possible_score ELSE 0 END), 0) AS highest_total,
            SUM(CASE WHEN ss.raw_score IS NOT NULL THEN 1 ELSE 0 END) AS entered_count
        FROM assessment_items ai
        LEFT JOIN student_scores ss ON ss.assessment_item_id = ai.id AND ss.student_id = ?
        WHERE ai.section_subject_teacher_id = ? AND ai.term = ? AND ai.component_type = ?');
    foreach (['WW', 'PT'] as $type) {
        $itemStmt->execute([$studentId, $sstId, $term, $type]);
        $row = $itemStmt->fetch();
        $hasNoData = (int) $row['entered_count'] === 0;
        $componentPct[$type] = $hasNoData ? null : compute_percentage((float) $row['raw_total'], (float) $row['highest_total']);
    }
    $componentPct['EX'] = compute_ex_percentage($studentId, $sstId, $term);

    $initial = compute_initial_grade($componentPct['WW'], $componentPct['PT'], $componentPct['EX'], $weights);
    $transmuted = compute_transmuted_grade($initial);

    $pdo->prepare('INSERT INTO term_grades (student_id, subject_id, term, ww_pct, pt_pct, ex_pct, initial_grade, transmuted_grade, school_year_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE ww_pct = VALUES(ww_pct), pt_pct = VALUES(pt_pct), ex_pct = VALUES(ex_pct),
            initial_grade = VALUES(initial_grade), transmuted_grade = VALUES(transmuted_grade)')
        ->execute([$studentId, $subjectId, $term, $componentPct['WW'], $componentPct['PT'], $componentPct['EX'], $initial, $transmuted, $schoolYearId]);
}

/**
 * Recomputes and persists a compound subject's (e.g. MAPEH) term_grades row — the average
 * of its children's (e.g. Music-Arts, PE-Health) transmuted grades. Only produces a value
 * once every active child has one; otherwise leaves it null (same "incomplete = no grade
 * yet" rule as a normal subject's WW/PT/EX components). This is the merged number that
 * downstream views/pages ultimately read for the compound subject — whether it actually
 * COUNTS toward ranking is a separate, publish-status-gated concern (see
 * db/migrations/0008_compound_subjects.sql's effective_term_grades view).
 */
function recompute_compound_term_grade(int $studentId, int $parentSubjectId, int $term, int $schoolYearId): void
{
    $pdo = db();

    $children = $pdo->prepare('SELECT id FROM subjects WHERE parent_subject_id = ? AND is_active = 1');
    $children->execute([$parentSubjectId]);
    $childIds = $children->fetchAll(PDO::FETCH_COLUMN);
    if (!$childIds) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($childIds), '?'));
    $stmt = $pdo->prepare("SELECT transmuted_grade FROM term_grades WHERE student_id = ? AND term = ? AND school_year_id = ? AND subject_id IN ($placeholders)");
    $stmt->execute(array_merge([$studentId, $term, $schoolYearId], $childIds));
    $grades = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $transmuted = null;
    if (count($grades) === count($childIds) && !in_array(null, $grades, true)) {
        $transmuted = (float) round(array_sum(array_map('floatval', $grades)) / count($grades));
    }

    $pdo->prepare('INSERT INTO term_grades (student_id, subject_id, term, transmuted_grade, school_year_id)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE transmuted_grade = VALUES(transmuted_grade)')
        ->execute([$studentId, $parentSubjectId, $term, $transmuted, $schoolYearId]);
}

/**
 * Recomputes term_grades for every active student in the section covered by this
 * assignment. If the assignment's subject is a compound child (e.g. Music-Arts under
 * MAPEH), also recomputes the parent's merged grade for the same students/term.
 */
function recompute_term_grades_for_assignment(int $sectionSubjectTeacherId, int $term): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT subject_id, school_year_id, section_id, sex_scope FROM section_subject_teachers WHERE id = ?');
    $stmt->execute([$sectionSubjectTeacherId]);
    $sst = $stmt->fetch();
    if (!$sst) {
        return;
    }

    $parentStmt = $pdo->prepare('SELECT parent_subject_id FROM subjects WHERE id = ?');
    $parentStmt->execute([$sst['subject_id']]);
    $parentSubjectId = $parentStmt->fetchColumn();

    // Only the students this specific assignment actually covers — e.g. a boys-only TLE
    // teacher's save must not touch girls' grades, even though they share a section_id.
   // Only recompute students covered by this assignment.
$sexScope = strtoupper(trim((string) ($sst['sex_scope'] ?? 'ALL')));

if ($sexScope === 'ALL') {
    $students = $pdo->prepare('
        SELECT id
        FROM students
        WHERE section_id = ?
          AND is_active = 1
    ');

    $students->execute([
        $sst['section_id'],
    ]);
} else {
    $students = $pdo->prepare('
        SELECT id
        FROM students
        WHERE section_id = ?
          AND is_active = 1
          AND sex = ?
    ');

    $students->execute([
        $sst['section_id'],
        $sexScope,
    ]);
}

    foreach ($students->fetchAll(PDO::FETCH_COLUMN) as $studentId) {
        recompute_term_grade((int) $studentId, (int) $sst['subject_id'], $term, (int) $sst['school_year_id']);
        if ($parentSubjectId) {
            recompute_compound_term_grade((int) $studentId, (int) $parentSubjectId, $term, (int) $sst['school_year_id']);
        }
    }
}
