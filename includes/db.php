<?php
declare(strict_types=1);

function config(): array
{
    static $config = null;
    if ($config === null) {
        $path = __DIR__ . '/../config/config.php';
        if (!file_exists($path)) {
            throw new RuntimeException('Missing config/config.php — copy config/config.example.php and fill in your database credentials.');
        }
        $config = require $path;
    }
    return $config;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config()['db'];
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $c['host'], $c['port'], $c['name'], $c['charset']);
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}
