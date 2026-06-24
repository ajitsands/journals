<?php
require_once __DIR__ . '/config/db.php';

echo "<h2>Starting Database Migration (Phase 4 - Credit Notes)</h2>";

try {
    // 1. Create credit_notes table
    $pdo->exec("CREATE TABLE IF NOT EXISTS credit_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        journal_id INT NOT NULL,
        bill_number VARCHAR(100) NOT NULL,
        credit_note_number VARCHAR(100) NOT NULL UNIQUE,
        amount DECIMAL(10,2) NOT NULL,
        base_amount DECIMAL(10,2) NOT NULL,
        gst_amount DECIMAL(10,2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (journal_id) REFERENCES journals(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✓ Table 'credit_notes' verified/created.<br>";

    // 2. Add default credit note format setting
    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) 
                           VALUES ('credit_note_format', 'SAN/CN/ONLINE/{FY}/{SEQ}') 
                           ON DUPLICATE KEY UPDATE setting_value = setting_value");
    $stmt->execute();
    echo "✓ Default Credit Note format seeded.<br>";

    echo "<h3>Migration Phase 4 completed successfully!</h3>";
    echo "<p><a href='admin/dashboard.php'>Go to Admin Dashboard</a></p>";
} catch (PDOException $e) {
    echo "<h3 style='color:red;'>Migration failed: " . $e->getMessage() . "</h3>";
}
?>
