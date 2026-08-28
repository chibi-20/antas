<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/teacher/index.php');
}

csrf_verify();

$sstId = (int) ($_POST['sst_id'] ?? 0);
$term = (int) ($_POST['term'] ?? 0);

$assignment = require_own_assignment($sstId);
assert_covers_term($assignment, $term);

$pdo = db();
$stmt = $pdo->prepare('SELECT status FROM submission_status WHERE section_subject_teacher_id = ? AND term = ?');
$stmt->execute([$sstId, $term]);
$status = $stmt->fetchColumn();

if (in_array($status, ['not_started', 'in_progress', 'returned_for_revision'], true)) {
    $pdo->prepare("UPDATE submission_status SET status = 'submitted_for_review', submitted_at = NOW(), revision_comment = NULL WHERE section_subject_teacher_id = ? AND term = ?")
        ->execute([$sstId, $term]);
    flash_set('success', 'Submitted for Head Teacher review.');
} else {
    flash_set('error', 'This term cannot be submitted from its current status.');
}

redirect("/teacher/class_record.php?sst_id=$sstId&term=$term");
