<?php
require_once __DIR__ . '/config/db.php';

echo "<h2>Starting Database Migration (Phase 2)</h2>";

try {
    // 1. Add is_blocked to users table
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_blocked'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_blocked TINYINT(1) DEFAULT 0");
        echo "✓ Column 'is_blocked' added to 'users' table.<br>";
    } else {
        echo "• Column 'is_blocked' already exists in 'users' table.<br>";
    }

    // 1b. Add phone to users table
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'phone'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(20) DEFAULT NULL");
        echo "✓ Column 'phone' added to 'users' table.<br>";
    } else {
        echo "• Column 'phone' already exists in 'users' table.<br>";
    }

    // 2. Add verifier_cut, admin_cut, portal_cut to journals table
    $cuts = ['verifier_cut', 'admin_cut', 'portal_cut'];
    foreach ($cuts as $cut) {
        $stmt = $pdo->query("SHOW COLUMNS FROM journals LIKE '$cut'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE journals ADD COLUMN $cut DECIMAL(10,2) DEFAULT NULL");
            echo "✓ Column '$cut' added to 'journals' table.<br>";
        } else {
            echo "• Column '$cut' already exists in 'journals' table.<br>";
        }
    }

    // 3. Add payment_method to payments table
    $stmt = $pdo->query("SHOW COLUMNS FROM payments LIKE 'payment_method'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE payments ADD COLUMN payment_method VARCHAR(50) DEFAULT 'upi'");
        echo "✓ Column 'payment_method' added to 'payments' table.<br>";
    } else {
        echo "• Column 'payment_method' already exists in 'payments' table.<br>";
    }

    // 4. Create system_settings table
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(50) PRIMARY KEY,
        setting_value VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✓ Table 'system_settings' verified/created.<br>";

    // Seed default settings
    $settings = [
        'verifier_cut_pct'     => '50',
        'admin_cut_pct'        => '20',
        'portal_cut_pct'       => '30',
        'current_volume'       => '20',
        'current_issue'        => '1',
        'current_edition_date' => '2026-03-01',
        'min_processing_fee'   => '1000',
        'editor_name'          => 'Prof. (Dr.) Biju Lona K.',
        'editor_signature'     => '',
    ];
    foreach ($settings as $key => $val) {
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = setting_value");
        $stmt->execute([$key, $val]);
    }
    echo "✓ Current edition settings seeded (current_volume, current_issue, current_edition_date).<br>";
    echo "✓ Default cutting settings seeded successfully.<br>";

    // 5. Create wallet_transactions table
    $pdo->exec("CREATE TABLE IF NOT EXISTS wallet_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        amount DECIMAL(10,2) NOT NULL,
        transaction_type ENUM('credit', 'debit') NOT NULL,
        description VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✓ Table 'wallet_transactions' verified/created.<br>";

    echo "<h3>Migration completed successfully!</h3>";
    echo "<p><a href='admin/dashboard.php'>Go to Admin Dashboard</a></p>";

} catch (PDOException $e) {
    echo "<h3 style='color:red;'>Migration failed: " . $e->getMessage() . "</h3>";
}
?>
