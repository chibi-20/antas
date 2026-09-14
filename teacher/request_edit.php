<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/teacher/index.php');
}

csrf_verify();

$sstId = (int) ($_POST['sst_id'] ?? 0);
$term = (int) ($_POST['term'] ?? 0);
$reason = trim((string) ($_POST['reason'] ?? ''));

// require_own_assignment() returns the section_subject_teacher assignment row (already
// validated as belonging to the current user), not the user themselves — its own teacher_id
// is the correct id to attribute this request to.
$assignment = require_own_assignment($sstId);
assert_covers_term($assignment, $term, '/teacher/index.php',
    "You don't teach {$assignment['subject_name']} for {$assignment['grade_level']} - {$assignment['section_name']} in Term $term — here are your classes.");

$pdo = db();
$stmt = $pdo->prepare('SELECT status FROM submission_status WHERE section_subject_teacher_id = ? AND term = ?');
$stmt->execute([$sstId, $term]);
$status = $stmt->fetchColumn();

$pendingStmt = $pdo->prepare("SELECT 1 FROM grade_edit_requests WHERE section_subject_teacher_id = ? AND term = ? AND status = 'pending'");
$pendingStmt->execute([$sstId, $term]);
$hasPending = (bool) $pendingStmt->fetchColumn();

if ($reason === '') {
    flash_set('error', 'A reason is required to request an edit.');
} elseif ($status !== 'published') {
    flash_set('error', 'Only a published term can have an edit requested.');
} elseif ($hasPending) {
    flash_set('error', 'An edit request is already pending for this term.');
} else {
    $pdo->prepare('INSERT INTO grade_edit_requests (section_subject_teacher_id, term, requested_by, reason) VALUES (?, ?, ?, ?)')
        ->execute([$sstId, $term, $assignment['teacher_id'], $reason]);

    $supervisors = head_teachers_for_subject((int) $assignment['subject_id'], (int) $assignment['school_year_id']);
    if ($supervisors) {
        $teacherNameStmt = $pdo->prepare('SELECT full_name FROM users WHERE id = ?');
        $teacherNameStmt->execute([$assignment['teacher_id']]);
        $teacherName = $teacherNameStmt->fetchColumn() ?: 'A teacher';
        $detail = $teacherName . ' · ' . $assignment['subject_name'] . ' · ' . $assignment['grade_level'] . ' - ' . $assignment['section_name'] . ' · Term ' . $term;
        $href = url('/headteacher/review.php?sst_id=' . $sstId . '&term=' . $term);
        foreach ($supervisors as $supervisor) {
            notify((int) $supervisor['id'], 'edit_request', $sstId, (int) $assignment['section_id'], $term, $detail, $href);
        }
    }

    flash_set('success', 'Edit request sent to the Head Teacher for approval.');
}

redirect("/teacher/class_record.php?sst_id=$sstId&term=$term");
