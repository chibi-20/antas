<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => config()['base_path'] . '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('antas_session');
    session_start();
}

function url(string $path = '/'): string
{
    return rtrim(config()['base_path'], '/') . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function forbidden(string $message = 'You do not have access to this page.'): never
{
    http_response_code(403);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>403</title></head><body style="font-family: sans-serif; padding: 2rem;">';
    echo '<h1>403 — Forbidden</h1><p>' . htmlspecialchars($message) . '</p>';
    echo '<p><a href="' . htmlspecialchars(url('/')) . '">Return to dashboard</a></p>';
    echo '</body></html>';
    exit;
}

function current_user(): ?array
{
    start_session();
    return $_SESSION['user'] ?? null;
}

function attempt_login(string $username, string $password): bool
{
    $stmt = db()->prepare('SELECT id, full_name, username, password_hash, role FROM users WHERE username = ? AND is_active = 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    start_session();
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'full_name' => $user['full_name'],
        'username' => $user['username'],
        'role' => $user['role'],
    ];
    return true;
}

function do_logout(): void
{
    start_session();
    $_SESSION = [];
    session_destroy();
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect('/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
    }
    return $user;
}

/** @param string[] $roles */
function require_role(array $roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        forbidden('This page is restricted to: ' . implode(', ', $roles) . '.');
    }
    return $user;
}

/**
 * Adviser guard: being an adviser is a capability (sections.adviser_id), not a separate
 * role — any subject_teacher can be an adviser for zero or more sections. Returns the
 * section row (with grade_level resolved from grade_levels for callers that display it).
 */
function require_own_section(int $sectionId): array
{
    $user = require_role(['subject_teacher', 'admin']);
    $stmt = db()->prepare('SELECT sec.*, gl.name AS grade_level FROM sections sec
        JOIN grade_levels gl ON gl.id = sec.grade_level_id WHERE sec.id = ?');
    $stmt->execute([$sectionId]);
    $section = $stmt->fetch();
    if (!$section) {
        forbidden('Section not found.');
    }
    if ($user['role'] !== 'admin' && (int) $section['adviser_id'] !== $user['id']) {
        forbidden('You are not the adviser of this section.');
    }
    return $section;
}

/**
 * Head Teacher guard: supervising a subject is a capability (head_teacher_assignments),
 * not a separate role — any subject_teacher can supervise zero or more subjects.
 */
function require_supervised_subject(int $subjectId, int $schoolYearId): array
{
    $user = require_role(['subject_teacher', 'admin']);
    if ($user['role'] === 'admin') {
        $stmt = db()->prepare('SELECT * FROM subjects WHERE id = ?');
        $stmt->execute([$subjectId]);
        $subject = $stmt->fetch();
        if (!$subject) {
            forbidden('Subject not found.');
        }
        return $subject;
    }
    $stmt = db()->prepare('SELECT s.* FROM head_teacher_assignments hta
        JOIN subjects s ON s.id = hta.subject_id
        WHERE hta.head_teacher_id = ? AND hta.subject_id = ? AND hta.school_year_id = ? AND hta.is_active = 1');
    $stmt->execute([$user['id'], $subjectId, $schoolYearId]);
    $subject = $stmt->fetch();
    if (!$subject) {
        forbidden('You do not supervise this subject.');
    }
    return $subject;
}

/** Subject Teacher guard: the section_subject_teachers row must belong to the current user. */
function require_own_assignment(int $sectionSubjectTeacherId): array
{
    $user = require_role(['subject_teacher', 'admin']);
    $stmt = db()->prepare('SELECT sst.*, sec.section_name, gl.name AS grade_level, sub.subject_name
        FROM section_subject_teachers sst
        JOIN sections sec ON sec.id = sst.section_id
        JOIN grade_levels gl ON gl.id = sec.grade_level_id
        JOIN subjects sub ON sub.id = sst.subject_id
        WHERE sst.id = ?');
    $stmt->execute([$sectionSubjectTeacherId]);
    $assignment = $stmt->fetch();
    if (!$assignment) {
        forbidden('Assignment not found.');
    }
    if ($user['role'] !== 'admin' && (int) $assignment['teacher_id'] !== $user['id']) {
        forbidden('This is not your class.');
    }
    return $assignment;
}

/** Blocks score edits once a term has been submitted for review or published. */
function assert_editable(int $sectionSubjectTeacherId, int $term): void
{
    $stmt = db()->prepare('SELECT status FROM submission_status WHERE section_subject_teacher_id = ? AND term = ?');
    $stmt->execute([$sectionSubjectTeacherId, $term]);
    $status = $stmt->fetchColumn();
    if (in_array($status, ['submitted_for_review', 'published'], true)) {
        forbidden('This term is locked (status: ' . $status . '). Ask your Head Teacher to return it for revision if changes are needed.');
    }
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function csrf_verify(): void
{
    start_session();
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || $token === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(400);
        die('Invalid or expired form submission (CSRF check failed). Go back and try again.');
    }
}

function flash_set(string $type, string $message): void
{
    start_session();
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** @return array{type: string, message: string}[] */
function flash_take(): array
{
    start_session();
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

function active_school_year(): ?array
{
    $stmt = db()->query('SELECT * FROM school_years WHERE is_active = 1 LIMIT 1');
    $row = $stmt->fetch();
    return $row ?: null;
}
