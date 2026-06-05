<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$message = "";
$message_type = "";

// 0. Handle Update Current Edition Settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_edition'])) {
    $ed_vol  = sanitize($_POST['edition_volume']);
    $ed_iss  = sanitize($_POST['edition_issue']);
    $ed_date = sanitize($_POST['edition_date'] ?? '');

    if (!empty($ed_vol) && !empty($ed_iss) && !empty($ed_date)) {
        try {
            $upd = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $upd->execute(['current_volume', $ed_vol]);
            $upd->execute(['current_issue', $ed_iss]);
            $upd->execute(['current_edition_date', $ed_date]);
            $message = "✅ Current edition updated: VOLUME $ed_vol \u2022 ISSUE $ed_iss \u2022 " . strtoupper(date('F Y', strtotime($ed_date))) . ". Home page now shows this edition.";
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Failed to update edition: " . $e->getMessage();
            $message_type = "danger";
        }
    } else {
        $message = "All edition fields are required.";
        $message_type = "warning";
    }
}

// Handle Update Editor settings POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_editor_settings'])) {
    $editor_name = sanitize($_POST['editor_name'] ?? '');
    
    if (!empty($editor_name)) {
        try {
            $upd = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $upd->execute(['editor_name', $editor_name]);
            
            // Handle file upload
            if (isset($_FILES['editor_signature']) && $_FILES['editor_signature']['error'] === UPLOAD_ERR_OK) {
                $file_tmp = $_FILES['editor_signature']['tmp_name'];
                
                // Verify image
                $info = @getimagesize($file_tmp);
                if ($info) {
                    $src_img = null;
                    if ($info[2] === IMAGETYPE_JPEG) {
                        $src_img = @imagecreatefromjpeg($file_tmp);
                    } elseif ($info[2] === IMAGETYPE_PNG) {
                        $src_img = @imagecreatefrompng($file_tmp);
                    }
                    
                    if ($src_img) {
                        $w = imagesx($src_img);
                        $h = imagesy($src_img);
                        
                        // Create white canvas
                        $out_img = imagecreatetruecolor($w, $h);
                        $white = imagecolorallocate($out_img, 255, 255, 255);
                        imagefill($out_img, 0, 0, $white);
                        
                        imagecopy($out_img, $src_img, 0, 0, 0, 0, $w, $h);
                        
                        // Generate signature directory
                        $sig_dir = __DIR__ . '/../uploads/signatures';
                        if (!file_exists($sig_dir)) {
                            @mkdir($sig_dir, 0777, true);
                        }
                        
                        // Delete old signature
                        $old_sig = rjpes_get_setting('editor_signature', '');
                        if (!empty($old_sig)) {
                            $old_abs = __DIR__ . '/../' . ltrim(str_replace(['/', '\\'], '/', $old_sig), '/');
                            if (file_exists($old_abs)) {
                                @unlink($old_abs);
                            }
                        }
                        
                        $new_filename = 'sig_' . time() . '.jpg';
                        $new_rel_path = 'uploads/signatures/' . $new_filename;
                        $new_abs_path = $sig_dir . '/' . $new_filename;
                        
                        // Save JPEG
                        if (@imagejpeg($out_img, $new_abs_path, 90)) {
                            $upd->execute(['editor_signature', $new_rel_path]);
                        } else {
                            throw new Exception("Failed to write signature image to disk.");
                        }
                        
                        imagedestroy($src_img);
                        imagedestroy($out_img);
                    } else {
                        throw new Exception("Unsupported image format. Please upload JPEG or PNG.");
                    }
                } else {
                    throw new Exception("Uploaded file is not a valid image.");
                }
            }
            
            $message = "✅ Editor-in-Chief details updated successfully! Dynamic signature will now be rendered on all official PDFs.";
            $message_type = "success";
        } catch (Exception $e) {
            $message = "Failed to update editor settings: " . $e->getMessage();
            $message_type = "danger";
        }
    } else {
        $message = "Editor Name is required.";
        $message_type = "warning";
    }
}

// 1. Handle Update Settings POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    $v_pct = floatval($_POST['verifier_pct']);
    $a_pct = floatval($_POST['admin_pct']);
    $p_pct = floatval($_POST['portal_pct']);
    $min_fee = floatval($_POST['min_processing_fee'] ?? 1000);
    $min_duration = sanitize($_POST['min_process_duration'] ?? '15 Working Days');
    $gst_pct = floatval($_POST['gst_percentage'] ?? 18);
    $bill_format = sanitize($_POST['bill_format'] ?? 'SAN/INV/ONLINE/{FY}/{SEQ}');
    $gst_mode = in_array($_POST['gst_mode'] ?? 'exclude', ['include', 'exclude']) ? $_POST['gst_mode'] : 'exclude';
    
    if ($v_pct + $a_pct + $p_pct !== 100.0) {
        $message = "Error: Cuttings percentages must sum to exactly 100% (currently: " . ($v_pct + $a_pct + $p_pct) . "%).";
        $message_type = "danger";
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
            $stmt->execute([$v_pct, 'verifier_cut_pct']);
            $stmt->execute([$a_pct, 'admin_cut_pct']);
            $stmt->execute([$p_pct, 'portal_cut_pct']);
            
            // Update or insert min_processing_fee
            $stmt_fee = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt_fee->execute(['min_processing_fee', $min_fee]);
            
            // Update or insert min_process_duration
            $stmt_dur = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt_dur->execute(['min_process_duration', $min_duration]);

            // Update or insert gst_percentage
            $stmt_gst = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt_gst->execute(['gst_percentage', $gst_pct]);
            
            // Update or insert gst_mode (include/exclude)
            $stmt_gst_mode = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt_gst_mode->execute(['gst_mode', $gst_mode]);
            
            // Update or insert bill_format
            $stmt_bill = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt_bill->execute(['bill_format', $bill_format]);
            
            $message = "Global settings updated successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Failed to update settings: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// 2. Handle Payout POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payout'])) {
    $user_id_raw = $_POST['user_id'] ?? '';
    $is_portal = ($user_id_raw === 'portal');
    $user_id = $is_portal ? null : intval($user_id_raw);
    $amount = floatval($_POST['amount']);
    $ref = sanitize($_POST['reference'] ?? 'Payout');
    $payment_type = sanitize($_POST['payment_type'] ?? '');
    $transaction_date = sanitize($_POST['transaction_date'] ?? '');
    
    if (($is_portal || ($user_id !== null && $user_id > 0)) && $amount > 0 && !empty($payment_type) && !empty($transaction_date)) {
        try {
            // Check current balance to avoid over-payouts
            if ($is_portal) {
                $bal_stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0.00) as balance FROM wallet_transactions WHERE user_id IS NULL");
                $current_bal = floatval($bal_stmt->fetch()['balance']);
            } else {
                $bal_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0.00) as balance FROM wallet_transactions WHERE user_id = ?");
                $bal_stmt->execute([$user_id]);
                $current_bal = floatval($bal_stmt->fetch()['balance']);
            }
            
            if ($amount > $current_bal) {
                if ($is_portal) {
                    $message = "Error: Payout amount (₹" . number_format($amount, 2) . ") exceeds the portal's revenue balance (₹" . number_format($current_bal, 2) . ").";
                } else {
                    $message = "Error: Payout amount (₹" . number_format($amount, 2) . ") exceeds the user's wallet balance (₹" . number_format($current_bal, 2) . ").";
                }
                $message_type = "danger";
            } else {
                $stmt = $pdo->prepare("INSERT INTO wallet_transactions (user_id, amount, transaction_type, description, payment_type, transaction_date) VALUES (?, ?, 'debit', ?, ?, ?)");
                $stmt->execute([$user_id, -$amount, "Payout: " . $ref, $payment_type, $transaction_date]);
                $message = "Payout of ₹" . number_format($amount, 2) . " recorded successfully!";
                $message_type = "success";
            }
        } catch (PDOException $e) {
            $message = "Payout transaction failed: " . $e->getMessage();
            $message_type = "danger";
        }
    } else {
        $message = "Error: All fields (Amount, Reference, Payment Type, and Date) are required to complete the payout transaction.";
        $message_type = "danger";
    }
}

