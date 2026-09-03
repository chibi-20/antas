<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

/**
 * One-off fix for "Illegal mix of collations" errors thrown whenever section_subject_teachers
 * .sex_scope is compared against students.sex (class_record.php, gradeCalc.php, the
 * effective_term_grades view, etc.) — migration 0011 added sex_scope without an explicit
 * COLLATE clause, so it silently inherited whatever the server's default collation was at
 * ALTER-time, which can differ from whatever students.sex inherited back when 0001_init.sql
 * first ran. Rather than hunt down and patch every comparison individually (fragile, and the
 * effective_term_grades view has this comparison baked in where no PHP query can reach it),
 * this aligns sex_scope's actual stored collation to match students.sex once, fixing every
 * comparison everywhere in one shot. Safe to re-run — a no-op once they already match.
 */

$pdo = db();

function fix_out(string $msg): void
{
    fwrite(STDOUT, $msg . PHP_EOL);
}

$stmt = $pdo->prepare("SELECT COLLATION_NAME FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");

$stmt->execute(['students', 'sex']);
$targetCollation = $stmt->fetchColumn();
if (!$targetCollation) {
    fix_out('Could not find students.sex — aborting, nothing changed.');
    exit(1);
}

$stmt->execute(['section_subject_teachers', 'sex_scope']);
$currentCollation = $stmt->fetchColumn();
if (!$currentCollation) {
    fix_out('Could not find section_subject_teachers.sex_scope — aborting, nothing changed.');
    exit(1);
}

fix_out("students.sex collation:                        $targetCollation");
fix_out("section_subject_teachers.sex_scope collation:   $currentCollation");

if ($currentCollation === $targetCollation) {
    fix_out('Already match — nothing to do.');
    exit(0);
}

// $targetCollation comes from information_schema (MySQL's own fixed vocabulary of real
// collation names), never from user input, so building the ALTER statement with it directly
// is safe.
$pdo->exec("ALTER TABLE section_subject_teachers MODIFY COLUMN sex_scope ENUM('ALL','M','F','MIX') NOT NULL DEFAULT 'ALL' COLLATE $targetCollation");

$stmt->execute(['section_subject_teachers', 'sex_scope']);
$newCollation = $stmt->fetchColumn();
fix_out("Fixed — section_subject_teachers.sex_scope is now: $newCollation");
