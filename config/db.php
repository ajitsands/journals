<?php
// Database configuration (dynamically handles local vs server environments)
$is_local = false;
if (DIRECTORY_SEPARATOR === '\\') {
    $is_local = true;
} else {
    $http_host = $_SERVER['HTTP_HOST'] ?? '';
    if (empty($http_host) || 
        in_array($http_host, ['localhost', '127.0.0.1', '[::1]']) || 
        strpos($http_host, 'localhost:') === 0 || 
        strpos($http_host, '127.0.0.1:') === 0) {
        $is_local = true;
    }
}

if ($is_local) {
    // Local configuration
    define('DB_HOST', '127.0.0.1');
    define('DB_PORT', '3306');
    define('DB_NAME', 'journals_ph_db');
    define('DB_USER', 'root');
    define('DB_PASS', 'S@nds1@b');
} else {
    // Server configuration
    define('DB_HOST', 'localhost');
    define('DB_PORT', '3306');
    define('DB_NAME', 'rjpes_journals_db');
    define('DB_USER', 'rjpes_Journals_user');
    define('DB_PASS', 'S@nds1@b');
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    // In production, log error and show friendly message
    die("Database connection failed. Please ensure MySQL is running and the database is imported. Error: " . $e->getMessage());
}
?>