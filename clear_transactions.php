<?php
/**
 * Script to clear all submission and transaction data from the server database,
 * while keeping users intact.
 */
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/config/db.php';

echo "Connecting and starting cleanup...\n";

try {
    // 1. Database Truncation
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $pdo->exec("TRUNCATE TABLE payments;");
    $pdo->exec("TRUNCATE TABLE reviews;");
    $pdo->exec("TRUNCATE TABLE reviewer_assignments;");
    $pdo->exec("TRUNCATE TABLE journal_versions;");
    $pdo->exec("TRUNCATE TABLE journals;");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
    
    echo "✓ Database tables truncated successfully (payments, reviews, reviewer_assignments, journal_versions, journals).\n";
} catch (PDOException $e) {
    die("Error truncating tables: " . $e->getMessage() . "\n");
}

// 2. Clear Uploaded Files
echo "Cleaning uploads directories...\n";

// Clear manuscript files in uploads/
$uploads_dir = __DIR__ . '/uploads';
if (is_dir($uploads_dir)) {
    $files = glob($uploads_dir . '/*');
    foreach ($files as $file) {
        if (is_file($file) && (strpos(basename($file), 'manuscript_') === 0 || strpos(basename($file), 'word_') === 0)) {
            unlink($file);
            echo "Deleted file: " . basename($file) . "\n";
        }
    }
}

// Clear payment proofs in uploads/payments/
$payments_dir = __DIR__ . '/uploads/payments';
if (is_dir($payments_dir)) {
    $files = glob($payments_dir . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
            echo "Deleted payment proof: " . basename($file) . "\n";
        }
    }
}

echo "✓ Cleanup complete!\n";
?>
