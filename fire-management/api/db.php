<?php
// api/db.php - database connection helper
// Edit the defaults below to match your XAMPP/MariaDB credentials if needed.

function get_pdo() {
    static $pdo = false;
    if ($pdo !== false) return $pdo;

    $dbHost = getenv('DB_HOST') ?: '127.0.0.1';
    $dbPort = getenv('DB_PORT') ?: '3306';
    $dbName = getenv('DB_NAME') ?: 'chay_rung';
    $dbUser = getenv('DB_USER') ?: 'root';
    $dbPass = getenv('DB_PASSWORD') ?: '';

    $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
    try {
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    } catch (Exception $e) {
        // Could not connect — return null to signal fallback to file-backed storage
        error_log('DB connect failed: ' . $e->getMessage());
        $pdo = null;
        return null;
    }
}
