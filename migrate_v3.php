<?php
require_once __DIR__ . '/config/db.php';

echo "<h2>Starting Database Migration (Phase 3 - GST and Invoicing)</h2>";

try {
    // 1. Add GST columns to journals table
    $columns = [
        'gst_type' => "VARCHAR(10) DEFAULT 'exclude'",
        'gst_amount' => "DECIMAL(10,2) DEFAULT 0.00",
        'base_amount' => "DECIMAL(10,2) DEFAULT 0.00",
        'bill_number' => "VARCHAR(100) DEFAULT NULL"
    ];

    foreach ($columns as $col => $definition) {
        $stmt = $pdo->query("SHOW COLUMNS FROM journals LIKE '$col'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE journals ADD COLUMN $col $definition");
            echo "✓ Column '$col' added to 'journals' table.<br>";
        } else {
            echo "• Column '$col' already exists in 'journals' table.<br>";
        }
    }

    // 2. Add default bill format setting
    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = setting_value");
    $stmt->execute(['bill_format', 'SAN/INV/ONLINE/26-27/{SEQ}']);
    echo "✓ Default bill format seeded.<br>";

    echo "<h3>Migration Phase 3 completed successfully!</h3>";
} catch (PDOException $e) {
    echo "<h3 style='color:red;'>Migration failed: " . $e->getMessage() . "</h3>";
}
