<?php

declare(strict_types=1);

define('BASE_DIR', dirname(__DIR__));

require BASE_DIR . '/vendor/autoload.php';

use App\Services\ConfigService;

$host = (string) ConfigService::get('database.host');
$name = (string) ConfigService::get('database.name');
$user = (string) ConfigService::get('database.user');
$pass = (string) ConfigService::get('database.pass');
$charset = (string) ConfigService::get('database.charset', 'utf8mb4');

$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $host, $name, $charset),
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec("SET time_zone = '+00:00'");

$dir = BASE_DIR . '/docs/db/migrations';
$files = glob($dir . '/*.sql') ?: [];
sort($files);

foreach ($files as $file) {
    $sql = file_get_contents($file);
    if ($sql === false || trim($sql) === '') {
        continue;
    }
    echo 'Applying ' . basename($file) . PHP_EOL;
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $statement) {
        $lines = array_filter(
            explode("\n", $statement),
            static fn(string $line): bool => !str_starts_with(trim($line), '--')
        );
        $statement = trim(implode("\n", $lines));
        if ($statement === '') {
            continue;
        }
        try {
            $pdo->exec($statement);
        } catch (PDOException $e) {
            $message = $e->getMessage();
            if (
                str_contains($message, 'Duplicate')
                || str_contains($message, 'already exists')
                || str_contains($message, 'Duplicate column')
            ) {
                echo '  skipped existing change' . PHP_EOL;
                continue;
            }
            throw $e;
        }
    }
}

echo 'Migrations complete.' . PHP_EOL;
