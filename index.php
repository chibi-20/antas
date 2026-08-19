<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$user = current_user();
if (!$user) {
    redirect('/login.php');
}

// Every non-admin account is a Subject Teacher first — Adviser/Head Teacher are
// capabilities layered on top (see nav_items() in includes/layout.php), reached via
// nav links rather than a separate landing page.
redirect($user['role'] === 'admin' ? '/admin/dashboard.php' : '/teacher/index.php');
