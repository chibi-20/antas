<?php
// Copy this file to config.php and fill in real values.
// config.php is gitignored — never commit real credentials.

return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'name' => 'antas_grades',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    // Random long string used to key session cookies. Generate a fresh one per environment,
    // e.g. via: php -r "echo bin2hex(random_bytes(32));"
    'session_secret' => 'change-me-generate-a-random-64-char-hex-string',
    // Base path the app is served under, e.g. '/antas' when running at http://localhost/antas/
    'base_path' => '/antas',
];
