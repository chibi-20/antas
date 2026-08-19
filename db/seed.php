<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

function seed_out(string $msg): void
{
    fwrite(STDOUT, $msg . PHP_EOL);
}

$pdo = db();

// --- Admin user ---
$existingAdmin = $pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetch();
if (!$existingAdmin) {
    $username = 'admin';
    $password = bin2hex(random_bytes(6));
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->prepare('INSERT INTO users (full_name, username, password_hash, role) VALUES (?, ?, ?, ?)')
        ->execute(['System Administrator', $username, $hash, 'admin']);
    seed_out("Created admin user — username: $username  password: $password  (CHANGE THIS AFTER FIRST LOGIN)");
} else {
    seed_out('Admin user already exists, skipping.');
}

// --- Active school year (Philippine SY starts ~June) ---
$existingYear = $pdo->query('SELECT id FROM school_years WHERE is_active = 1 LIMIT 1')->fetch();
if (!$existingYear) {
    $currentYear = (int) date('Y');
    $month = (int) date('n');
    $startYear = $month >= 6 ? $currentYear : $currentYear - 1;
    $label = $startYear . '-' . ($startYear + 1);
    $pdo->prepare('INSERT INTO school_years (year_label, is_active) VALUES (?, 1)')->execute([$label]);
    seed_out("Created active school year: $label");
} else {
    seed_out('Active school year already exists, skipping.');
}

// --- Weight profiles: Key Stage 2 & 3 (Grades 4-10), DepEd Order No. 15 s. 2026 ---
$countProfiles = (int) $pdo->query('SELECT COUNT(*) FROM grade_weight_profiles')->fetchColumn();
if ($countProfiles === 0) {
    $profiles = [
        ['English, Filipino, Math, Science, AP, GMRC/VE (WW20/PT50/EX30)', 20, 50, 30],
        ['EPP/TLE, MAPEH (WW20/PT60/EX20)', 20, 60, 20],
    ];
    $stmt = $pdo->prepare('INSERT INTO grade_weight_profiles (profile_name, written_work_pct, performance_task_pct, examination_pct) VALUES (?, ?, ?, ?)');
    foreach ($profiles as $p) {
        $stmt->execute($p);
    }
    seed_out('Seeded Key Stage 2 & 3 (Grades 4-10) grade weight profiles.');
} else {
    seed_out('Grade weight profiles already exist, skipping.');
}

// --- SY 2026-2027 Adjusted Transmutation Table, DepEd Order No. 15 s. 2026 ---
// Zero-based grading transition: Initial Grade 70.00 -> passing Transmuted Grade of 75.
$countTransmutation = (int) $pdo->query('SELECT COUNT(*) FROM transmutation_table')->fetchColumn();
if ($countTransmutation === 0) {
    $rows = [
        [99.50, 100.00, 100], [98.32, 99.49, 99], [97.14, 98.31, 98], [95.96, 97.13, 97],
        [94.78, 95.95, 96], [93.60, 94.77, 95], [92.42, 93.59, 94], [91.24, 92.41, 93],
        [90.06, 91.23, 92], [88.88, 90.05, 91], [87.70, 88.87, 90], [86.52, 87.69, 89],
        [85.34, 86.51, 88], [84.16, 85.33, 87], [82.98, 84.15, 86], [81.80, 82.97, 85],
        [80.62, 81.79, 84], [79.44, 80.61, 83], [78.26, 79.43, 82], [77.08, 78.25, 81],
        [75.90, 77.07, 80], [74.72, 75.89, 79], [73.54, 74.71, 78], [72.36, 73.53, 77],
        [71.18, 72.35, 76], [70.00, 71.17, 75], [65.34, 69.99, 74], [60.67, 65.33, 73],
        [56.01, 60.66, 72], [51.34, 56.00, 71], [46.67, 51.33, 70], [42.01, 46.66, 69],
        [37.34, 42.00, 68], [32.68, 37.33, 67], [28.01, 32.67, 66], [23.35, 28.00, 65],
        [18.68, 23.34, 64], [14.01, 18.67, 63], [9.35, 14.00, 62], [4.68, 9.34, 61],
        [0.00, 4.67, 60],
    ];
    $stmt = $pdo->prepare('INSERT INTO transmutation_table (min_initial, max_initial, transmuted) VALUES (?, ?, ?)');
    foreach ($rows as $row) {
        $stmt->execute($row);
    }
    seed_out('Seeded SY 2026-2027 Adjusted Transmutation Table.');
} else {
    seed_out('Transmutation table already has rows, skipping.');
}

// --- Grade Levels reference table ---
$countGradeLevels = (int) $pdo->query('SELECT COUNT(*) FROM grade_levels')->fetchColumn();
if ($countGradeLevels === 0) {
    $levels = ['Kindergarten' => 0, 'Grade 1' => 1, 'Grade 2' => 2, 'Grade 3' => 3, 'Grade 4' => 4,
        'Grade 5' => 5, 'Grade 6' => 6, 'Grade 7' => 7, 'Grade 8' => 8, 'Grade 9' => 9,
        'Grade 10' => 10, 'Grade 11' => 11, 'Grade 12' => 12];
    $stmt = $pdo->prepare('INSERT INTO grade_levels (name, sort_order) VALUES (?, ?)');
    foreach ($levels as $name => $order) {
        $stmt->execute([$name, $order]);
    }
    seed_out('Seeded grade levels (Kindergarten - Grade 12).');
} else {
    seed_out('Grade levels already exist, skipping.');
}

seed_out('Seed complete.');
