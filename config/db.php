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
    
    // Auto-migration: Check and add author_photo column to journals table
    try {
        $pdo->query("SELECT author_photo FROM journals LIMIT 1");
    } catch (PDOException $e) {
        try {
            $pdo->exec("ALTER TABLE journals ADD COLUMN author_photo VARCHAR(255) DEFAULT NULL");
        } catch (PDOException $ex) {}
    }

    // Auto-migration: Check and add start_page and end_page columns to journals table
    try {
        $pdo->query("SELECT start_page FROM journals LIMIT 1");
    } catch (PDOException $e) {
        try {
            $pdo->exec("ALTER TABLE journals ADD COLUMN start_page INT DEFAULT NULL, ADD COLUMN end_page INT DEFAULT NULL");
        } catch (PDOException $ex) {}
    }

    // Auto-migration: Check and create journal_authors table
    try {
        $pdo->query("SELECT 1 FROM journal_authors LIMIT 1");
    } catch (PDOException $e) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS journal_authors (
                id INT AUTO_INCREMENT PRIMARY KEY,
                journal_id INT NOT NULL,
                name VARCHAR(255) NOT NULL,
                photo_path VARCHAR(255) DEFAULT NULL,
                order_num INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (journal_id) REFERENCES journals(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (PDOException $ex) {}
    }
} catch (PDOException $e) {
    // In production, log error and show friendly message
    die("Database connection failed. Please ensure MySQL is running and the database is imported. Error: " . $e->getMessage());
}
?>