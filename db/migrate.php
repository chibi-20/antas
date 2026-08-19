<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

function migrate_out(string $msg): void
{
    fwrite(STDOUT, $msg . PHP_EOL);
}

$dbConfig = config()['db'];

// Ensure the database itself exists before connecting to it.
$rootDsn = sprintf('mysql:host=%s;port=%s;charset=%s', $dbConfig['host'], $dbConfig['port'], $dbConfig['charset']);
$root = new PDO($rootDsn, $dbConfig['user'], $dbConfig['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$root->exec(sprintf('CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET %s', $dbConfig['name'], $dbConfig['charset']));

$pdo = db();

$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
    filename VARCHAR(255) PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$applied = $pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);

$files = glob(__DIR__ . '/migrations/*.sql');
sort($files);

foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        migrate_out("skip  $name (already applied)");
        continue;
    }

    $sql = file_get_contents($file);
    // Strip "-- comment" text before splitting on ';' — otherwise a semicolon inside a
    // comment (e.g. "...table; retire it.") gets mistaken for a statement boundary.
    $sql = preg_replace('/--.*$/m', '', $sql);
    // DDL auto-commits in MySQL/MariaDB regardless of transactions, so statements are
    // simply split on ';' and run in order — no BEGIN/COMMIT needed for atomicity here.
    $statements = array_filter(array_map('trim', explode(';', $sql)), fn($s) => $s !== '');

    try {
        foreach ($statements as $stmt) {
            $pdo->exec($stmt);
        }
        $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)')->execute([$name]);
        migrate_out("apply $name");
    } catch (Throwable $e) {
        migrate_out("FAILED $name: " . $e->getMessage());
        exit(1);
    }
}

migrate_out('Migrations up to date.');