// Fetch default settings
try {
    $set_stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
    $settings = [];
    while ($row = $set_stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    $v_pct  = floatval($settings['verifier_cut_pct'] ?? 50);
    $a_pct  = floatval($settings['admin_cut_pct']    ?? 20);
    $p_pct  = floatval($settings['portal_cut_pct']   ?? 30);
    $min_fee = floatval($settings['min_processing_fee'] ?? 1000);
    $min_duration = $settings['min_process_duration'] ?? '15 Working Days';
    $gst_pct_val  = floatval($settings['gst_percentage'] ?? 18);
    $gst_mode_val = $settings['gst_mode'] ?? 'exclude';
    $bill_format_val = $settings['bill_format'] ?? 'SAN/INV/ONLINE/{FY}/{SEQ}';
    // Current edition
    $cur_vol  = $settings['current_volume']       ?? '20';
    $cur_iss  = $settings['current_issue']        ?? '1';
    $cur_date = $settings['current_edition_date'] ?? date('Y-m-d');
} catch (PDOException $e) {
    $v_pct = 50; $a_pct = 20; $p_pct = 30; $min_fee = 1000;
    $min_duration = '15 Working Days';
    $gst_pct_val = 18;
    $gst_mode_val = 'exclude';
    $bill_format_val = 'SAN/INV/ONLINE/{FY}/{SEQ}';
    $cur_vol = '20'; $cur_iss = '1'; $cur_date = date('Y-m-d');
}

// Fetch balances of Portal and Users
try {
    // Portal Balance
    $p_bal_stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0.00) as balance FROM wallet_transactions WHERE user_id IS NULL");
    $portal_balance = floatval($p_bal_stmt->fetch()['balance']);
    
    // User Balances
    $u_bal_stmt = $pdo->query("SELECT u.id, u.fullname, u.username, u.role, 
                              COALESCE((SELECT SUM(wt.amount) FROM wallet_transactions wt WHERE wt.user_id = u.id), 0.00) as balance 
                              FROM users u 
                              WHERE u.role IN ('reviewer', 'admin') 
                              ORDER BY balance DESC, u.fullname ASC");
    $wallets = $u_bal_stmt->fetchAll();
    
    // Calculate total verifiers balance and total admins balance
    $total_verifier_bal = 0.00;
    $total_admin_bal = 0.00;
    foreach ($wallets as $w) {
        if ($w['role'] === 'reviewer') {
            $total_verifier_bal += floatval($w['balance']);
        } else {
            $total_admin_bal += floatval($w['balance']);
        }
    }
} catch (PDOException $e) {
    die("Database query failed: " . $e->getMessage());
}

$page_title = "Manage Wallets";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="dashboard-container">
    <main class="main-content" style="width: 100%;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 15px;">
            <div>
                <h2 style="font-family: var(--font-heading); color: var(--primary-color);">Manage Wallets &amp; Ledger</h2>
                <p style="color: var(--text-muted); font-size: 0.9rem;">Track verifier payouts, portal revenue splits, and system cutting configuration</p>
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <button onclick="openEditionModal()" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 8px; font-weight: 600; padding: 10px 20px; background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%); color: white;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    Set Current Edition
                </button>
                <button onclick="openRulesModal()" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 8px; font-weight: 600; padding: 10px 20px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle;"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06-.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06-.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                    Global Financial Settings
                </button>
                <button onclick="openEditorModal()" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 8px; font-weight: 600; padding: 10px 20px; background: linear-gradient(135deg, #0b2240 0%, #16365f 100%); color: white; border: none;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    Editor-in-Chief Settings
                </button>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <div><?php echo $message; ?></div>
            </div>
        <?php endif; ?>

        <!-- Summary Cards Grid -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
            <!-- Current Edition card -->
            <div class="card" style="margin-bottom: 0; padding: 1.5rem; border-top: 4px solid #2563eb; background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);">
                <span style="font-size: 0.75rem; text-transform: uppercase; color: #1e40af; font-weight: 700; display: block; letter-spacing: 0.5px; margin-bottom: 6px;">📖 Current Edition (Home Page)</span>
                <span style="font-size: 1.05rem; font-weight: 800; color: #1e3a5f; display: block; line-height: 1.4;">
                    VOL. <?php echo sanitize($cur_vol); ?> &bull; ISSUE <?php echo sanitize($cur_iss); ?>
                </span>
                <span style="font-size: 0.82rem; color: #3b82f6; font-weight: 600; display: block; margin-top: 3px;">
                    <?php echo strtoupper(date('F Y', strtotime($cur_date))); ?>
                </span>
                <button onclick="openEditionModal()" style="margin-top: 10px; background: #1e40af; border: none; color: white; font-size: 0.72rem; font-weight: 600; cursor: pointer; padding: 5px 12px; border-radius: 5px; display: inline-flex; align-items: center; gap: 4px;">
                    ✏️ Edit
                </button>
            </div>

            <!-- Portal wallet card -->
            <div class="card" style="margin-bottom: 0; padding: 1.5rem; border-top: 4px solid var(--accent-color); display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <span style="font-size: 0.8rem; text-transform: uppercase; color: var(--text-muted); font-weight: 600; display: block;">Portal Revenue Balance</span>
                    <span style="font-size: 1.8rem; font-weight: 800; color: var(--primary-color); display: block; margin-top: 5px;">₹<?php echo number_format($portal_balance, 2); ?></span>
                </div>
                <div style="display: flex; gap: 8px;">
                    <button onclick="openLedgerModal('portal', 'Portal Platform Ledger', <?php echo $portal_balance; ?>, true, 'Portal Platform')" class="btn btn-secondary" style="padding: 6px 12px; font-size: 0.8rem; border: 1px solid var(--border-color); color: var(--primary-color);">
                        View Ledger
                    </button>
                    <?php if ($portal_balance > 0): ?>
                        <button onclick="openPayoutModal('portal', 'Portal Platform', <?php echo $portal_balance; ?>)" class="btn btn-primary" style="padding: 6px 12px; font-size: 0.8rem; background-color: var(--success-color); border: none; color: white;">
                            Payout
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Verifier wallets card -->
            <div class="card" style="margin-bottom: 0; padding: 1.5rem; border-top: 4px solid var(--info-color);">
                <span style="font-size: 0.8rem; text-transform: uppercase; color: var(--text-muted); font-weight: 600; display: block;">Total Verifier Balances</span>
                <span style="font-size: 1.8rem; font-weight: 800; color: var(--primary-color); display: block; margin-top: 5px;">₹<?php echo number_format($total_verifier_bal, 2); ?></span>
            </div>

            <!-- Admin wallets card -->
            <div class="card" style="margin-bottom: 0; padding: 1.5rem; border-top: 4px solid var(--success-color);">
                <span style="font-size: 0.8rem; text-transform: uppercase; color: var(--text-muted); font-weight: 600; display: block;">Total Admin Balances</span>
                <span style="font-size: 1.8rem; font-weight: 800; color: var(--primary-color); display: block; margin-top: 5px;">₹<?php echo number_format($total_admin_bal, 2); ?></span>
            </div>
        </div>

        <!-- User Wallets Card (Full Width) -->
        <div class="card" style="padding: 1.5rem; margin-bottom: 0;">
            <h3 style="font-family: var(--font-heading); color: var(--primary-color); margin-bottom: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">User Wallets</h3>
            
            <?php if (empty($wallets)): ?>
                <div style="text-align: center; padding: 3rem 1rem; color: var(--text-muted);">
                    <p style="font-weight: 500;">No verifier or admin accounts found.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table datatable">
                        <thead>
                            <tr>
                                <th>User Account</th>
                                <th>Role</th>
                                <th>Current Balance</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($wallets as $w): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 600; color: var(--primary-color);"><?php echo sanitize($w['fullname']); ?></div>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);">User: <?php echo sanitize($w['username']); ?></div>
                                    </td>
                                    <td>
                                        <span style="font-size: 0.78rem; text-transform: uppercase; font-weight: 500; color: var(--text-muted);">
                                            <?php echo ($w['role'] === 'reviewer') ? 'Verifier' : 'Admin'; ?>
                                        </span>
                                    </td>
                                    <td style="font-weight: bold; color: <?php echo ($w['balance'] > 0) ? '#16a34a' : 'var(--text-color)'; ?>;">
                                        ₹<?php echo number_format($w['balance'], 2); ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 8px;">
                                            <button onclick="openLedgerModal(<?php echo $w['id']; ?>, '<?php echo addslashes(sanitize($w['fullname'])); ?> Wallet Ledger', <?php echo $w['balance']; ?>, false, '<?php echo addslashes(sanitize($w['fullname'])); ?>')" class="btn btn-secondary" style="padding: 4px 8px; font-size: 0.75rem; border: 1px solid var(--border-color); color: var(--text-color);">
                                                Ledger
                                            </button>
                                            <?php if ($w['balance'] > 0): ?>
                                                <button onclick="openPayoutModal(<?php echo $w['id']; ?>, '<?php echo addslashes(sanitize($w['fullname'])); ?>', <?php echo $w['balance']; ?>)" class="btn btn-primary" style="padding: 4px 8px; font-size: 0.75rem; background-color: var(--success-color); border: none; color: white;">
                                                    Payout
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Modal: Ledger History -->
<div id="ledgerModal" class="modal-overlay" style="display: none; align-items: flex-start; padding-top: 40px;">
    <div class="modal-content" style="max-width: 1100px; width: 95%; max-height: 80vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 10px; position: sticky; top: 0; background: white; z-index: 1;">
            <h3 style="font-family: var(--font-heading); color: var(--primary-color); font-size: 1.1rem;" id="ledgerModalTitle">Wallet Transaction Ledger</h3>
            <button onclick="closeLedgerModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>
        <div id="ledgerModalContent" style="padding: 4px 0;"></div>
    </div>
</div>

<!-- Modal: Record Payout -->
<div id="payoutModal" class="modal-overlay" style="display: none;">
    <div class="modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">
            <h3 style="font-family: var(--font-heading); color: var(--primary-color); font-size: 1.2rem;">Record Wallet Payout</h3>
            <button onclick="closePayoutModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>
        
        <form action="wallets.php" method="POST">
            <input type="hidden" name="payout" value="1">
            <input type="hidden" name="user_id" id="payoutUserId">
            
            <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1.5rem;">
                Record payout/settlement transaction for <strong id="payoutUserName"></strong>. Settle their earnings and record a payout debit.
            </p>
            
            <div class="form-group">
                <label for="payout_amount">Payout Amount (INR / ₹) &mdash; Max: ₹<span id="payoutMaxSpan">0.00</span></label>
                <input type="number" name="amount" id="payout_amount" class="form-control" placeholder="e.g. 1000" min="0.01" step="0.01" required>
            </div>

            <div class="form-group">
                <label for="payout_payment_type">Payment Type</label>
                <select name="payment_type" id="payout_payment_type" class="form-control" required>
                    <option value="">Select Payment Type...</option>
                    <option value="Bank Transfer">Bank Transfer</option>
                    <option value="UPI">UPI</option>
                    <option value="Cash">Cash</option>
                    <option value="Cheque">Cheque</option>
                </select>
            </div>

            <div class="form-group">
                <label for="payout_date">Transaction Date</label>
                <input type="date" name="transaction_date" id="payout_date" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label for="payout_ref">Reference Description / Transaction Info</label>
                <input type="text" name="reference" id="payout_ref" class="form-control" placeholder="e.g. Bank Transfer Ref 8203928172" required>
            </div>
            
            <div style="margin-top: 1.5rem; display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" onclick="closePayoutModal()" class="btn btn-secondary" style="border: 1px solid var(--border-color); color: var(--primary-color); padding: 8px 16px;">Cancel</button>
                <button type="submit" class="btn btn-dark" style="padding: 8px 16px; background-color: var(--success-color); border: none; color: white;">Record Payout</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Current Edition Settings -->
<div id="editionModal" class="modal-overlay" style="display: none;">
    <div class="modal-content" style="max-width: 500px; width: 95%;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">
            <div>
                <h3 style="font-family: var(--font-heading); color: var(--primary-color); font-size: 1.25rem; margin-bottom: 4px;">📖 Set Current Edition</h3>
                <p style="font-size: 0.78rem; color: var(--text-muted); margin: 0;">Controls what visitors see on the home page hero banner</p>
            </div>
            <button onclick="closeEditionModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>

        <form action="wallets.php" method="POST">
            <input type="hidden" name="update_edition" value="1">

            <div style="background: linear-gradient(135deg, #1e3a5f 0%, #1e40af 100%); border-radius: 10px; padding: 16px 20px; margin-bottom: 1.5rem; text-align: center;">
                <p style="color: rgba(255,255,255,0.7); font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; margin: 0 0 6px 0;">Home Page Preview</p>
                <p id="editionPreviewText" style="color: white; font-size: 1rem; font-weight: 800; letter-spacing: 1px; margin: 0;">VOLUME 20 &bull; ISSUE 1 &bull; MARCH 2026</p>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 1rem;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="edition_volume" style="font-weight: 600;">Volume Number</label>
                    <input type="text" name="edition_volume" id="edition_volume" class="form-control" placeholder="e.g., 20" value="<?php echo sanitize($cur_vol); ?>" required oninput="updateEditionPreview()">
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="edition_issue" style="font-weight: 600;">Issue Number</label>
                    <input type="text" name="edition_issue" id="edition_issue" class="form-control" placeholder="e.g., 1" value="<?php echo sanitize($cur_iss); ?>" required oninput="updateEditionPreview()">
                </div>
            </div>

            <div class="form-group">
                <label for="edition_date" style="font-weight: 600;">Edition Month &amp; Year</label>
                <input type="date" name="edition_date" id="edition_date" class="form-control" value="<?php echo sanitize($cur_date); ?>" required onchange="updateEditionPreview()">
                <small style="color: var(--text-muted); font-size: 0.72rem;">The month shown on the home page (e.g., select 1st March 2026 to show MARCH 2026)</small>
            </div>

            <div style="margin-top: 1.25rem; display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" onclick="closeEditionModal()" class="btn btn-secondary" style="border: 1px solid var(--border-color); color: var(--primary-color); padding: 8px 16px;">Cancel</button>
                <button type="submit" class="btn btn-dark" style="padding: 8px 20px; background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%); color: white; border: none;">💾 Update Home Page</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Global Financial Settings -->
<div id="rulesModal" class="modal-overlay" style="display: none; align-items: flex-start; padding-top: 30px;">
    <div class="modal-content" style="max-width: 1000px; width: 97%; max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.2rem; border-bottom: 1px solid var(--border-color); padding-bottom: 10px; position: sticky; top: 0; background: white; z-index: 10;">
            <div>
                <h3 style="font-family: var(--font-heading); color: var(--primary-color); font-size: 1.25rem; margin: 0 0 2px 0;">⚙️ Global Financial Settings</h3>
                <p style="font-size: 0.78rem; color: var(--text-muted); margin: 0;">Configure system parameters, GST options, and view payment account details.</p>
            </div>
            <button onclick="closeRulesModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>
        
        <form action="wallets.php" method="POST" onsubmit="return validateSettingsSum()">
            <input type="hidden" name="update_settings" value="1">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0; margin-bottom: 1.5rem;">
                
                <!-- ===== LEFT COLUMN: System Settings ===== -->
                <div style="border-right: 1px solid var(--border-color); padding-right: 24px;">
                    <h4 style="font-family: var(--font-heading); color: var(--primary-light); font-size: 0.92rem; margin-bottom: 1rem; padding-bottom: 6px; border-bottom: 2px solid #2563eb; text-transform: uppercase; letter-spacing: 0.5px;">⚙️ System Configuration</h4>
                    
                    <div class="form-group" style="margin-bottom: 1rem;">
                        <label for="min_processing_fee" style="font-weight: 600; color: var(--primary-color); font-size: 0.82rem;">Minimum Processing Fee (INR / ₹)</label>
                        <input type="number" name="min_processing_fee" id="min_processing_fee" class="form-control" value="<?php echo $min_fee; ?>" min="0" step="1" required style="font-size: 0.88rem; padding: 6px 10px;">
                        <small style="color: var(--text-muted); font-size: 0.68rem;">Default base fee shown to authors.</small>
                    </div>
                    
                    <div class="form-group" style="margin-bottom: 1rem;">
                        <label for="min_process_duration" style="font-weight: 600; color: var(--primary-color); font-size: 0.82rem;">Minimum Process Duration</label>
                        <input type="text" name="min_process_duration" id="min_process_duration" class="form-control" value="<?php echo sanitize($min_duration); ?>" required style="font-size: 0.88rem; padding: 6px 10px;">
                        <small style="color: var(--text-muted); font-size: 0.68rem;">Shown in Call for Papers block on home page.</small>
                    </div>

                    <div class="form-group" style="margin-bottom: 1rem;">
                        <label for="gst_percentage" style="font-weight: 600; color: var(--primary-color); font-size: 0.82rem;">GST Rate Percentage (%)</label>
                        <input type="number" name="gst_percentage" id="gst_percentage" class="form-control" value="<?php echo $gst_pct_val; ?>" min="0" max="100" step="0.1" required style="font-size: 0.88rem; padding: 6px 10px;">
                        <small style="color: var(--text-muted); font-size: 0.68rem;">Configurable GST tax rate. Split equally as CGST + SGST.</small>
                    </div>

                    <!-- GST Mode: Include / Exclude -->
                    <div class="form-group" style="margin-bottom: 1rem;">
                        <label style="font-weight: 600; color: var(--primary-color); font-size: 0.82rem; display: block; margin-bottom: 8px;">GST Calculation Mode (Default)</label>
                        <div style="display: flex; gap: 12px;">
                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; background: <?php echo ($gst_mode_val === 'exclude') ? '#eff6ff' : '#f8fafc'; ?>; border: 1.5px solid <?php echo ($gst_mode_val === 'exclude') ? '#2563eb' : '#e2e8f0'; ?>; border-radius: 8px; padding: 8px 14px; font-size: 0.82rem; font-weight: 600; transition: all 0.2s; flex: 1;" id="gst_exclude_label" onclick="selectGstMode('exclude')">
                                <input type="radio" name="gst_mode" value="exclude" <?php echo ($gst_mode_val === 'exclude') ? 'checked' : ''; ?> style="accent-color: #2563eb; width: 15px; height: 15px;" onchange="selectGstMode('exclude')">
                                <span>
                                    <span style="display: block; font-size: 0.82rem; font-weight: 700;">➕ Exclude GST</span>
                                    <span style="display: block; font-size: 0.68rem; color: #64748b; font-weight: 400;">GST added on top of base fee</span>
                                </span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; background: <?php echo ($gst_mode_val === 'include') ? '#eff6ff' : '#f8fafc'; ?>; border: 1.5px solid <?php echo ($gst_mode_val === 'include') ? '#2563eb' : '#e2e8f0'; ?>; border-radius: 8px; padding: 8px 14px; font-size: 0.82rem; font-weight: 600; transition: all 0.2s; flex: 1;" id="gst_include_label" onclick="selectGstMode('include')">
                                <input type="radio" name="gst_mode" value="include" <?php echo ($gst_mode_val === 'include') ? 'checked' : ''; ?> style="accent-color: #2563eb; width: 15px; height: 15px;" onchange="selectGstMode('include')">
                                <span>
                                    <span style="display: block; font-size: 0.82rem; font-weight: 700;">✅ Include GST</span>
                                    <span style="display: block; font-size: 0.68rem; color: #64748b; font-weight: 400;">GST included within the fee amount</span>
                                </span>
                            </label>
                        </div>
                        <small style="color: var(--text-muted); font-size: 0.68rem; margin-top: 4px; display: block;">Sets the default GST mode shown in the fee configuration modal for each journal.</small>
                    </div>
                    
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="bill_format" style="font-weight: 600; color: var(--primary-color); font-size: 0.82rem;">Invoice / Bill Format Pattern</label>
                        <input type="text" name="bill_format" id="bill_format" class="form-control" value="<?php echo sanitize($bill_format_val); ?>" required style="font-size: 0.88rem; padding: 6px 10px;">
                        <small style="color: var(--text-muted); font-size: 0.68rem;">Use <code>{FY}</code> for Financial Year (e.g. <em>26-27</em>) and <code>{SEQ}</code> for auto sequence counter.</small>
                    </div>
                </div>
                
                <!-- ===== RIGHT COLUMN: Payment Details + Revenue Splits ===== -->
                <div style="padding-left: 24px;">

                    <!-- Payment Account Info -->
                    <h4 style="font-family: var(--font-heading); color: var(--primary-light); font-size: 0.92rem; margin-bottom: 1rem; padding-bottom: 6px; border-bottom: 2px solid #16a34a; text-transform: uppercase; letter-spacing: 0.5px;">🏦 Payment Account Details</h4>

                    <div style="background: linear-gradient(135deg, #0f2044 0%, #1e3a5f 100%); border-radius: 12px; padding: 16px 18px; margin-bottom: 1rem; color: white;">
                        <div style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1px; opacity: 0.7; margin-bottom: 6px;">Company</div>
                        <div style="font-size: 0.88rem; font-weight: 800; line-height: 1.35; margin-bottom: 2px;">SaNDS Lab</div>
                        <div style="font-size: 0.72rem; opacity: 0.8; font-weight: 400; line-height: 1.5;">(SOFTWARE AND NETWORK DEVELOPMENT SOLUTIONS LAB)</div>
                        <div style="font-size: 0.72rem; opacity: 0.75; margin-top: 4px; line-height: 1.5;">XI/866, Chandanam Block, Infopark, Koratty,<br>Thrissur, Kerala &ndash; 680308</div>
                        <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid rgba(255,255,255,0.15);">
                            <span style="font-size: 0.68rem; opacity: 0.7; text-transform: uppercase; letter-spacing: 0.5px;">GST No: </span>
                            <span style="font-size: 0.78rem; font-weight: 700; font-family: monospace; letter-spacing: 0.5px;">32ABQFS7745B1Z1</span>
                        </div>
                    </div>

                    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 14px 16px; margin-bottom: 1rem;">
                        <div style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.8px; color: #15803d; font-weight: 700; margin-bottom: 10px;">🏛️ Bank Account</div>
                        <div style="display: grid; grid-template-columns: auto 1fr; gap: 4px 12px; font-size: 0.78rem;">
                            <span style="color: #64748b; font-weight: 600;">A/No:</span><span style="font-weight: 700; font-family: monospace; color: #1e3a5f;">17540200000152</span>
                            <span style="color: #64748b; font-weight: 600;">A/Name:</span><span style="font-weight: 700; color: #1e3a5f;">SaNDSLab</span>
                            <span style="color: #64748b; font-weight: 600;">Bank:</span><span style="font-weight: 600; color: #1e3a5f;">Federal Bank</span>
                            <span style="color: #64748b; font-weight: 600;">Branch:</span><span style="font-weight: 500; color: #334155;">Koratty Infopark Extension</span>
                            <span style="color: #64748b; font-weight: 600;">IFSC:</span><span style="font-weight: 700; font-family: monospace; color: #1e3a5f;">FDRL0001754</span>
                        </div>
                    </div>

                    <div style="background: linear-gradient(135deg, #4c1d95 0%, #6d28d9 100%); border-radius: 10px; padding: 12px 16px; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 12px;">
                        <div style="font-size: 1.4rem;">📱</div>
                        <div>
                            <div style="font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.8px; color: rgba(255,255,255,0.7); font-weight: 600;">UPI ID</div>
                            <div style="font-size: 0.9rem; font-weight: 800; color: white; font-family: monospace; letter-spacing: 0.5px;">sandslab2023@fbi</div>
                        </div>
                    </div>

                    <!-- Revenue Splits -->
                    <h4 style="font-family: var(--font-heading); color: var(--primary-light); font-size: 0.92rem; margin-bottom: 0.8rem; padding-bottom: 6px; border-bottom: 2px solid #f59e0b; text-transform: uppercase; letter-spacing: 0.5px;">💰 Revenue Splits (Cuts)</h4>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; margin-bottom: 10px;">
                        <div>
                            <label for="verifier_pct" style="font-weight: 600; color: var(--primary-color); font-size: 0.78rem; display: block; margin-bottom: 4px;">Verifier %</label>
                            <input type="number" name="verifier_pct" id="verifier_pct" class="form-control" value="<?php echo $v_pct; ?>" min="0" max="100" step="1" required oninput="checkSumPercentage()" style="font-size: 0.88rem; padding: 6px 8px; text-align: center;">
                        </div>
                        <div>
                            <label for="admin_pct" style="font-weight: 600; color: var(--primary-color); font-size: 0.78rem; display: block; margin-bottom: 4px;">Admin %</label>
                            <input type="number" name="admin_pct" id="admin_pct" class="form-control" value="<?php echo $a_pct; ?>" min="0" max="100" step="1" required oninput="checkSumPercentage()" style="font-size: 0.88rem; padding: 6px 8px; text-align: center;">
                        </div>
                        <div>
                            <label for="portal_pct" style="font-weight: 600; color: var(--primary-color); font-size: 0.78rem; display: block; margin-bottom: 4px;">Portal %</label>
                            <input type="number" name="portal_pct" id="portal_pct" class="form-control" value="<?php echo $p_pct; ?>" min="0" max="100" step="1" required oninput="checkSumPercentage()" style="font-size: 0.88rem; padding: 6px 8px; text-align: center;">
                        </div>
                    </div>

                    <div style="background-color: #f8fafc; border: 1px solid var(--border-color); padding: 10px 14px; border-radius: 8px; font-size: 0.82rem; text-align: center;">
                        Total: <strong id="pctSumDisplay">100</strong>%
                        <span id="pctSumStatus" style="display: block; font-size: 0.7rem; margin-top: 3px; font-weight: 600; color: #16a34a;">✓ Sum equals 100%</span>
                    </div>
                </div>
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end; border-top: 1px solid var(--border-color); padding-top: 14px; margin-top: 0.5rem;">
                <button type="button" onclick="closeRulesModal()" class="btn btn-secondary" style="border: 1px solid var(--border-color); color: var(--primary-color); padding: 8px 16px;">Cancel</button>
                <button type="submit" id="settingsSubmitBtn" class="btn btn-dark" style="padding: 8px 20px; background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%); color: white; border: none;">💾 Save Financial Settings</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Editor-in-Chief Settings -->
<div id="editorModal" class="modal-overlay" style="display: none;">
    <div class="modal-content" style="max-width: 480px; width: 95%;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">
            <h3 style="font-family: var(--font-heading); color: var(--primary-color); font-size: 1.25rem;">Editor-in-Chief Settings</h3>
            <button onclick="closeEditorModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>
        
        <form action="wallets.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="update_editor_settings" value="1">
            
            <p style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 1.5rem;">
                Configure the name and upload the signature image for the Editor-in-Chief to be rendered on all official documents.
            </p>

            <div class="form-group" style="margin-bottom: 1.25rem;">
                <label for="editor_name" style="font-weight: 600; color: var(--primary-color); display: block; margin-bottom: 6px;">Editor-in-Chief Name</label>
                <input type="text" name="editor_name" id="editor_name" class="form-control" value="<?php echo sanitize(rjpes_get_setting('editor_name', 'Prof. (Dr.) Biju Lona K.')); ?>" required>
            </div>
            
            <div class="form-group" style="margin-bottom: 1.25rem;">
                <label for="editor_signature" style="font-weight: 600; color: var(--primary-color); display: block; margin-bottom: 6px;">Signature Image (JPEG/PNG)</label>
                <input type="file" name="editor_signature" id="editor_signature" class="form-control" accept="image/jpeg, image/png">
                <small style="color: var(--text-muted); font-size: 0.72rem; display: block; margin-top: 4px;">Upload a clean scan of the signature. The portal will automatically optimize and convert it.</small>
            </div>

            <?php 
            $curr_sig = rjpes_get_setting('editor_signature', '');
            if (!empty($curr_sig) && file_exists(__DIR__ . '/../' . $curr_sig)): 
            ?>
                <div style="background-color: #f8fafc; border: 1px solid var(--border-color); padding: 12px; border-radius: 6px; margin-bottom: 1.5rem;">
                    <span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 600; display: block; margin-bottom: 6px;">Current Signature Preview</span>
                    <img src="../<?php echo sanitize($curr_sig); ?>?t=<?php echo time(); ?>" alt="Editor Signature" style="max-height: 50px; background: white; border: 1px solid var(--border-color); padding: 4px; border-radius: 4px; display: block;">
                </div>
            <?php endif; ?>

            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" onclick="closeEditorModal()" class="btn btn-secondary" style="border: 1px solid var(--border-color); color: var(--primary-color); padding: 8px 16px;">Cancel</button>
                <button type="submit" class="btn btn-dark" style="padding: 8px 20px; background: linear-gradient(135deg, #0b2240 0%, #16365f 100%); color: white; border: none;">Save Details</button>
            </div>
        </form>
    </div>
</div>

<!-- Ledger Data Containers -->
<?php foreach ($wallets as $w): ?>
    <?php
    // Fetch chronologically to compute running balance correctly
    $stmt_tx = $pdo->prepare("SELECT id, amount, transaction_type, description, created_at, payment_type, transaction_date FROM wallet_transactions WHERE user_id = ? ORDER BY id ASC");
    $stmt_tx->execute([$w['id']]);
    $txs_asc = $stmt_tx->fetchAll();
    
    $running_bal = 0.00;
    $txs_with_bal = [];
    foreach ($txs_asc as $tx) {
        $running_bal += floatval($tx['amount']);
        $tx['running_balance'] = $running_bal;
        $txs_with_bal[] = $tx;
    }
    // Reverse to show newest first
    $txs_desc = array_reverse($txs_with_bal);
    ?>
    <div id="ledger-data-<?php echo $w['id']; ?>" style="display: none;">
        <div class="table-responsive">
            <table class="table" style="font-size: 0.8rem; margin: 0;">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Description</th>
                        <th style="text-align: right;">Credit (₹)</th>
                        <th style="text-align: right;">Debit (₹)</th>
                        <th style="text-align: right;">Balance (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($txs_desc)): ?>
                        <tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 2rem;">No transaction ledger records.</td></tr>
                    <?php else: ?>
                        <?php foreach ($txs_desc as $tx): ?>
                            <tr>
                                <td>
                                    <?php 
                                    if ($tx['transaction_type'] == 'debit' && !empty($tx['transaction_date'])) {
                                        echo date('d M Y', strtotime($tx['transaction_date']));
                                    } else {
                                        echo date('d M Y, h:i A', strtotime($tx['created_at']));
                                    }
                                    ?>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                        <span><?php echo sanitize($tx['description']); ?></span>
                                        <?php if (!empty($tx['payment_type'])): ?>
                                            <span style="font-size: 0.7rem; background: #e2e8f0; color: #475569; padding: 2px 6px; border-radius: 4px; display: inline-block; font-weight: 500;">
                                                💳 <?php echo sanitize($tx['payment_type']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td style="text-align: right; font-weight: 600; color: #16a34a;">
                                    <?php echo ($tx['transaction_type'] == 'credit') ? '₹' . number_format(abs($tx['amount']), 2) : '—'; ?>
                                </td>
                                <td style="text-align: right; font-weight: 600; color: #dc2626;">
                                    <?php echo ($tx['transaction_type'] == 'debit') ? '₹' . number_format(abs($tx['amount']), 2) : '—'; ?>
                                </td>
                                <td style="text-align: right; font-weight: 600; color: var(--text-color);">
                                    ₹<?php echo number_format($tx['running_balance'], 2); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endforeach; ?>

<!-- Portal Ledger Data -->
<?php
$stmt_portal_tx = $pdo->query("SELECT id, amount, transaction_type, description, created_at, payment_type, transaction_date FROM wallet_transactions WHERE user_id IS NULL ORDER BY id ASC");
$portal_txs_asc = $stmt_portal_tx->fetchAll();

$running_bal_portal = 0.00;
$portal_txs_with_bal = [];
foreach ($portal_txs_asc as $tx) {
    $running_bal_portal += floatval($tx['amount']);
    $tx['running_balance'] = $running_bal_portal;
    $portal_txs_with_bal[] = $tx;
}
$portal_txs_desc = array_reverse($portal_txs_with_bal);
?>
<div id="ledger-data-portal" style="display: none;">
    <div class="table-responsive">
        <table class="table" style="font-size: 0.8rem; margin: 0;">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Description</th>
                    <th style="text-align: right;">Credit (₹)</th>
                    <th style="text-align: right;">Debit (₹)</th>
                    <th style="text-align: right;">Balance (₹)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($portal_txs_desc)): ?>
                    <tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 2rem;">No transactions recorded for the portal yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($portal_txs_desc as $tx): ?>
                        <tr>
                            <td>
                                <?php 
                                if ($tx['transaction_type'] == 'debit' && !empty($tx['transaction_date'])) {
                                    echo date('d M Y', strtotime($tx['transaction_date']));
                                } else {
                                    echo date('d M Y, h:i A', strtotime($tx['created_at']));
                                }
                                ?>
                            </td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                    <span><?php echo sanitize($tx['description']); ?></span>
                                    <?php if (!empty($tx['payment_type'])): ?>
                                        <span style="font-size: 0.7rem; background: #e2e8f0; color: #475569; padding: 2px 6px; border-radius: 4px; display: inline-block; font-weight: 500;">
                                            💳 <?php echo sanitize($tx['payment_type']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td style="text-align: right; font-weight: 600; color: #16a34a;">
                                <?php echo ($tx['transaction_type'] == 'credit') ? '₹' . number_format(abs($tx['amount']), 2) : '—'; ?>
                            </td>
                            <td style="text-align: right; font-weight: 600; color: #dc2626;">
                                <?php echo ($tx['transaction_type'] == 'debit') ? '₹' . number_format(abs($tx['amount']), 2) : '—'; ?>
                            </td>
                            <td style="text-align: right; font-weight: 600; color: var(--text-color);">
                                ₹<?php echo number_format($tx['running_balance'], 2); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function escapeHtml(text) {
    if (!text) return '';
    return text.toString()
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function openLedgerModal(id, title, balance, isPortal, fullname) {
    window.currentLedgerId = id;
    document.getElementById('ledgerModalTitle').textContent = title;
    
    // Build premium balance display header with settlement option inside ledger
    var headerHtml = '';
    headerHtml += '<div style="background: linear-gradient(135deg, #f8fafc 0%, #edf2f7 100%); border: 1px solid var(--border-color); border-radius: 12px; padding: 1.25rem; margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; box-shadow: var(--shadow-sm);">';
    headerHtml += '  <div>';
    headerHtml += '    <span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 600; display: block; letter-spacing: 0.5px;">Current Wallet Balance</span>';
    headerHtml += '    <span style="font-size: 1.6rem; font-weight: 800; color: var(--primary-color); display: block; margin-top: 4px;">₹' + parseFloat(balance).toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '</span>';
    headerHtml += '  </div>';
    
    headerHtml += '  <div style="display: flex; gap: 8px;">';
    headerHtml += '    <button onclick="exportLedgerToExcel()" class="btn btn-secondary" style="border: 1px solid var(--border-color); color: var(--primary-color); padding: 8px 16px; font-size: 0.8rem; font-weight: 600; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">';
    headerHtml += '      📥 Export to Excel';
    headerHtml += '    </button>';
    if (parseFloat(balance) > 0) {
        var payoutId = isPortal ? "'portal'" : id;
        headerHtml += '    <button onclick="closeLedgerModal(); openPayoutModal(' + payoutId + ', \'' + escapeHtml(fullname) + '\', ' + balance + ')" class="btn btn-primary" style="background-color: var(--success-color); color: white; padding: 8px 16px; font-size: 0.8rem; font-weight: 600; border: none; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 2px 5px rgba(46, 204, 113, 0.3); transition: all 0.2s;">';
        headerHtml += '      Record Payout';
        headerHtml += '    </button>';
    }
    headerHtml += '  </div>';
    headerHtml += '</div>';

    var dataEl = document.getElementById('ledger-data-' + id);
    var contentHtml = dataEl ? dataEl.innerHTML : '<p style="text-align: center; color: var(--text-muted); padding: 2rem;">No transaction ledger records.</p>';
    
    document.getElementById('ledgerModalContent').innerHTML = headerHtml + contentHtml;
    document.getElementById('ledgerModal').style.display = 'flex';
    
    // Dynamically initialize DataTable with vertical scroll
    var $table = $('#ledgerModalContent table');
    if ($table.length > 0) {
        if ($.fn.DataTable.isDataTable($table)) {
            $table.DataTable().destroy();
        }
        $table.DataTable({
            pageLength: 5,
            lengthMenu: [5, 10, 25, 50],
            order: [], // keep default newest first
            language: {
                searchPlaceholder: "Search ledger...",
                search: ""
            },
            scrollY: '320px',
            scrollCollapse: true
        });
    }
}

function exportLedgerToExcel() {
    if (!window.currentLedgerId) {
        alert('No ledger context found.');
        return;
    }
    var originalDiv = document.getElementById('ledger-data-' + window.currentLedgerId);
    var table = originalDiv ? originalDiv.querySelector('table') : null;
    if (!table) {
        alert('No ledger data available to export.');
        return;
    }
    
    var rows = table.querySelectorAll('tr');
    var csvContent = [];
    
    for (var i = 0; i < rows.length; i++) {
        var row = [];
        var cols = rows[i].querySelectorAll('th, td');
        
        for (var j = 0; j < cols.length; j++) {
            // Clean up whitespace/newlines and format text properly
            var text = cols[j].textContent.replace(/(\r\n|\n|\r)/gm, '').trim();
            text = text.replace(/\s+/g, ' ');
            // Escape double quotes
            text = text.replace(/"/g, '""');
            row.push('"' + text + '"');
        }
        csvContent.push(row.join(','));
    }
    
    var csvString = csvContent.join('\n');
    
    // Excel needs BOM for UTF-8 compatibility
    var blob = new Blob(['\ufeff' + csvString], { type: 'text/csv;charset=utf-8;' });
    var filename = document.getElementById('ledgerModalTitle').textContent.trim().replace(/\s+/g, '_') + '.csv';
    
    if (navigator.msSaveBlob) {
        navigator.msSaveBlob(blob, filename);
    } else {
        var link = document.createElement("a");
        if (link.download !== undefined) {
            var url = URL.createObjectURL(blob);
            link.setAttribute("href", url);
            link.setAttribute("download", filename);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    }
}

function closeLedgerModal() {
    document.getElementById('ledgerModal').style.display = 'none';
}

function openPayoutModal(userId, userName, currentBalance) {
    document.getElementById('payoutUserId').value = userId;
    document.getElementById('payoutUserName').textContent = userName;
    document.getElementById('payoutMaxSpan').textContent = currentBalance.toFixed(2);
    document.getElementById('payout_amount').max = currentBalance;
    
    // Set default date to today's date (local timezone format YYYY-MM-DD)
    var today = new Date();
    var offset = today.getTimezoneOffset();
    today = new Date(today.getTime() - (offset*60*1000));
    document.getElementById('payout_date').value = today.toISOString().split('T')[0];
    
    document.getElementById('payoutModal').style.display = 'flex';
}

function closePayoutModal() {
    document.getElementById('payoutModal').style.display = 'none';
    document.getElementById('payout_amount').value = '';
    document.getElementById('payout_ref').value = '';
    document.getElementById('payout_payment_type').value = '';
    document.getElementById('payout_date').value = '';
}

function openEditionModal() {
    updateEditionPreview();
    document.getElementById('editionModal').style.display = 'flex';
}

function closeEditionModal() {
    document.getElementById('editionModal').style.display = 'none';
}

function updateEditionPreview() {
    var vol     = document.getElementById('edition_volume').value || '?';
    var iss     = document.getElementById('edition_issue').value  || '?';
    var dateVal = document.getElementById('edition_date').value;
    var monthYear = '';
    if (dateVal) {
        var d = new Date(dateVal + 'T00:00:00');
        monthYear = d.toLocaleDateString('en-US', { month: 'long', year: 'numeric' }).toUpperCase();
    }
    document.getElementById('editionPreviewText').textContent =
        'VOLUME ' + vol + ' \u2022 ISSUE ' + iss + (monthYear ? ' \u2022 ' + monthYear : '');
}

function selectGstMode(mode) {
    var excLabel = document.getElementById('gst_exclude_label');
    var incLabel = document.getElementById('gst_include_label');
    if (!excLabel || !incLabel) return;
    if (mode === 'exclude') {
        excLabel.style.background = '#eff6ff';
        excLabel.style.borderColor = '#2563eb';
        incLabel.style.background = '#f8fafc';
        incLabel.style.borderColor = '#e2e8f0';
    } else {
        incLabel.style.background = '#eff6ff';
        incLabel.style.borderColor = '#2563eb';
        excLabel.style.background = '#f8fafc';
        excLabel.style.borderColor = '#e2e8f0';
    }
}

function openRulesModal() {
    document.getElementById('rulesModal').style.display = 'flex';
    checkSumPercentage();
}

function closeRulesModal() {
    document.getElementById('rulesModal').style.display = 'none';
}

// Global Setting Percentages Check
function checkSumPercentage() {
    var v = parseInt(document.getElementById('verifier_pct').value) || 0;
    var a = parseInt(document.getElementById('admin_pct').value) || 0;
    var p = parseInt(document.getElementById('portal_pct').value) || 0;
    var total = v + a + p;
    
    document.getElementById('pctSumDisplay').textContent = total;
    var statusDisplay = document.getElementById('pctSumStatus');
    var submitBtn = document.getElementById('settingsSubmitBtn');
    
    if (total === 100) {
        statusDisplay.textContent = "✓ Sum equals 100%";
        statusDisplay.style.color = "#16a34a";
        submitBtn.disabled = false;
        submitBtn.style.opacity = "1";
    } else {
        statusDisplay.textContent = "✗ Sum must equal exactly 100%";
        statusDisplay.style.color = "#dc2626";
        submitBtn.disabled = true;
        submitBtn.style.opacity = "0.6";
    }
}

function validateSettingsSum() {
    var v = parseInt(document.getElementById('verifier_pct').value) || 0;
    var a = parseInt(document.getElementById('admin_pct').value) || 0;
    var p = parseInt(document.getElementById('portal_pct').value) || 0;
    return (v + a + p === 100);
}

function openEditorModal() {
    document.getElementById('editorModal').style.display = 'flex';
}
function closeEditorModal() {
    document.getElementById('editorModal').style.display = 'none';
}

// Backdrop modal click close
document.getElementById('ledgerModal').addEventListener('click', function(e) {
    if (e.target === this) closeLedgerModal();
});
document.getElementById('payoutModal').addEventListener('click', function(e) {
    if (e.target === this) closePayoutModal();
});
document.getElementById('rulesModal').addEventListener('click', function(e) {
    if (e.target === this) closeRulesModal();
});
document.getElementById('editionModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditionModal();
});
document.getElementById('editorModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditorModal();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
